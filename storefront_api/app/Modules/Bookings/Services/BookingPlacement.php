<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use App\Exceptions\SlotTakenException;
use App\Libraries\ApiSite;
use App\Modules\Orders\Services\OrderQueries;
use App\Modules\Storefront\Services\StorefrontService;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Services\BookingService;

/**
 * Online bookings through the API (needs the "bookings" module, else 403 module_disabled).
 *
 * Slots come from the core BookingService (opening hours, staff, existing bookings). For
 * appointments, rooms and tables the requested time must still be a free slot (409 slot_taken);
 * "Any" staff is resolved to the first free member so the core clash check (row lock) applies.
 */
final class BookingPlacement
{
    private const SLOTTED = ['appointment', 'room', 'table'];

    public function __construct(
        private readonly ApiSite $site,
        private readonly ApiCaller $caller,
        private readonly BookingService $bookings,
    ) {
    }

    public function assertEnabled(): void
    {
        $b = $this->site->business();
        if (($b['booking_kind'] ?? null) === null || ! service('moduleAccess')->forTenant((int) $b['tenant_id'])->has('bookings')) {
            throw new ModuleDisabledException('Online bookings are not enabled for this shop.');
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function slots(string $date, ?int $productId, ?string $staff): array
    {
        $this->assertEnabled();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new ValidationException('Invalid date.', ['date' => 'Use YYYY-MM-DD.']);
        }
        $duration = 30;
        if ($productId !== null) {
            $duration = $this->duration($productId);
        }
        $slots = $this->bookings->slots($date, $productId, $staff !== '' ? $staff : null);

        return [
            'data' => array_map(static fn (array $s): array => ['time' => $s['time'], 'starts_at' => OrderQueries::iso($s['starts_at']), 'staff' => $s['staff']], $slots),
            'meta' => ['date' => $date, 'staff' => StorefrontService::staff($this->site->business()['settings']), 'duration_min' => $duration],
        ];
    }

    /**
     * @param array<string, mixed> $in {kind, customer: {name, phone, email}, product_id, staff, starts_at, party_size, details, note, channel}
     * @return array<string, mixed>
     */
    public function create(array $in): array
    {
        $this->assertEnabled();
        $biz = $this->site->business();
        // clinic sites: an appointment with a doctor (doctor's slots, serial number, patient profile)
        if (isset($in['doctor_id']) || (($biz['business_type'] ?? '') === 'clinic' && \App\Modules\Clinic\Services\ClinicDirectory::enabledFor($biz))) {
            return (new \App\Modules\Clinic\Services\ClinicDirectory($this->site, $this->caller, service('clinicSchedule')))->book($in);
        }
        $kind = (string) ($in['kind'] ?? ($biz['booking_kind'] ?: 'appointment'));
        $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
        $errors = [];
        $productId = null;
        if (isset($in['product_id']) && $in['product_id'] !== '' && $in['product_id'] !== null) {
            if (! ctype_digit((string) $in['product_id'])) {
                $errors['product_id'] = 'Invalid service id.';
            } else {
                $productId = (int) $in['product_id'];
            }
        }
        $startsAt = null;
        $rawStart = trim((string) ($in['starts_at'] ?? ''));
        if ($rawStart !== '') {
            $ts = strtotime($rawStart);
            if ($ts === false) {
                $errors['starts_at'] = 'Invalid date and time.';
            } else {
                $startsAt = date('Y-m-d H:i:s', $ts);
            }
        }
        if (isset($in['details']) && ! is_array($in['details'])) {
            $errors['details'] = 'details must be an object.';
        }
        $channel = (string) ($in['channel'] ?? '');
        if ($channel !== '' && ! in_array($channel, ['storefront', 'marketplace', 'api'], true)) {
            $errors['channel'] = 'Use storefront, marketplace or api.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the booking details.', $errors);
        }
        $staff = trim((string) ($in['staff'] ?? ''));
        $staff = $staff === '' || strcasecmp($staff, 'any') === 0 ? null : $staff;

        // the time must still be free (same rules as GET /bookings/slots)
        if ($startsAt !== null && in_array($kind, self::SLOTTED, true) && strtotime($startsAt) > time()) {
            $slot = null;
            foreach ($this->bookings->slots(substr($startsAt, 0, 10), $productId, $staff) as $s) {
                if ($s['starts_at'] === $startsAt) {
                    $slot = $s;
                    break;
                }
            }
            if ($slot === null) {
                throw new SlotTakenException(($staff !== null ? $staff . ' is' : 'That time is') . ' not available at ' . date('H:i', (int) strtotime($startsAt)) . '. Please pick another slot.');
            }
            if ($staff === null && $slot['staff'] !== ['Any']) {
                $staff = (string) $slot['staff'][0];
            }
        }

        $isStorefront = (bool) ($this->caller->key()['is_storefront'] ?? false);
        $source = $channel === 'marketplace' ? 'marketplace' : ($channel === 'storefront' || ($channel === '' && $isStorefront) ? 'web' : 'api');
        try {
            $row = Tx::run(fn (): array => $this->bookings->create([
                'kind' => $kind,
                'customer_name' => (string) ($c['name'] ?? ''),
                'customer_phone' => (string) ($c['phone'] ?? ''),
                'customer_email' => (string) ($c['email'] ?? ''),
                'product_id' => $productId,
                'staff_name' => $staff,
                'starts_at' => $startsAt,
                'party_size' => isset($in['party_size']) && is_numeric($in['party_size']) ? (int) $in['party_size'] : null,
                'details' => $in['details'] ?? null,
                'note' => isset($in['note']) ? (string) $in['note'] : null,
                'source' => $source,
            ]));
        } catch (ValidationException $e) {
            $map = ['customer_name' => 'customer.name', 'customer_phone' => 'customer.phone', 'customer_email' => 'customer.email'];
            $details = [];
            foreach ($e->details() as $k => $v) {
                $details[$map[$k] ?? $k] = $v;
            }

            throw new ValidationException($e->getMessage(), $details, $e);
        } catch (BusinessRuleException $e) {
            if (str_contains($e->getMessage(), 'already booked')) {
                throw new SlotTakenException($e->getMessage(), [], $e);
            }

            throw $e;
        }

        return [
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'kind' => (string) $row['kind'],
            'staff' => $row['staff_name'],
            'starts_at' => OrderQueries::iso($row['starts_at']),
            'ends_at' => OrderQueries::iso($row['ends_at']),
        ];
    }

    private function duration(int $productId): int
    {
        $d = model(\OmniPOS\Core\Models\Generated\ProductsModel::class)->scoped('p')->select('p.duration_min')
            ->where('p.id', $productId)->where('p.deleted_at', null)->get()->getRow('duration_min');

        return (int) ($d ?: 30);
    }
}
