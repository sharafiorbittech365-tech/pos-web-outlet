<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Clinic\Services\ClinicGuard;
use App\Modules\Clinic\Services\PatientService;
use App\Modules\Clinic\Services\PrescriptionService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Clinic › Patients: list, registration, profile; the medical record for clinicians only. */
final class PatientsController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        ClinicGuard::require('clinic.appointments');
        $svc = new PatientService();
        $table = DataTable::make('pt', $this->request)->from($svc->listQuery())
            ->columns([
                Column::make('code', 'No.')->nowrap()->sortBy('p.seq')->cell(static fn (array $r): string => '<span class="mono">' . esc(PatientService::code($r['seq'])) . '</span>')->csv(static fn (array $r): string => PatientService::code($r['seq'])),
                Column::make('name', 'Patient')->sortBy('p.name')->searchIn('p.name', 'p.phone')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('clinic/patients/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted">' . esc(PatientService::ageSex($r)) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('phone', 'Phone')->cell(static fn (array $r): string => $r['phone'] ? '<a href="tel:' . esc($r['phone'], 'attr') . '">' . esc($r['phone']) . '</a>' : '')->csv(static fn (array $r): string => (string) $r['phone']),
                Column::make('blood', 'Blood')->cell(static fn (array $r): string => esc((string) $r['blood_group']))->csv(static fn (array $r): string => (string) $r['blood_group']),
                Column::make('last', 'Last visit')->nowrap()->sortBy('last_visit')->cell(static fn (array $r): string => $r['last_visit'] ? esc(date_str($r['last_visit'])) : '<span class="muted">—</span>')->csv(static fn (array $r): string => (string) $r['last_visit']),
            ])->defaultSort('code', 'desc')->placeholder('Name or phone…')->unit('patients')->exportable('patients')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('users') . '</span><b>No patients yet</b><span>Patients are added when they book, or with “New patient”.</span></div>');
        if ($csv = $table->csvResponse($this->response)) {
            service('audit')->activity('Patient list exported');

            return $csv;
        }

        return $this->page('App\Modules\Clinic\Views\patients', ['title' => 'Patients', 'table' => $table->render(), 'blood' => PatientService::BLOOD, 'scripts' => AppointmentsController::assets()]);
    }

    public function search(): ResponseInterface
    {
        ClinicGuard::require('clinic.appointments');

        return $this->json((new PatientService())->search($this->getStr('q', 60)));
    }

    public function store(): RedirectResponse
    {
        $row = (new PatientService())->create((array) $this->request->getPost());

        return $this->done('Registered ' . PatientService::code($row['seq']) . ' · ' . $row['name'] . '.', site_url('clinic/patients/' . $row['id']));
    }

    public function show(int $id): string
    {
        ClinicGuard::require('clinic.appointments');
        $svc = new PatientService();
        $p = $svc->find($id);
        $canRecord = $this->s()->can('clinic.patients.view');

        return $this->page('App\Modules\Clinic\Views\patient', [
            'title' => $p['name'], 'p' => $p, 'visits' => $svc->visits($id), 'record' => $canRecord ? $svc->record($id) : null,
            'access' => $this->s()->can('clinic.manage') && $canRecord ? ClinicGuard::accessLog($id) : null, 'blood' => PatientService::BLOOD,
            'doctors' => $this->s()->can('clinic.prescribe') ? array_values(array_filter(service('clinicSchedule')->doctors(), static fn (array $d): bool => in_array((int) $d['id'], (new PrescriptionService())->allowedDoctors(), true))) : [],
            'scripts' => AppointmentsController::assets(),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        (new PatientService())->update($id, (array) $this->request->getPost());

        return $this->done('Patient profile saved.', site_url('clinic/patients/' . $id));
    }
}
