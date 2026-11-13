<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use App\Exceptions\SlotTakenException;
use App\Libraries\ApiSite;
use App\Modules\Orders\Services\OrderQueries;
use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\RateLimitException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Services\ClinicSchedule;

/**
 * Doctors of a clinic site and online appointments (module "clinic", needs "bookings"; else 403 module_disabled).
 *
 *   GET  /v1/doctors                 doctors shown on the website (with their weekly sessions)
 *   GET  /v1/doctors/{id}            one doctor
 *   GET  /v1/doctors/{id}/slots      slots on a date (free ones only unless ?all=1)
 *   GET  /v1/doctors/{id}/photo      the doctor's photo {mime, base64}
 *   POST /v1/bookings + doctor_id    book a slot: re-checked server-side under a row lock (409 slot_taken)
 *
 * Abuse limits on top of the key's rate limit: per patient phone 5 online appointments an hour per
 * clinic, per key 30 appointment requests a minute.
 */
final class ClinicDirectory
{
    public const PER_PHONE_HOUR = 5;
    public const PER_KEY_MINUTE = 30;

    public function __construct(private readonly ApiSite $site, private readonly ApiCaller $caller, private readonly ClinicSchedule $schedule)
    {
    }

    public static function enabledFor(array $business): bool
    {
        return service('moduleAccess')->forTenant((int) $business['tenant_id'])->has('clinic')
            && (($business['business_type'] ?? '') === 'clinic' || ! empty($business['settings']['features']['clinic']));
    }

    public function assertEnabled(): void
    {
        if (! self::enabledFor($this->site->business())) {
            throw new ModuleDisabledException('Online doctor appointments are not enabled for this site.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function doctors(): array
    {
        $this->assertEnabled();
        $rows = $this->schedule->doctors(true);
        $sessions = $this->schedule->sessionsFor(array_map(static fn (array $d): int => (int) $d['id'], $rows));

        return array_map(fn (array $d): array => $this->present($d, $sessions[(int) $d['id']] ?? []), $rows);
    }

    /** @return array<string, mixed> */
    public function doctor(int $id): array
    {
        $this->assertEnabled();
        $d = $this->schedule->doctor($id, true) ?? throw new NotFoundException('Doctor not found.');
        $out = $this->present($d, $this->schedule->sessionsFor([$id])[$id] ?? []);
        $out['open_dates'] = $this->schedule->openDates($id, 14);

        return $out;
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function slots(int $id, string $date, bool $all): array
    {
        $this->assertEnabled();
        $this->schedule->doctor($id, true) ?? throw new NotFoundException('Doctor not found.');
        if (! ClinicSchedule::validDate($date)) {
            throw new ValidationException('Invalid date.', ['date' => 'Use YYYY-MM-DD.']);
        }
        $r = $this->schedule->slots($id, $date);
        $list = array_values(array_filter($r['slots'], static fn (array $s): bool => $all || $s['free']));

        return [
            'data' => array_map(static fn (array $s): array => ['time' => $s['time'], 'starts_at' => OrderQueries::iso($s['starts_at']), 'ends_at' => OrderQueries::iso($s['ends_at']),
                'available' => $s['free'], 'reason' => $s['reason']], $list),
            'meta' => ['date' => $date, 'doctor_id' => $id, 'on_leave' => $r['on_leave'], 'free' => count(array_filter($r['slots'], static fn (array $s): bool => $s['free']))],
        ];
    }

    /** @return array{mime: string, base64: string, updated_at: ?string} */
    public function photo(int $id): array
    {
        $this->assertEnabled();
        $d = $this->schedule->doctor($id, true);
        if ($d === null || ! $d['photo_file_id']) {
            throw new NotFoundException('No photo.');
        }
        $f = db_connect()->table('clinic_files')->select('mime, data, created_at')->where('id', (int) $d['photo_file_id'])->where('business_id', (int) $this->site->business()['id'])
            ->get()->getRowArray() ?? throw new NotFoundException('No photo.');

        return ['mime' => (string) $f['mime'], 'base64' => base64_encode((string) $f['data']), 'updated_at' => OrderQueries::iso($f['created_at'])];
    }

    /**
     * POST /v1/bookings with a doctor.
     *
     * @param array<string, mixed> $in {doctor_id, starts_at, customer|patient: {name, phone, email?, sex?, age?}, note?, channel?}
     * @return array<string, mixed>
     */
    public function book(array $in): array
    {
        $this->assertEnabled();
        $errors = [];
        if (! isset($in['doctor_id']) || ! ctype_digit((string) $in['doctor_id'])) {
            $errors['doctor_id'] = 'Use a doctor id.';
        }
        $rawStart = trim((string) ($in['starts_at'] ?? ''));
        if ($rawStart === '' || strtotime($rawStart) === false) {
            $errors['starts_at'] = 'Pick one of the doctor\'s free times (GET /v1/doctors/{id}/slots).';
        }
        $p = is_array($in['patient'] ?? null) ? $in['patient'] : (is_array($in['customer'] ?? null) ? $in['customer'] : []);
        $p = array_intersect_key($p, array_flip(['name', 'phone', 'email', 'sex', 'age', 'dob']));
        foreach (ClinicSchedule::patientErrors($p) as $k => $v) {
            $errors[$k] = $v;
        }
        if (isset($in['note']) && mb_strlen((string) $in['note']) > 500) {
            $errors['note'] = 'At most 500 characters.';
        }
        $channel = (string) ($in['channel'] ?? '');
        if ($channel !== '' && ! in_array($channel, ['storefront', 'marketplace', 'api'], true)) {
            $errors['channel'] = 'Use storefront, marketplace or api.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the appointment details.', $errors);
        }
        $this->throttle((string) $p['phone']);

        $isStorefront = (bool) ($this->caller->key()['is_storefront'] ?? false);
        $source = $channel === 'marketplace' ? 'marketplace' : ($channel === 'storefront' || ($channel === '' && $isStorefront) ? 'web' : 'api');
        try {
            $row = $this->schedule->book([
                'doctor_id' => (int) $in['doctor_id'], 'starts_at' => date('Y-m-d H:i:s', (int) strtotime($rawStart)), 'patient' => $p, 'online' => true,
                'source' => $source, 'note' => isset($in['note']) ? (string) $in['note'] : null,
            ]);
        } catch (ConflictException $e) {
            throw new SlotTakenException($e->getMessage(), [], $e);
        }

        return [
            'reference' => (string) $row['reference'], 'status' => (string) $row['status'], 'kind' => 'appointment',
            'doctor' => ['id' => (int) $row['doctor_id'], 'name' => (string) $row['doctor_name']], 'staff' => (string) $row['doctor_name'],
            'serial_no' => (int) $row['serial_no'], 'starts_at' => OrderQueries::iso($row['starts_at']), 'ends_at' => OrderQueries::iso($row['ends_at']),
        ];
    }

    private function throttle(string $phone): void
    {
        $t = service('throttler');
        $bid = (int) $this->site->business()['id'];
        $phone = (string) preg_replace('/[^0-9]/', '', $phone);
        if ($t->check('omnipos_clinic_k' . (int) $this->caller->keyId(), self::PER_KEY_MINUTE, MINUTE) === false
            || $t->check('omnipos_clinic_p' . $bid . '_' . substr(hash('sha256', $phone), 0, 24), self::PER_PHONE_HOUR, HOUR) === false) {
            service('response')->setHeader('Retry-After', (string) max(1, $t->getTokenTime()));

            throw new RateLimitException('Too many appointment requests. Please try again later or call the clinic.');
        }
    }

    /** @param list<array<string, mixed>> $sessions */
    private function present(array $d, array $sessions): array
    {
        return [
            'id' => (int) $d['id'], 'name' => (string) $d['name'], 'degrees' => $d['degrees'], 'specialty' => $d['specialty'], 'registration_no' => $d['reg_no'],
            'designation' => $d['designation'], 'chamber' => $d['chamber'], 'fee' => $d['fee'] !== null ? (float) $d['fee'] : null, 'about' => $d['bio'],
            'photo' => $d['photo_file_id'] ? ['version' => (int) $d['photo_file_id']] : null,
            'schedule' => array_map(static fn (array $s): array => ['weekday' => (int) $s['weekday'], 'day' => ClinicSchedule::WEEKDAYS[(int) $s['weekday']],
                'from' => substr((string) $s['starts'], 0, 5), 'to' => substr((string) $s['ends'], 0, 5), 'slot_minutes' => (int) $s['slot_minutes'],
                'max_patients' => $s['max_patients'] !== null ? (int) $s['max_patients'] : null, 'chamber' => $s['chamber']], $sessions),
        ];
    }
}
