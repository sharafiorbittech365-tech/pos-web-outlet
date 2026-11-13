<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ClinicSchedule;

/**
 * Patient profiles (Clinic › Patients). The profile itself (name, phone, age, sex, address) is for the
 * front desk (clinic.appointments); the visit history with prescriptions is a medical record
 * (clinic.patients.view) and every open of it is written to clinic_access_log.
 */
final class PatientService
{
    public const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private function bid(): int
    {
        return service('posSession')->businessId();
    }

    public static function code(int|string $seq): string
    {
        return 'P-' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function listQuery(): BaseBuilder
    {
        return db_connect()->table('clinic_patients p')
            ->select('p.id, p.seq, p.name, p.phone, p.sex, p.dob, p.age_years, p.age_recorded_on, p.blood_group, p.created_at,
                (SELECT MAX(bk.starts_at) FROM bookings bk WHERE bk.business_id = p.business_id AND bk.patient_id = p.id) AS last_visit', false)
            ->where('p.business_id', $this->bid())->where('p.deleted_at', null);
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return db_connect()->table('clinic_patients')->where('id', $id)->where('business_id', $this->bid())->where('deleted_at', null)->get()->getRowArray()
            ?? throw new NotFoundException('Patient not found.');
    }

    /** @param array<string, mixed> $in */
    public function create(array $in): array
    {
        ClinicGuard::require('clinic.appointments');
        $row = service('clinicSchedule')->createPatient($in);
        service('audit')->activity('Patient registered', self::code($row['seq']) . ' · ' . $row['name']);

        return $row;
    }

    /** @param array<string, mixed> $in */
    public function update(int $id, array $in): void
    {
        ClinicGuard::require('clinic.appointments');
        $p = $this->find($id);
        $errors = ClinicSchedule::patientErrors($in);
        if ($errors !== []) {
            throw new ValidationException('Please check the patient details.', $errors);
        }
        $row = ClinicSchedule::patientFields($in);
        // keep the recorded age's date when the age did not change
        if ($row['age_years'] !== null && (int) $row['age_years'] === (int) $p['age_years'] && $p['age_recorded_on'] !== null) {
            $row['age_recorded_on'] = $p['age_recorded_on'];
        }
        db_connect()->table('clinic_patients')->where('id', $id)->where('business_id', $this->bid())->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
        ClinicGuard::logAccess($id, null, 'profile edited');
        service('audit')->activity('Patient profile changed', self::code($p['seq']) . ' · ' . $row['name']);
    }

    /**
     * Appointments of the patient (front-desk view: dates, doctors, status — no clinical content).
     *
     * @return list<array<string, mixed>>
     */
    public function visits(int $id): array
    {
        return db_connect()->table('bookings bk')->select('bk.id, bk.reference, bk.status, bk.visit_stage, bk.serial_no, bk.starts_at, d.name AS doctor_name')
            ->join('clinic_doctors d', 'd.id = bk.doctor_id', 'left')
            ->where('bk.business_id', $this->bid())->where('bk.patient_id', $id)->orderBy('bk.starts_at', 'desc')->limit(100)->get()->getResultArray();
    }

    /**
     * The medical record: prescriptions (latest revision of each), logged as an access.
     *
     * @return list<array<string, mixed>>
     */
    public function record(int $id): array
    {
        ClinicGuard::require('clinic.patients.view');
        $this->find($id);
        ClinicGuard::logAccess($id, null, 'record viewed');

        return db_connect()->table('clinic_prescriptions rx')
            ->select('rx.id, rx.rx_no, rx.revision, rx.status, rx.visit_date, rx.diagnosis, rx.chief_complaints, rx.follow_up, rx.finalized_at, d.name AS doctor_name')
            ->join('clinic_doctors d', 'd.id = rx.doctor_id', 'left')
            ->where('rx.business_id', $this->bid())->where('rx.patient_id', $id)->where('rx.status !=', 'superseded')
            ->orderBy('rx.visit_date', 'desc')->orderBy('rx.id', 'desc')->limit(200)->get()->getResultArray();
    }

    /**
     * Quick search for the appointment and prescription forms (name, phone, P-number).
     *
     * @return list<array{id: int, code: string, name: string, phone: string, age: ?int, sex: ?string}>
     */
    public function search(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $b = db_connect()->table('clinic_patients')->select('id, seq, name, phone, sex, dob, age_years, age_recorded_on')->where('business_id', $this->bid())->where('deleted_at', null);
        if (preg_match('/^p-?0*(\d+)$/i', $q, $m)) {
            $b->where('seq', (int) $m[1]);
        } elseif (preg_match('/^[0-9+\- ]{3,}$/', $q)) {
            $b->like('phone', (string) preg_replace('/[^0-9+]/', '', $q), 'after');
        } else {
            $b->like('name', $q, 'both');
        }

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => self::code($r['seq']), 'name' => (string) $r['name'], 'phone' => (string) $r['phone'],
            'age' => ClinicSchedule::age($r), 'sex' => $r['sex']], $b->orderBy('name')->limit(15)->get()->getResultArray());
    }

    /** "34 y · Female" */
    public static function ageSex(array $p): string
    {
        $age = ClinicSchedule::age($p);

        return implode(' · ', array_filter([$age !== null ? $age . ' y' : '', $p['sex'] ? ucfirst((string) $p['sex']) : '']));
    }
}
