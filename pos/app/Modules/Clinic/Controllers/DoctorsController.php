<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Clinic\Services\ClinicGuard;
use App\Modules\Clinic\Services\DoctorAdmin;
use CodeIgniter\HTTP\RedirectResponse;

/** Clinic › Doctors: profiles, weekly sessions, leave dates. */
final class DoctorsController extends BackOfficeController
{
    private function admin(): DoctorAdmin
    {
        ClinicGuard::require('clinic.manage');

        return new DoctorAdmin();
    }

    /** @return array<string, ?\CodeIgniter\HTTP\Files\UploadedFile> */
    private function files(): array
    {
        return ['photo' => $this->request->getFile('photo'), 'signature' => $this->request->getFile('signature')];
    }

    public function index(): string
    {
        $admin = $this->admin();

        return $this->page('App\Modules\Clinic\Views\doctors', ['title' => 'Doctors', 'doctors' => $admin->all(), 'users' => $admin->users(), 'scripts' => AppointmentsController::assets()]);
    }

    public function store(): RedirectResponse
    {
        $id = $this->admin()->save(null, (array) $this->request->getPost(), $this->files());

        return $this->done('Doctor added. Now set the weekly schedule.', site_url('clinic/doctors/' . $id) . '#schedule');
    }

    public function edit(int $id): string
    {
        $admin = $this->admin();
        $d = $admin->find($id);

        return $this->page('App\Modules\Clinic\Views\doctor', ['title' => $d['name'], 'd' => $d, 'users' => $admin->users(), 'scripts' => AppointmentsController::assets()]);
    }

    public function update(int $id): RedirectResponse
    {
        $this->admin()->save($id, (array) $this->request->getPost(), $this->files());

        return $this->done('Profile saved.', site_url('clinic/doctors/' . $id));
    }

    public function delete(int $id): RedirectResponse
    {
        $name = $this->admin()->delete($id);

        return $this->done('Removed ' . $name . '. Past appointments and prescriptions are kept.', site_url('clinic/doctors'));
    }

    public function schedule(int $id): RedirectResponse
    {
        $n = $this->admin()->saveSchedule($id, $this->postArray('sessions'));

        return $this->done('Schedule saved: ' . $n . ' session' . ($n === 1 ? '' : 's') . ' a week.', site_url('clinic/doctors/' . $id) . '#schedule');
    }

    public function addLeave(int $id): RedirectResponse
    {
        $this->admin()->addLeave($id, (array) $this->request->getPost());

        return $this->done('Leave added — those days are closed for booking.', site_url('clinic/doctors/' . $id) . '#leave');
    }

    public function deleteLeave(int $id, int $leaveId): RedirectResponse
    {
        $this->admin()->deleteLeave($id, $leaveId);

        return $this->done('Leave removed.', site_url('clinic/doctors/' . $id) . '#leave');
    }
}
