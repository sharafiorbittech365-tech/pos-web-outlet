<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Front desk of the clinic: the day's queue per doctor (serial numbers), counter / phone
 * appointments and the visit workflow:
 *
 *   pending ─ confirm ─▶ confirmed ─ arrived ─▶ (arrived) ─ in_consult ─▶ (in consultation) ─ done ─▶ done
 *        └──────────────── cancelled / no_show (from any open stage) ───────────────────────┘
 *
 * Status lives in bookings.status (pending | confirmed | done | cancelled — the POS Bookings page and
 * its badge read the same rows); the in-between stages are bookings.visit_stage.
 */
final class AppointmentDesk
{
    /** action => [label, icon] */
    public const ACTIONS = [
        'confirmed' => ['Confirm', 'check'], 'arrived' => ['Arrived', 'user'], 'in_consult' => ['In consultation', 'stethoscope'],
        'done' => ['Done', 'circle-check-big'], 'no_show' => ['No-show', 'ban'], 'cancelled' => ['Cancel', 'x'],
    ];

    private function bid(): int
    {
        return service('posSession')->businessId();
    }

    public function listQuery(string $date, int $doctorId): BaseBuilder
    {
        $b = db_connect()->table('bookings bk')
            ->select('bk.id, bk.reference, bk.status, bk.visit_stage, bk.source, bk.serial_no, bk.starts_at, bk.ends_at, bk.customer_name, bk.customer_phone, bk.note, bk.details, bk.doctor_id, bk.patient_id, bk.order_id,
                d.name AS doctor_name, p.seq AS patient_seq, p.sex, p.dob, p.age_years, p.age_recorded_on,
                (SELECT MAX(rx.id) FROM clinic_prescriptions rx WHERE rx.business_id = bk.business_id AND rx.booking_id = bk.id) AS rx_id', false)
            ->join('clinic_doctors d', 'd.id = bk.doctor_id', 'left')
            ->join('clinic_patients p', 'p.id = bk.patient_id', 'left')
            ->where('bk.business_id', $this->bid())->where('bk.doctor_id IS NOT NULL', null, false)
            ->where('bk.starts_at >=', $date . ' 00:00:00')->where('bk.starts_at <=', $date . ' 23:59:59');
        if ($doctorId > 0) {
            $b->where('bk.doctor_id', $doctorId);
        }

        return $b;
    }

    /** @return array{total: int, waiting: int, seen: int, pending: int} */
    public function counts(string $date, int $doctorId): array
    {
        $sql = "SELECT COUNT(*) AS total, SUM(status = 'confirmed' AND visit_stage IN ('arrived','in_consult')) AS waiting, SUM(status = 'done') AS seen, SUM(status = 'pending') AS pending
            FROM bookings WHERE business_id = ? AND doctor_id IS NOT NULL AND starts_at >= ? AND starts_at <= ? AND status <> 'cancelled'" . ($doctorId > 0 ? ' AND doctor_id = ' . $doctorId : '');
        $r = db_connect()->query($sql, [$this->bid(), $date . ' 00:00:00', $date . ' 23:59:59'])->getRowArray() ?? [];

        return ['total' => (int) ($r['total'] ?? 0), 'waiting' => (int) ($r['waiting'] ?? 0), 'seen' => (int) ($r['seen'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0)];
    }

    /**
     * A counter / phone appointment (confirmed straight away) or a walk-in queued now.
     *
     * @param array<string, mixed> $in doctor_id, starts_at | walk_in, patient_id | patient_name, patient_phone, patient_sex, patient_age, note
     * @return array<string, mixed>
     */
    public function book(array $in): array
    {
        ClinicGuard::require('clinic.appointments');
        $patient = ['name' => $in['patient_name'] ?? '', 'phone' => $in['patient_phone'] ?? '', 'sex' => $in['patient_sex'] ?? '', 'age' => $in['patient_age'] ?? '',
            'email' => $in['patient_email'] ?? ''];
        $row = service('clinicSchedule')->book([
            'doctor_id' => (int) ($in['doctor_id'] ?? 0), 'starts_at' => (string) ($in['starts_at'] ?? ''), 'walk_in' => ! empty($in['walk_in']),
            'patient_id' => (int) ($in['patient_id'] ?? 0), 'patient' => $patient, 'source' => 'pos', 'note' => trim((string) ($in['note'] ?? '')),
        ]);
        service('bookings')->setStatus((int) $row['id'], 'confirmed');
        service('audit')->activity('Appointment booked', $row['doctor_name'] . ' · serial ' . $row['serial_no'] . ' · ' . substr((string) $row['starts_at'], 0, 16) . ' · ' . $row['customer_name']);

        return $row;
    }

    /** Move an appointment along the visit workflow. */
    public function act(int $id, string $action): array
    {
        ClinicGuard::require('clinic.appointments');
        if (! isset(self::ACTIONS[$action])) {
            throw new ValidationException('Unknown step.', ['action' => 'Unknown step.']);
        }
        $db = db_connect();
        $bk = $db->table('bookings')->select('id, reference, status, visit_stage, customer_name, serial_no, doctor_id')->where('id', $id)->where('business_id', $this->bid())
            ->where('doctor_id IS NOT NULL', null, false)->get()->getRowArray() ?? throw new NotFoundException('Appointment not found.');
        if (in_array($bk['status'], ['done', 'cancelled'], true)) {
            throw new BusinessRuleException('This appointment is already ' . ($bk['visit_stage'] === 'no_show' ? 'marked no-show' : $bk['status']) . '.');
        }
        [$status, $stage] = match ($action) {
            'confirmed' => ['confirmed', null],
            'arrived' => ['confirmed', 'arrived'],
            'in_consult' => ['confirmed', 'in_consult'],
            'done' => ['done', $bk['visit_stage'] === 'no_show' ? null : $bk['visit_stage']],
            'no_show' => ['cancelled', 'no_show'],
            default => ['cancelled', null],
        };
        if ($status !== $bk['status']) {
            service('bookings')->setStatus($id, $status);
        }
        $db->table('bookings')->where('id', $id)->where('business_id', $this->bid())->update(['visit_stage' => $stage, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->activity('Appointment ' . strtolower(self::ACTIONS[$action][0]), $bk['reference'] . ' · serial ' . $bk['serial_no'] . ' · ' . $bk['customer_name']);

        return ['status' => $status, 'stage' => $stage];
    }

    /** Label and tag colour for an appointment row. */
    public static function stageTag(array $r): string
    {
        [$l, $k] = match (true) {
            $r['visit_stage'] === 'no_show' => ['No-show', 'danger'],
            $r['status'] === 'cancelled' => ['Cancelled', ''],
            $r['status'] === 'done' => ['Seen', 'ok'],
            $r['visit_stage'] === 'in_consult' => ['In consultation', 'primary'],
            $r['visit_stage'] === 'arrived' => ['Waiting', 'warn'],
            $r['status'] === 'pending' => ['New', 'warn'],
            default => ['Confirmed', ''],
        };

        return tag_html($l, $k);
    }

    /** @return list<string> the next steps for a row */
    public static function nextSteps(array $r): array
    {
        if (in_array($r['status'], ['done', 'cancelled'], true)) {
            return [];
        }

        return match (true) {
            $r['status'] === 'pending' => ['confirmed', 'arrived', 'cancelled'],
            $r['visit_stage'] === 'in_consult' => ['done'],
            $r['visit_stage'] === 'arrived' => ['in_consult', 'done', 'no_show'],
            default => ['arrived', 'no_show', 'cancelled'],
        };
    }
}
