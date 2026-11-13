<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ClinicSchedule;

/**
 * Prescriptions — written by the clinician, never generated: complaints, findings, diagnosis, notes,
 * medicines (picked from the medicines list or typed) with dose, frequency, duration, route and
 * instructions, investigations from the clinic's own test list, advice and follow-up.
 *
 * Versioning: a prescription starts as a draft (editable). Finalize locks it. Editing a final one
 * means "Revise" with a reason: a new draft revision (same RX number, revision + 1) copied from it;
 * finalizing that revision marks the earlier one superseded. Old revisions are never changed, every
 * step is in the activity log and every open / print in the medical-record access log.
 */
final class PrescriptionService
{
    public const TEXT = ['chief_complaints' => 5000, 'history' => 5000, 'examination' => 5000, 'diagnosis' => 5000, 'notes' => 5000, 'advice' => 5000];

    public const VITALS = ['bp' => 'BP', 'pulse' => 'Pulse', 'temp' => 'Temp', 'weight' => 'Weight', 'height' => 'Height', 'spo2' => 'SpO2', 'rr' => 'Resp. rate'];

    public const ITEM_FIELDS = ['brand_name' => 200, 'generic' => 255, 'strength' => 120, 'dosage_form' => 80, 'dose' => 120, 'frequency' => 120, 'duration' => 120, 'route' => 80, 'instructions' => 300];

    public const MAX_ITEMS = 40;

    private function bid(): int
    {
        return service('posSession')->businessId();
    }

    /**
     * Doctors the signed-in user may prescribe as: the doctor profiles linked to the user, or
     * (owner / staff without a linked profile, e.g. an assistant typing for the doctor) every active doctor.
     *
     * @return list<int>
     */
    public function allowedDoctors(): array
    {
        $mine = DoctorAdmin::mine();
        $u = service('posSession')->user();
        if ($mine !== [] && ! ($u['locked'] ?? false)) {
            return $mine;
        }

        return array_map(static fn (array $d): int => (int) $d['id'], service('clinicSchedule')->doctors());
    }

    private function assertDoctor(int $doctorId): void
    {
        if (! in_array($doctorId, $this->allowedDoctors(), true)) {
            throw new PermissionDeniedException('You can only write prescriptions as your own doctor profile.');
        }
    }

    /** Start a prescription (or open the draft already started for this appointment). */
    public function start(int $patientId, int $doctorId, ?int $bookingId = null): int
    {
        ClinicGuard::require('clinic.prescribe');
        $patient = (new PatientService())->find($patientId);
        $this->assertDoctor($doctorId);
        $db = db_connect();
        $bid = $this->bid();
        if ($bookingId !== null && $bookingId > 0) {
            $bk = $db->table('bookings')->select('id, patient_id, doctor_id, status, visit_stage')->where('id', $bookingId)->where('business_id', $bid)->get()->getRowArray();
            if ($bk === null || (int) $bk['patient_id'] !== $patientId) {
                throw new NotFoundException('Appointment not found for this patient.');
            }
            $existing = $db->table('clinic_prescriptions')->select('id')->where('business_id', $bid)->where('booking_id', $bookingId)->where('status', 'draft')->get()->getRow('id');
            if ($existing !== null) {
                return (int) $existing;
            }
        } else {
            $bookingId = null;
            $bk = null;
        }
        $id = Tx::run(function ($db) use ($patient, $doctorId, $bookingId, $bid): int {
            $db->query('SELECT id FROM businesses WHERE id = ? FOR UPDATE', [$bid]);
            $n = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(rx_no, 4) AS UNSIGNED)), 0) AS m FROM clinic_prescriptions WHERE business_id = ?", [$bid])->getRow('m') + 1;
            $s = service('posSession');
            $db->table('clinic_prescriptions')->insert([
                'tenant_id' => $s->tenantId(), 'business_id' => $bid, 'rx_no' => 'RX-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'revision' => 1, 'status' => 'draft',
                'doctor_id' => $doctorId, 'patient_id' => (int) $patient['id'], 'booking_id' => $bookingId, 'visit_date' => date('Y-m-d'),
                'patient_snapshot' => json_encode($this->patientSnapshot($patient), JSON_UNESCAPED_UNICODE), 'doctor_snapshot' => json_encode($this->doctorSnapshot($doctorId), JSON_UNESCAPED_UNICODE),
                'created_by' => $s->userId() ?: null, 'created_at' => date('Y-m-d H:i:s'),
            ]);

            return (int) $db->insertID();
        });
        if ($bk !== null && $bk['status'] === 'confirmed' && $bk['visit_stage'] !== 'in_consult') {
            $db->table('bookings')->where('id', $bookingId)->where('business_id', $bid)->update(['visit_stage' => 'in_consult', 'updated_at' => date('Y-m-d H:i:s')]);
        }
        ClinicGuard::logAccess($patientId, $id, 'prescription started');
        service('audit')->activity('Prescription started', PatientService::code($patient['seq']) . ' · ' . $patient['name']);

        return $id;
    }

    /** @return array<string, mixed> prescription with items, tests, doctor and patient (no access check) */
    public function find(int $id): array
    {
        $db = db_connect();
        $rx = $db->table('clinic_prescriptions')->where('id', $id)->where('business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Prescription not found.');
        $rx['vitals'] = json_decode((string) ($rx['vitals'] ?? ''), true) ?: [];
        $rx['patient_snapshot'] = json_decode((string) ($rx['patient_snapshot'] ?? ''), true) ?: [];
        $rx['doctor_snapshot'] = json_decode((string) ($rx['doctor_snapshot'] ?? ''), true) ?: [];
        $rx['items'] = $db->table('clinic_rx_items')->where('business_id', $this->bid())->where('prescription_id', $id)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        $rx['tests'] = $db->table('clinic_rx_tests')->where('business_id', $this->bid())->where('prescription_id', $id)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        $rx['doctor'] = $db->table('clinic_doctors')->where('id', (int) $rx['doctor_id'])->where('business_id', $this->bid())->get()->getRowArray() ?? [];
        $rx['patient'] = $db->table('clinic_patients')->where('id', (int) $rx['patient_id'])->where('business_id', $this->bid())->get()->getRowArray() ?? [];

        return $rx;
    }

    /** Open for reading (medical record): permission + access log. */
    public function open(int $id, string $action = 'prescription viewed'): array
    {
        ClinicGuard::assertEnabled();
        $rx = $this->find($id);
        if (! self::canOpen($rx)) {
            ClinicGuard::require('clinic.patients.view');
        }
        ClinicGuard::logAccess((int) $rx['patient_id'], $id, $action);

        return $rx;
    }

    /** @return list<array<string, mixed>> every revision of an RX number */
    public function revisions(string $rxNo): array
    {
        return db_connect()->table('clinic_prescriptions rx')->select('rx.id, rx.revision, rx.status, rx.revision_reason, rx.finalized_at, rx.created_at, u.name AS by_name')
            ->join('users u', 'u.id = COALESCE(rx.finalized_by, rx.created_by)', 'left')
            ->where('rx.business_id', $this->bid())->where('rx.rx_no', $rxNo)->orderBy('rx.revision', 'desc')->get()->getResultArray();
    }

    /**
     * Save a draft from the pad.
     *
     * @param array<string, mixed> $in text fields, vitals[], follow_up, follow_up_note, items[][], tests[] (ids), extra_tests[][]
     */
    public function save(int $id, array $in): void
    {
        ClinicGuard::require('clinic.prescribe');
        $rx = $this->find($id);
        if ($rx['status'] !== 'draft') {
            throw new BusinessRuleException('This prescription is final. Use "Revise" to make a new revision.');
        }
        $this->assertDoctor((int) $rx['doctor_id']);
        [$row, $items, $tests] = $this->clean($in);
        $row['patient_snapshot'] = json_encode(self::padPatient($this->patientSnapshot($rx['patient']), is_array($in['pt'] ?? null) ? $in['pt'] : []), JSON_UNESCAPED_UNICODE);
        $row['doctor_snapshot'] = json_encode($this->doctorSnapshot((int) $rx['doctor_id']), JSON_UNESCAPED_UNICODE);
        $row['updated_at'] = date('Y-m-d H:i:s');
        $bid = $this->bid();
        $tid = service('posSession')->tenantId();
        Tx::run(function ($db) use ($id, $row, $items, $tests, $bid, $tid): void {
            $db->table('clinic_prescriptions')->where('id', $id)->where('business_id', $bid)->where('status', 'draft')->update($row);
            $db->table('clinic_rx_items')->where('business_id', $bid)->where('prescription_id', $id)->delete();
            $db->table('clinic_rx_tests')->where('business_id', $bid)->where('prescription_id', $id)->delete();
            foreach ($items as $i => $it) {
                $db->table('clinic_rx_items')->insert($it + ['tenant_id' => $tid, 'business_id' => $bid, 'prescription_id' => $id, 'sort_order' => $i]);
            }
            foreach ($tests as $i => $t) {
                $db->table('clinic_rx_tests')->insert($t + ['tenant_id' => $tid, 'business_id' => $bid, 'prescription_id' => $id, 'sort_order' => $i]);
            }
        });
        ClinicGuard::logAccess((int) $rx['patient_id'], $id, 'prescription edited');
    }

    /** Lock a draft: it becomes the current revision; an earlier final revision is superseded. */
    public function finalize(int $id): void
    {
        ClinicGuard::require('clinic.prescribe');
        $rx = $this->find($id);
        if ($rx['status'] !== 'draft') {
            throw new BusinessRuleException('This prescription is already final.');
        }
        $this->assertDoctor((int) $rx['doctor_id']);
        $hasContent = $rx['items'] !== [] || $rx['tests'] !== [];
        foreach (array_keys(self::TEXT) as $k) {
            $hasContent = $hasContent || trim((string) $rx[$k]) !== '';
        }
        if (! $hasContent) {
            throw new BusinessRuleException('The prescription is empty. Write it before finalizing.');
        }
        $bid = $this->bid();
        Tx::run(function ($db) use ($id, $rx, $bid): void {
            $db->table('clinic_prescriptions')->where('business_id', $bid)->where('rx_no', $rx['rx_no'])->where('status', 'final')->where('id !=', $id)
                ->update(['status' => 'superseded', 'updated_at' => date('Y-m-d H:i:s')]);
            $db->table('clinic_prescriptions')->where('id', $id)->where('business_id', $bid)
                ->update(['status' => 'final', 'finalized_by' => service('posSession')->userId() ?: null, 'finalized_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        });
        ClinicGuard::logAccess((int) $rx['patient_id'], $id, 'prescription finalized');
        service('audit')->activity('Prescription finalized', $rx['rx_no'] . ' rev ' . $rx['revision'] . ' · ' . ($rx['patient']['name'] ?? ''));
    }

    /** New draft revision of a final prescription (the final one stays as it is until the new one is finalized). */
    public function revise(int $id, string $reason): int
    {
        ClinicGuard::require('clinic.prescribe');
        $rx = $this->find($id);
        if ($rx['status'] !== 'final') {
            throw new BusinessRuleException($rx['status'] === 'draft' ? 'This is a draft — edit it directly.' : 'A newer revision exists. Revise the latest one.');
        }
        $this->assertDoctor((int) $rx['doctor_id']);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
            throw new ValidationException('Say why the prescription is being revised.', ['reason' => 'Give a reason (3 to 300 characters).']);
        }
        $db = db_connect();
        $bid = $this->bid();
        $draft = $db->table('clinic_prescriptions')->select('id')->where('business_id', $bid)->where('rx_no', $rx['rx_no'])->where('status', 'draft')->get()->getRow('id');
        if ($draft !== null) {
            return (int) $draft;
        }
        $s = service('posSession');
        $newId = Tx::run(function ($db) use ($rx, $reason, $bid, $s): int {
            $rev = (int) $db->query('SELECT MAX(revision) AS m FROM clinic_prescriptions WHERE business_id = ? AND rx_no = ? FOR UPDATE', [$bid, $rx['rx_no']])->getRow('m') + 1;
            $copy = array_intersect_key($rx, array_flip(['tenant_id', 'business_id', 'rx_no', 'doctor_id', 'patient_id', 'booking_id', 'visit_date', 'chief_complaints', 'history', 'examination',
                'diagnosis', 'notes', 'advice', 'follow_up', 'follow_up_note', 'paper', 'style']));
            $db->table('clinic_prescriptions')->insert($copy + [
                'root_id' => (int) ($rx['root_id'] ?: $rx['id']), 'revision' => $rev, 'status' => 'draft', 'vitals' => json_encode($rx['vitals'], JSON_UNESCAPED_UNICODE),
                'patient_snapshot' => json_encode($rx['patient_snapshot'], JSON_UNESCAPED_UNICODE), 'doctor_snapshot' => json_encode($rx['doctor_snapshot'], JSON_UNESCAPED_UNICODE),
                'revision_reason' => mb_substr($reason, 0, 300), 'created_by' => $s->userId() ?: null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $nid = (int) $db->insertID();
            foreach ($rx['items'] as $it) {
                unset($it['id']);
                $db->table('clinic_rx_items')->insert(['prescription_id' => $nid] + $it);
            }
            foreach ($rx['tests'] as $t) {
                unset($t['id']);
                $db->table('clinic_rx_tests')->insert(['prescription_id' => $nid] + $t);
            }

            return $nid;
        });
        ClinicGuard::logAccess((int) $rx['patient_id'], $newId, 'prescription revised');
        service('audit')->activity('Prescription revised', $rx['rx_no'] . ' rev ' . $rx['revision'] . ' → new draft · ' . $reason);

        return $newId;
    }

    /** Throw away a draft (a final prescription is never deleted). Returns the id to show next (the final revision), or null. */
    public function discard(int $id): ?int
    {
        ClinicGuard::require('clinic.prescribe');
        $rx = $this->find($id);
        if ($rx['status'] !== 'draft') {
            throw new BusinessRuleException('Final prescriptions cannot be deleted.');
        }
        $this->assertDoctor((int) $rx['doctor_id']);
        $db = db_connect();
        if ((int) $rx['revision'] === 1 && $db->table('clinic_prescriptions')->where('business_id', $this->bid())->where('root_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('This draft has revisions.');
        }
        $db->table('clinic_prescriptions')->where('id', $id)->where('business_id', $this->bid())->where('status', 'draft')->delete();
        ClinicGuard::logAccess((int) $rx['patient_id'], null, 'draft discarded');
        service('audit')->activity('Prescription draft discarded', $rx['rx_no'] . ' rev ' . $rx['revision']);
        $final = $db->table('clinic_prescriptions')->select('id')->where('business_id', $this->bid())->where('rx_no', $rx['rx_no'])->where('status', 'final')->get()->getRow('id');

        return $final !== null ? (int) $final : null;
    }

    /** A clinician may open a prescription they wrote even without clinic.patients.view. */
    public static function canOpen(array $rx): bool
    {
        $s = service('posSession');

        return $s->can('clinic.patients.view') || ($s->can('clinic.prescribe') && (int) ($rx['created_by'] ?? 0) === $s->userId() && $s->userId() > 0);
    }

    /**
     * Patient line of this visit: the profile, with name / age / sex as typed on the pad (this
     * prescription only — the profile itself is changed on the patient page).
     *
     * @param array<string, mixed> $snap
     * @param array<string, mixed> $pt   name, age, sex from the pad
     * @return array<string, mixed>
     */
    public static function padPatient(array $snap, array $pt): array
    {
        $name = trim((string) ($pt['name'] ?? ''));
        if ($name !== '') {
            $snap['name'] = mb_substr($name, 0, 120);
        }
        if (isset($pt['age']) && $pt['age'] !== '' && ctype_digit((string) $pt['age']) && (int) $pt['age'] <= 130) {
            $snap['age'] = (int) $pt['age'];
        }
        if (in_array($pt['sex'] ?? null, ['male', 'female', 'other'], true)) {
            $snap['sex'] = $pt['sex'];
        }

        return $snap;
    }

    /**
     * Prescriptions list (latest revision of each RX number), for clinic.patients.view; a
     * prescriber without it sees the ones they wrote.
     *
     * @param array{status?: string, doctor?: int, from?: string, to?: string} $f
     */
    public function listQuery(array $f): \CodeIgniter\Database\BaseBuilder
    {
        $s = service('posSession');
        $b = db_connect()->table('clinic_prescriptions rx')
            ->select('rx.id, rx.rx_no, rx.revision, rx.status, rx.visit_date, rx.diagnosis, rx.follow_up, rx.created_at, rx.finalized_at, rx.patient_id, rx.created_by,
                p.name AS patient_name, p.phone AS patient_phone, p.seq AS patient_seq, d.name AS doctor_name')
            ->join('clinic_patients p', 'p.id = rx.patient_id', 'left')
            ->join('clinic_doctors d', 'd.id = rx.doctor_id', 'left')
            ->where('rx.business_id', $this->bid())->where('rx.status !=', 'superseded');
        if (! $s->can('clinic.patients.view')) {
            $b->where('rx.created_by', $s->userId());
        }
        if (in_array($f['status'] ?? '', ['draft', 'final'], true)) {
            $b->where('rx.status', $f['status']);
        }
        if (! empty($f['doctor'])) {
            $b->where('rx.doctor_id', (int) $f['doctor']);
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (! empty($f[$k]) && \OmniPOS\Core\Services\ClinicSchedule::validDate((string) $f[$k])) {
                $b->where('rx.visit_date ' . $op, $f[$k]);
            }
        }

        return $b;
    }

    /**
     * Medicine lines of an earlier final prescription of the same patient, for "Copy medicines
     * from this visit" (an explicit clinician action; the lines land in the draft unsaved).
     *
     * @return list<array<string, mixed>>
     */
    public function itemsToCopy(int $fromId, int $intoId): array
    {
        ClinicGuard::require('clinic.prescribe');
        $into = $this->find($intoId);
        $from = $this->find($fromId);
        if ((int) $from['patient_id'] !== (int) $into['patient_id'] || $from['status'] === 'draft') {
            throw new NotFoundException('That prescription is not an earlier visit of this patient.');
        }
        ClinicGuard::logAccess((int) $from['patient_id'], $fromId, 'medicines copied');

        return array_map(static fn (array $it): array => array_intersect_key($it, array_flip(['medicine_id', 'brand_name', 'generic', 'strength', 'dosage_form', 'dose', 'frequency', 'duration', 'route', 'instructions'])), $from['items']);
    }

    /**
     * Validate & normalise the pad input.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function clean(array $in): array
    {
        $errors = [];
        $row = [];
        foreach (self::TEXT as $k => $max) {
            $v = trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? '')));
            if (mb_strlen($v) > $max) {
                $errors[$k] = 'This is too long (at most ' . $max . ' characters).';
            }
            $row[$k] = $v !== '' ? $v : null;
        }
        $vit = [];
        foreach (array_keys(self::VITALS) as $k) {
            $v = trim((string) (($in['vitals'] ?? [])[$k] ?? ''));
            if ($v !== '') {
                $vit[$k] = mb_substr($v, 0, 30);
            }
        }
        $row['vitals'] = $vit !== [] ? json_encode($vit, JSON_UNESCAPED_UNICODE) : null;
        $fu = trim((string) ($in['follow_up'] ?? ''));
        if ($fu !== '' && (! ClinicSchedule::validDate($fu) || $fu < date('Y-m-d', strtotime('-1 day')))) {
            $errors['follow_up'] = 'Pick a follow-up date from today on.';
        }
        $row['follow_up'] = $fu !== '' ? $fu : null;
        $row['follow_up_note'] = trim((string) ($in['follow_up_note'] ?? '')) !== '' ? mb_substr(trim((string) $in['follow_up_note']), 0, 200) : null;
        $row['paper'] = in_array($in['paper'] ?? '', ['a4', 'a5'], true) ? $in['paper'] : null;
        $row['style'] = isset(ClinicConfig::STYLES[$in['style'] ?? '']) ? $in['style'] : null;

        $items = [];
        foreach (array_values(is_array($in['items'] ?? null) ? $in['items'] : []) as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            $mid = (int) ($r['medicine_id'] ?? 0);
            $it = [];
            foreach (self::ITEM_FIELDS as $f => $max) {
                $v = trim((string) ($r[$f] ?? ''));
                if (mb_strlen($v) > $max) {
                    $errors["items.$i.$f"] = 'Too long (at most ' . $max . ' characters).';
                }
                $it[$f] = $v !== '' ? mb_substr($v, 0, $max) : null;
            }
            $it['manufacturer'] = null;
            $it['medicine_id'] = null;
            if ($mid > 0 && ($m = MedicineSearch::find($mid)) !== null) {
                // the line names a listed medicine: its name & details come from the list, the clinician's dose etc. from the pad
                $it = array_merge($it, ['medicine_id' => $m['id'], 'brand_name' => mb_substr($m['brand_name'], 0, 200), 'generic' => $m['generic'] !== '' ? mb_substr($m['generic'], 0, 255) : null,
                    'strength' => $m['strength'] !== '' ? mb_substr($m['strength'], 0, 120) : null, 'dosage_form' => $m['dosage_form'] !== '' ? mb_substr($m['dosage_form'], 0, 80) : null,
                    'manufacturer' => $m['manufacturer'] !== '' ? mb_substr($m['manufacturer'], 0, 160) : null]);
            }
            if ($it['brand_name'] === null) {
                $rest = array_filter(array_diff_key($it, ['brand_name' => 1, 'manufacturer' => 1, 'medicine_id' => 1]));
                if ($rest !== []) {
                    $errors["items.$i.brand_name"] = 'Name the medicine on this line, or remove the line.';
                }

                continue;
            }
            $items[] = $it;
        }
        if (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'At most ' . self::MAX_ITEMS . ' medicines on one prescription.';
        }

        $tests = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($in['tests'] ?? null) ? $in['tests'] : []))));
        if ($ids !== []) {
            $known = db_connect()->table('clinic_tests')->select('id, category, name')->where('business_id', $this->bid())->whereIn('id', $ids)->get()->getResultArray();
            if (count($known) !== count($ids)) {
                $errors['tests'] = 'Pick tests from the clinic\'s list.';
            }
            foreach ($known as $t) {
                $tests[] = ['test_id' => (int) $t['id'], 'category' => (string) $t['category'], 'name' => (string) $t['name'], 'note' => null];
            }
        }
        foreach (array_values(is_array($in['extra_tests'] ?? null) ? $in['extra_tests'] : []) as $i => $t) {
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 160) {
                $errors["extra_tests.$i.name"] = 'Too long.';
            }
            $cat = (string) ($t['category'] ?? 'other');
            $tests[] = ['test_id' => null, 'category' => isset(ClinicConfig::TEST_CATS[$cat]) ? $cat : 'other', 'name' => mb_substr($name, 0, 160),
                'note' => trim((string) ($t['note'] ?? '')) !== '' ? mb_substr(trim((string) $t['note']), 0, 200) : null];
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the prescription.', $errors);
        }

        return [$row, $items, $tests];
    }

    /** @return array<string, mixed> patient details printed on the prescription (as on the visit day) */
    private function patientSnapshot(array $p): array
    {
        return ['code' => PatientService::code($p['seq'] ?? 0), 'name' => (string) ($p['name'] ?? ''), 'age' => ClinicSchedule::age($p), 'sex' => $p['sex'] ?? null,
            'phone' => $p['phone'] ?? null, 'blood_group' => $p['blood_group'] ?? null, 'address' => $p['address'] ?? null];
    }

    /** @return array<string, mixed> doctor details printed on the prescription */
    private function doctorSnapshot(int $doctorId): array
    {
        $d = db_connect()->table('clinic_doctors')->select('name, degrees, specialty, reg_no, designation, chamber, phone')->where('id', $doctorId)->where('business_id', $this->bid())->get()->getRowArray() ?? [];

        return $d;
    }
}
