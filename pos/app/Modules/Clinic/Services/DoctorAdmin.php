<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ClinicSchedule;

/**
 * Doctor profiles of the outlet, their weekly sessions and leave dates (Clinic › Doctors).
 * Degrees, specialty and the registration number are stored exactly as typed — nothing is looked up or generated.
 */
final class DoctorAdmin
{
    private function bid(): int
    {
        return service('posSession')->businessId();
    }

    /** @return list<array<string, mixed>> every doctor (active first), with sessions per doctor */
    public function all(): array
    {
        $rows = db_connect()->table('clinic_doctors')
            ->select('id, user_id, name, degrees, specialty, reg_no, designation, chamber, phone, fee, bio, photo_file_id, signature_file_id, is_active, is_public, sort_order')
            ->where('business_id', $this->bid())->where('deleted_at', null)->orderBy('is_active', 'desc')->orderBy('sort_order')->orderBy('name')->get()->getResultArray();
        $sessions = service('clinicSchedule')->sessionsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['sessions'] = $sessions[(int) $r['id']] ?? [];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $d = db_connect()->table('clinic_doctors')->where('id', $id)->where('business_id', $this->bid())->where('deleted_at', null)->get()->getRowArray()
            ?? throw new NotFoundException('Doctor not found.');
        $d['sessions'] = db_connect()->table('clinic_schedules')->where('business_id', $this->bid())->where('doctor_id', $id)->orderBy('weekday')->orderBy('starts')->get()->getResultArray();
        $d['leaves'] = db_connect()->table('clinic_leaves')->where('business_id', $this->bid())->where('doctor_id', $id)->where('to_date >=', date('Y-m-d', strtotime('-30 days')))
            ->orderBy('from_date')->get()->getResultArray();

        return $d;
    }

    /** @return list<array{id: int, name: string}> staff of this outlet a doctor profile can be linked to */
    public function users(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], db_connect()->table('users u')->select('u.id, u.name')
            ->join('user_businesses ub', 'ub.user_id = u.id')->where('ub.business_id', $this->bid())->where('u.is_active', 1)->orderBy('u.name')->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $in name, degrees, specialty, reg_no, designation, chamber, phone, fee, bio, user_id, is_active, is_public, sort_order
     * @param array<string, ?UploadedFile> $files photo, signature
     */
    public function save(?int $id, array $in, array $files = []): int
    {
        $s = static fn (string $k, int $max): ?string => trim((string) ($in[$k] ?? '')) !== '' ? mb_substr(trim((string) $in[$k]), 0, $max) : null;
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter the doctor\'s name (up to 120 characters).';
        }
        $fee = trim((string) ($in['fee'] ?? ''));
        if ($fee !== '' && (! is_numeric($fee) || (float) $fee < 0 || (float) $fee > 10000000)) {
            $errors['fee'] = 'Enter the fee as a number, or leave it empty.';
        }
        if (($in['phone'] ?? '') !== '' && ! preg_match('/^\+?[0-9][0-9\- ]{6,18}$/', (string) $in['phone'])) {
            $errors['phone'] = 'Enter a valid phone number.';
        }
        $userId = (int) ($in['user_id'] ?? 0);
        if ($userId > 0 && ! in_array($userId, array_column($this->users(), 'id'), true)) {
            $errors['user_id'] = 'Choose a staff member of this outlet.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the doctor\'s profile.', $errors);
        }
        $row = [
            'name' => mb_substr($name, 0, 120), 'degrees' => $s('degrees', 255), 'specialty' => $s('specialty', 160), 'reg_no' => $s('reg_no', 60),
            'designation' => $s('designation', 200), 'chamber' => $s('chamber', 255), 'phone' => $s('phone', 32), 'fee' => $fee === '' ? null : round((float) $fee, 2),
            'bio' => $s('bio', 3000), 'user_id' => $userId ?: null, 'is_active' => ! empty($in['is_active']) ? 1 : 0, 'is_public' => ! empty($in['is_public']) ? 1 : 0,
            'sort_order' => max(0, min(999, (int) ($in['sort_order'] ?? 0))), 'updated_at' => date('Y-m-d H:i:s'),
        ];
        $photo = ClinicFiles::store($files['photo'] ?? null, 'photo', 'photo');
        $sign = ClinicFiles::store($files['signature'] ?? null, 'signature', 'signature');
        $db = db_connect();
        $old = $id !== null ? $this->find($id) : null;
        if ($photo !== null) {
            $row['photo_file_id'] = $photo;
        } elseif (! empty($in['remove_photo'])) {
            $row['photo_file_id'] = null;
        }
        if ($sign !== null) {
            $row['signature_file_id'] = $sign;
        } elseif (! empty($in['remove_signature'])) {
            $row['signature_file_id'] = null;
        }
        if ($old === null) {
            $s2 = service('posSession');
            $db->table('clinic_doctors')->insert($row + ['tenant_id' => $s2->tenantId(), 'business_id' => $this->bid(), 'created_at' => date('Y-m-d H:i:s')]);
            $id = (int) $db->insertID();
            service('audit')->activity('Doctor added', $name);

            return $id;
        }
        $db->table('clinic_doctors')->where('id', $id)->where('business_id', $this->bid())->update($row);
        foreach (['photo_file_id', 'signature_file_id'] as $col) {
            if (array_key_exists($col, $row) && (int) ($old[$col] ?? 0) !== (int) ($row[$col] ?? 0)) {
                ClinicFiles::delete($old[$col] !== null ? (int) $old[$col] : null);
            }
        }
        service('audit')->activity('Doctor profile changed', $name);

        return (int) $id;
    }

    /** Remove from the list (kept for past appointments and prescriptions). */
    public function delete(int $id): string
    {
        $d = $this->find($id);
        db_connect()->table('clinic_doctors')->where('id', $id)->where('business_id', $this->bid())->update(['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => 0, 'is_public' => 0]);
        service('audit')->activity('Doctor removed', (string) $d['name']);

        return (string) $d['name'];
    }

    /**
     * Replace the doctor's weekly sessions.
     *
     * @param list<array<string, mixed>> $rows weekday, starts (HH:MM), ends, slot_minutes, max_patients, chamber
     */
    public function saveSchedule(int $doctorId, array $rows): int
    {
        $d = $this->find($doctorId);
        $clean = [];
        $errors = [];
        foreach (array_values($rows) as $i => $r) {
            if (! is_array($r) || (trim((string) ($r['starts'] ?? '')) === '' && trim((string) ($r['ends'] ?? '')) === '')) {
                continue;
            }
            $wd = (int) ($r['weekday'] ?? -1);
            $st = (string) ($r['starts'] ?? '');
            $en = (string) ($r['ends'] ?? '');
            $len = (int) ($r['slot_minutes'] ?? 0);
            $max = trim((string) ($r['max_patients'] ?? ''));
            if ($wd < 0 || $wd > 6) {
                $errors["sessions.$i.weekday"] = 'Choose a day.';
            }
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $st) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $en) || $en <= $st) {
                $errors["sessions.$i.time"] = 'Each session needs a start and a later end time.';
            }
            if ($len < 5 || $len > 240) {
                $errors["sessions.$i.slot_minutes"] = 'Slot length: 5 to 240 minutes.';
            }
            if ($max !== '' && (! ctype_digit($max) || (int) $max < 1 || (int) $max > 500)) {
                $errors["sessions.$i.max_patients"] = 'Max patients: 1 to 500, or empty.';
            }
            $clean[] = ['weekday' => $wd, 'starts' => $st . ':00', 'ends' => $en . ':00', 'slot_minutes' => $len, 'max_patients' => $max === '' ? null : (int) $max,
                'chamber' => trim((string) ($r['chamber'] ?? '')) !== '' ? mb_substr(trim((string) $r['chamber']), 0, 255) : null];
        }
        // sessions of one day must not overlap
        foreach ($clean as $i => $a) {
            foreach ($clean as $j => $b) {
                if ($i < $j && $a['weekday'] === $b['weekday'] && $a['starts'] < $b['ends'] && $b['starts'] < $a['ends']) {
                    $errors["sessions.$j.time"] = 'Two sessions on ' . ClinicSchedule::WEEKDAYS[$a['weekday']] . ' overlap.';
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the schedule.', $errors);
        }
        $s = service('posSession');
        Tx::run(function ($db) use ($doctorId, $clean, $s): void {
            $db->table('clinic_schedules')->where('business_id', $this->bid())->where('doctor_id', $doctorId)->delete();
            foreach ($clean as $r) {
                $db->table('clinic_schedules')->insert($r + ['tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'doctor_id' => $doctorId, 'is_active' => 1]);
            }
        });
        service('audit')->activity('Doctor schedule changed', $d['name'] . ' · ' . count($clean) . ' sessions a week');

        return count($clean);
    }

    public function addLeave(int $doctorId, array $in): void
    {
        $d = $this->find($doctorId);
        $from = (string) ($in['from_date'] ?? '');
        $to = (string) ($in['to_date'] ?? '') ?: $from;
        if (! ClinicSchedule::validDate($from) || ! ClinicSchedule::validDate($to) || $to < $from) {
            throw new ValidationException('Please check the leave dates.', ['from_date' => 'Pick a start date and an end date on or after it.']);
        }
        $s = service('posSession');
        db_connect()->table('clinic_leaves')->insert(['tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'doctor_id' => $doctorId, 'from_date' => $from, 'to_date' => $to,
            'note' => trim((string) ($in['note'] ?? '')) !== '' ? mb_substr(trim((string) $in['note']), 0, 200) : null, 'created_at' => date('Y-m-d H:i:s')]);
        service('audit')->activity('Doctor leave added', $d['name'] . ' · ' . $from . ($to !== $from ? ' to ' . $to : ''));
    }

    public function deleteLeave(int $doctorId, int $leaveId): void
    {
        $this->find($doctorId);
        db_connect()->table('clinic_leaves')->where('id', $leaveId)->where('doctor_id', $doctorId)->where('business_id', $this->bid())->delete();
    }

    /** @return list<int> doctor ids linked to the signed-in user */
    public static function mine(): array
    {
        $s = service('posSession');

        return array_map('intval', array_column(db_connect()->table('clinic_doctors')->select('id')->where('business_id', $s->businessId())
            ->where('user_id', $s->userId())->where('deleted_at', null)->get()->getResultArray(), 'id'));
    }
}
