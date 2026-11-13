<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\ClinicGuard;
use App\Modules\Clinic\Services\MedicineSearch;
use App\Modules\Clinic\Services\PadView;
use App\Modules\Clinic\Services\PatientService;
use App\Modules\Clinic\Services\PrescriptionService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Clinic › Prescriptions: list, new, the pad editor (draft → finalize → revise), print / save as PDF. */
final class PrescriptionsController extends BackOfficeController
{
    private function assertListAccess(): void
    {
        ClinicGuard::assertEnabled();
        if (! $this->s()->can('clinic.prescribe') && ! $this->s()->can('clinic.patients.view')) {
            throw new PermissionDeniedException('Only clinicians and authorised staff can open prescriptions.');
        }
    }

    public function index(): string|ResponseInterface
    {
        $this->assertListAccess();
        $svc = new PrescriptionService();
        $f = ['status' => $this->getStr('status', 10), 'doctor' => $this->getInt('doctor'), 'from' => $this->getStr('from', 10), 'to' => $this->getStr('to', 10)];
        $table = DataTable::make('rxl', $this->request)->from($svc->listQuery($f))->keep(array_filter($f))
            ->columns([
                Column::make('date', 'Date')->nowrap()->sortBy('rx.visit_date')->cell(static fn (array $r): string => '<b>' . esc(date_str($r['visit_date'])) . '</b><br><small class="muted mono">' . esc($r['rx_no']) . ((int) $r['revision'] > 1 ? ' r' . (int) $r['revision'] : '') . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['visit_date']),
                Column::make('patient', 'Patient')->sortBy('p.name')->searchIn('p.name', 'p.phone', 'rx.rx_no', 'd.name')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('clinic/rx/' . $r['id']), 'attr') . '"><b>' . esc((string) $r['patient_name']) . '</b></a><br><small class="muted">' . esc(implode(' · ', array_filter([PatientService::code((int) $r['patient_seq']), (string) $r['patient_phone']]))) . '</small>')
                    ->csv(static fn (array $r): string => $r['patient_name'] . ' ' . $r['patient_phone']),
                Column::make('doctor', 'Doctor')->sortBy('d.name')->cell(static fn (array $r): string => esc((string) $r['doctor_name']))->csv(static fn (array $r): string => (string) $r['doctor_name']),
                Column::make('dx', 'Diagnosis')->cell(static fn (array $r): string => $r['diagnosis'] ? esc(mb_strimwidth((string) $r['diagnosis'], 0, 70, '…')) : '<span class="muted">—</span>')->noCsv(),
                Column::make('status', 'Status')->sortBy('rx.status')->cell(static fn (array $r): string => $r['status'] === 'draft' ? tag_html('Draft', 'warn') : tag_html('Final', 'ok'))->csv(static fn (array $r): string => (string) $r['status']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => '<a class="btn sm" href="' . esc(site_url('clinic/rx/' . $r['id']), 'attr') . '">' . icon($r['status'] === 'draft' ? 'edit' : 'eye', 'sm') . ($r['status'] === 'draft' ? 'Continue' : 'Open') . '</a> '
                    . ($r['status'] === 'final' ? '<a class="btn sm" href="' . esc(site_url('clinic/rx/' . $r['id'] . '/print'), 'attr') . '" target="_blank" rel="noopener" aria-label="Print ' . esc($r['rx_no'], 'attr') . '">' . icon('print', 'sm') . '</a>' : '')),
            ])->defaultSort('date', 'desc')->placeholder('Patient, phone, RX no., doctor…')->unit('prescriptions')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('rx') . '</span><b>No prescriptions here</b><span>Start one with “New prescription”, or from a patient in the appointments queue.</span></div>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Clinic\Views\prescriptions', [
            'title' => 'Prescriptions', 'table' => $table->render(), 'f' => $f, 'doctors' => service('clinicSchedule')->doctors(), 'canRx' => $this->s()->can('clinic.prescribe'),
            'scripts' => AppointmentsController::assets(),
        ]);
    }

    public function create(): string
    {
        ClinicGuard::require('clinic.prescribe');
        $svc = new PrescriptionService();
        $allowed = $svc->allowedDoctors();
        $patient = $this->getInt('patient') > 0 ? (new PatientService())->find($this->getInt('patient')) : null;

        return $this->page('App\Modules\Clinic\Views\rx_new', [
            'title' => 'New prescription', 'patient' => $patient, 'blood' => PatientService::BLOOD,
            'doctors' => array_values(array_filter(service('clinicSchedule')->doctors(), static fn (array $d): bool => in_array((int) $d['id'], $allowed, true))),
            'scripts' => AppointmentsController::assets(),
        ]);
    }

    /** Start a prescription for an existing patient, or a patient added right here (quick add). */
    public function start(): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $patientId = (int) ($in['patient_id'] ?? 0);
        if ($patientId < 1 && trim((string) ($in['patient_name'] ?? '')) !== '') {
            ClinicGuard::require('clinic.prescribe');
            $p = service('clinicSchedule')->findOrCreatePatient(['name' => $in['patient_name'], 'phone' => $in['patient_phone'] ?? '', 'age' => $in['patient_age'] ?? '',
                'sex' => $in['patient_sex'] ?? '']);
            service('audit')->activity('Patient registered', PatientService::code((int) $p['seq']) . ' · ' . $p['name']);
            $patientId = (int) $p['id'];
        }
        $id = (new PrescriptionService())->start($patientId, (int) ($in['doctor_id'] ?? 0), (int) ($in['booking_id'] ?? 0) ?: null);

        return redirect()->to(site_url('clinic/rx/' . $id));
    }

    public function show(int $id): string
    {
        $svc = new PrescriptionService();
        $rx = $svc->open($id);
        $cfg = new ClinicConfig();
        $s = $this->s();
        $editable = $rx['status'] === 'draft' && $s->can('clinic.prescribe') && in_array((int) $rx['doctor_id'], $svc->allowedDoctors(), true);
        $db = db_connect();
        $booking = $rx['booking_id'] ? $db->table('bookings')->select('id, reference, serial_no, starts_at, status, visit_stage, source, note')->where('id', (int) $rx['booking_id'])
            ->where('business_id', $s->businessId())->get()->getRowArray() : null;

        return $this->page('App\Modules\Clinic\Views\rx', [
            'title' => $rx['rx_no'] . ' · ' . ($rx['patient']['name'] ?? ''), 'rx' => $rx, 'editable' => $editable, 'lists' => $cfg->lists(), 'tests' => $cfg->tests(true),
            'view' => PadView::build($rx, $s->business()), 'booking' => $booking,
            'revisions' => $svc->revisions((string) $rx['rx_no']), 'canRx' => $s->can('clinic.prescribe'), 'medicinesOn' => MedicineSearch::available(),
            'history' => $s->can('clinic.patients.view') ? $db->table('clinic_prescriptions rx')->select('rx.id, rx.rx_no, rx.visit_date, rx.diagnosis, d.name AS doctor_name,
                    (SELECT COUNT(*) FROM clinic_rx_items i WHERE i.prescription_id = rx.id) AS n_items', false)
                ->join('clinic_doctors d', 'd.id = rx.doctor_id', 'left')->where('rx.business_id', $s->businessId())->where('rx.patient_id', (int) $rx['patient_id'])
                ->where('rx.status', 'final')->where('rx.rx_no !=', $rx['rx_no'])->orderBy('rx.visit_date', 'desc')->limit(10)->get()->getResultArray() : [],
            'scripts' => AppointmentsController::assets(),
        ]);
    }

    public function save(int $id): RedirectResponse
    {
        $svc = new PrescriptionService();
        $svc->save($id, (array) $this->request->getPost());
        $action = (string) $this->request->getPost('action');
        if ($action === 'finalize' || $this->request->getPost('finalize') === '1') {
            $svc->finalize($id);

            return redirect()->to(site_url('clinic/rx/' . $id))->with('success', 'Prescription finalized and locked.')->with('rx_print', $id);
        }
        if ($action === 'preview') {
            return redirect()->to(site_url('clinic/rx/' . $id))->with('success', 'Draft saved.')->with('rx_print', $id);
        }

        return $this->done('Draft saved.', site_url('clinic/rx/' . $id));
    }

    /** Medicine lines of an earlier visit (JSON) for "Copy medicines from this visit". */
    public function items(int $from): ResponseInterface
    {
        return $this->json((new PrescriptionService())->itemsToCopy($from, $this->getInt('into')));
    }

    public function revise(int $id): RedirectResponse
    {
        $new = (new PrescriptionService())->revise($id, (string) $this->request->getPost('reason'));

        return $this->done('New revision started — the final prescription stays as it was until you finalize this one.', site_url('clinic/rx/' . $new));
    }

    public function discard(int $id): RedirectResponse
    {
        $rx = (new PrescriptionService())->find($id);
        $next = (new PrescriptionService())->discard($id);

        return $this->done('Draft discarded.', $next !== null ? site_url('clinic/rx/' . $next) : site_url('clinic/prescriptions'));
    }

    /** Medicine search for the pad (JSON; empty when no medicine list is installed). */
    public function medicines(): ResponseInterface
    {
        ClinicGuard::require('clinic.prescribe');

        return $this->json(MedicineSearch::search($this->getStr('q', 60)), ['available' => MedicineSearch::available()]);
    }

    /**
     * Print-ready prescription on the doctor's (or clinic's) pad. There is no PDF library in the POS:
     * "Save as PDF" is the browser print dialog; @page carries the paper size and margins.
     */
    public function print(int $id): string
    {
        $rx = (new PrescriptionService())->open($id, 'prescription printed');
        $paper = (string) ($this->request->getGet('paper') ?? '');

        return view('App\Modules\Clinic\Views\rx_print', ['rx' => $rx, 'view' => PadView::build($rx, $this->s()->business(), $paper !== '' ? $paper : null),
            'auto' => (string) $this->request->getGet('auto') === '1',
            'booking' => $rx['booking_id'] ? db_connect()->table('bookings')->select('serial_no')->where('id', (int) $rx['booking_id'])->where('business_id', $this->s()->businessId())->get()->getRowArray() : null]);
    }
}
