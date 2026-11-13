<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Clinic\Services\AppointmentDesk;
use App\Modules\Clinic\Services\ClinicGuard;
use App\Modules\Clinic\Services\PatientService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Services\ClinicSchedule;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Clinic › Appointments: the day's queue per doctor, counter / phone appointments, visit steps. */
final class AppointmentsController extends BackOfficeController
{
    public function home(): RedirectResponse
    {
        ClinicGuard::assertEnabled();
        $to = match (true) {
            $this->s()->can('clinic.appointments') => 'clinic/appointments',
            $this->s()->can('clinic.manage') => 'clinic/doctors',
            default => 'dashboard',
        };

        return redirect()->to(site_url($to));
    }

    public function index(): string|ResponseInterface
    {
        ClinicGuard::require('clinic.appointments');
        $date = $this->getStr('date', 10);
        $date = ClinicSchedule::validDate($date) ? $date : date('Y-m-d');
        $doctorId = $this->getInt('doctor');
        $desk = new AppointmentDesk();
        $canRx = $this->s()->can('clinic.prescribe');
        $canRecord = $this->s()->can('clinic.patients.view');
        $table = DataTable::make('ap', $this->request)->from($desk->listQuery($date, $doctorId))->keep(array_filter(['date' => $date !== date('Y-m-d') ? $date : '', 'doctor' => $doctorId ?: '']))
            ->columns([
                Column::make('serial', 'Serial')->num()->sortBy('bk.serial_no')->cell(static fn (array $r): string => '<b class="cl-serial">' . (int) $r['serial_no'] . '</b>')->csv(static fn (array $r): string => (string) $r['serial_no']),
                Column::make('at', 'Time')->nowrap()->sortBy('bk.starts_at')->cell(static function (array $r): string {
                    $d = json_decode((string) ($r['details'] ?? '{}'), true) ?: [];

                    return '<b>' . esc(time_str($r['starts_at'])) . '</b>' . (! empty($d['walk_in']) ? '<br><small class="muted">Walk-in</small>' : '');
                })->csv(static fn (array $r): string => (string) $r['starts_at']),
                Column::make('patient', 'Patient')->sortBy('bk.customer_name')->searchIn('bk.customer_name', 'bk.customer_phone', 'bk.reference')
                    ->cell(static fn (array $r): string => ($r['patient_id'] ? '<a class="link" href="' . esc(site_url('clinic/patients/' . $r['patient_id']), 'attr') . '"><b>' . esc($r['customer_name']) . '</b></a>' : '<b>' . esc($r['customer_name']) . '</b>')
                        . '<br><small class="muted">' . esc(implode(' · ', array_filter([$r['patient_seq'] ? PatientService::code($r['patient_seq']) : '', PatientService::ageSex($r), $r['customer_phone']]))) . '</small>')
                    ->csv(static fn (array $r): string => $r['customer_name'] . ' ' . $r['customer_phone']),
                Column::make('doctor', 'Doctor')->sortBy('d.name')->cell(static fn (array $r): string => esc((string) $r['doctor_name']))->csv(static fn (array $r): string => (string) $r['doctor_name']),
                Column::make('src', 'From')->cell(static fn (array $r): string => $r['source'] === 'pos' ? tag_html('Desk') : tag_html('Online', 'primary'))->csv(static fn (array $r): string => (string) $r['source']),
                Column::make('status', 'Status')->sortBy('bk.status')->cell(static fn (array $r): string => AppointmentDesk::stageTag($r))->csv(static fn (array $r): string => (string) ($r['visit_stage'] ?: $r['status'])),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static function (array $r) use ($canRx, $canRecord): string {
                    $out = '';
                    foreach (AppointmentDesk::nextSteps($r) as $st) {
                        [$label, $ic] = AppointmentDesk::ACTIONS[$st];
                        $out .= '<form class="inline" method="post" action="' . esc(site_url('clinic/appointments/' . $r['id'] . '/step'), 'attr') . '"' . (in_array($st, ['cancelled', 'no_show'], true) ? ' data-confirm="' . esc($label, 'attr') . ' this appointment?"' : '') . '>'
                            . csrf_field() . '<input type="hidden" name="action" value="' . $st . '"><button class="btn sm ' . ($st === 'cancelled' || $st === 'no_show' ? 'danger' : ($st === 'confirmed' || $st === 'in_consult' ? 'primary' : '')) . '" type="submit" title="' . esc($label, 'attr') . '">'
                            . icon($ic, 'sm') . ($st === 'cancelled' ? '' : esc($label)) . '</button></form> ';
                    }
                    if ($r['rx_id'] && $canRecord) {
                        $out .= '<a class="btn sm" href="' . esc(site_url('clinic/rx/' . $r['rx_id']), 'attr') . '">' . icon('rx', 'sm') . 'Prescription</a>';
                    } elseif ($canRx && $r['patient_id'] && $r['doctor_id'] && $r['status'] !== 'cancelled') {
                        $out .= '<form class="inline" method="post" action="' . esc(site_url('clinic/rx'), 'attr') . '">' . csrf_field()
                            . '<input type="hidden" name="patient_id" value="' . (int) $r['patient_id'] . '"><input type="hidden" name="doctor_id" value="' . (int) $r['doctor_id'] . '"><input type="hidden" name="booking_id" value="' . (int) $r['id'] . '">'
                            . '<button class="btn sm" type="submit">' . icon('notebook-pen', 'sm') . 'Write prescription</button></form>';
                    }

                    return $out;
                }),
            ])->defaultSort('serial', 'asc')->placeholder('Patient, phone, reference…')->unit('appointments')->exportable('appointments-' . $date)
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('calendar') . '</span><b>No appointments on this day</b><span>Add one with “New appointment”, or patients book on your website.</span></div>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $doctors = service('clinicSchedule')->doctors();

        return $this->page('App\Modules\Clinic\Views\appointments', [
            'title' => 'Appointments', 'table' => $table->render(), 'date' => $date, 'doctorId' => $doctorId, 'doctors' => $doctors, 'counts' => $desk->counts($date, $doctorId),
            'scripts' => self::assets(),
        ]);
    }

    /** Slots of a doctor on a date for the appointment form (JSON). */
    public function slots(): ResponseInterface
    {
        ClinicGuard::require('clinic.appointments');
        $r = service('clinicSchedule')->slots($this->getInt('doctor'), $this->getStr('date', 10), 0);

        return $this->json(['on_leave' => $r['on_leave'], 'slots' => array_map(static fn (array $s): array => ['time' => $s['time'], 'starts_at' => $s['starts_at'], 'free' => $s['free'], 'reason' => $s['reason']], $r['slots'])]);
    }

    public function store(): RedirectResponse
    {
        $row = (new AppointmentDesk())->book((array) $this->request->getPost());

        return $this->done('Booked: serial ' . $row['serial_no'] . ' with ' . $row['doctor_name'] . ' at ' . date('h:i A', (int) strtotime((string) $row['starts_at'])) . '.',
            site_url('clinic/appointments') . '?' . http_build_query(['date' => substr((string) $row['starts_at'], 0, 10), 'doctor' => $row['doctor_id']]));
    }

    public function step(int $id): RedirectResponse
    {
        $action = (string) $this->request->getPost('action');
        (new AppointmentDesk())->act($id, $action);

        return $this->done(match ($action) {
            'confirmed' => 'Confirmed — let the patient know by SMS or call.',
            'arrived' => 'Marked as arrived — the patient is in the queue.',
            'in_consult' => 'With the doctor now.',
            'done' => 'Visit completed.',
            'no_show' => 'Marked as no-show.',
            default => 'Appointment cancelled.',
        });
    }

    /** The clinic stylesheet and script (public/assets/pos/clinic.*), cache-busted by file time. */
    public static function assets(): string
    {
        $out = '';
        foreach (['clinic.css' => 'css', 'clinic.js' => 'js'] as $file => $kind) {
            $path = FCPATH . 'assets/pos/' . $file;
            $url = base_url('assets/pos/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : '1');
            $out .= $kind === 'css' ? '<link rel="stylesheet" href="' . esc($url, 'attr') . '">' : '<script src="' . esc($url, 'attr') . '" defer></script>';
        }

        return $out;
    }
}
