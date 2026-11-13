<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\ClinicFiles;
use App\Modules\Clinic\Services\ClinicGuard;
use App\Modules\Clinic\Services\DoctorAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/** Clinic › Setup: pick lists, investigations, prescription pads; clinic images. */
final class SetupController extends BackOfficeController
{
    private function cfg(): ClinicConfig
    {
        ClinicGuard::require('clinic.manage');

        return new ClinicConfig();
    }

    public function index(): string
    {
        $cfg = $this->cfg();
        $doctors = (new DoctorAdmin())->all();
        $scope = $this->getInt('doctor');
        if ($scope > 0 && ! in_array($scope, array_map(static fn (array $d): int => (int) $d['id'], $doctors), true)) {
            $scope = 0;
        }

        return $this->page('App\Modules\Clinic\Views\setup', [
            'title' => 'Clinic setup', 'lists' => $cfg->lists(), 'tests' => $cfg->tests(), 'pad' => $cfg->pad($scope), 'scope' => $scope, 'doctors' => $doctors,
            'scripts' => AppointmentsController::assets(),
        ]);
    }

    public function addList(): RedirectResponse
    {
        $list = (string) $this->request->getPost('list');
        $n = $this->cfg()->addListValues($list, (string) $this->request->getPost('values'));

        return $this->done($n . ' added to ' . strtolower(ClinicConfig::LISTS[$list]) . '.', site_url('clinic/setup') . '#lists');
    }

    public function deleteList(int $id): RedirectResponse
    {
        $this->cfg()->deleteListValue($id);

        return $this->done('Removed.', site_url('clinic/setup') . '#lists');
    }

    public function addTests(): RedirectResponse
    {
        $n = $this->cfg()->addTests((string) $this->request->getPost('category'), (string) $this->request->getPost('names'));

        return $this->done($n . ' test' . ($n === 1 ? '' : 's') . ' added.', site_url('clinic/setup') . '#tests');
    }

    public function toggleTest(int $id): RedirectResponse
    {
        $this->cfg()->toggleTest($id);

        return $this->done('Saved.', site_url('clinic/setup') . '#tests');
    }

    public function savePad(): RedirectResponse
    {
        $doctor = (int) $this->request->getPost('doctor_id');
        $this->cfg()->savePad($doctor, (array) $this->request->getPost(), ['header' => $this->request->getFile('header_image'), 'footer' => $this->request->getFile('footer_image')]);

        return $this->done('Prescription pad saved.', site_url('clinic/setup') . ($doctor > 0 ? '?doctor=' . $doctor : '') . '#pad');
    }

    public function resetPad(): RedirectResponse
    {
        $doctor = (int) $this->request->getPost('doctor_id');
        $this->cfg()->resetPad($doctor);

        return $this->done('This doctor now prints on the clinic\'s pad.', site_url('clinic/setup') . '?doctor=' . $doctor . '#pad');
    }

    /** A clinic image (photo, signature, pad header / footer) for signed-in clinic staff. */
    public function file(int $id): ResponseInterface
    {
        ClinicGuard::assertEnabled();
        $s = $this->s();
        if (! $s->can('clinic.appointments') && ! $s->can('clinic.manage') && ! $s->can('clinic.patients.view')) {
            throw new PermissionDeniedException('Your role cannot open clinic files.');
        }
        $f = ClinicFiles::get($id);

        return $this->response->setContentType($f['mime'])->setBody($f['data'])->setHeader('Cache-Control', 'private, max-age=86400')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
