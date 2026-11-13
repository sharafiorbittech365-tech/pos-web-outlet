<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Controllers;

use App\Controllers\ApiController;
use App\Modules\Clinic\Services\ClinicDirectory;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /v1/doctors, /v1/doctors/{id}, /v1/doctors/{id}/slots, /v1/doctors/{id}/photo (bookings:create) */
final class DoctorsController extends ApiController
{
    private function dir(): ClinicDirectory
    {
        return new ClinicDirectory($this->site(), $this->caller(), service('clinicSchedule'));
    }

    public function index(): ResponseInterface
    {
        $list = $this->dir()->doctors();

        return $this->cached($list, ['total' => count($list)], 60);
    }

    public function show(string $id): ResponseInterface
    {
        // includes open_dates (changes with every booking): not cached
        return $this->ok($this->dir()->doctor((int) $id));
    }

    public function slots(string $id): ResponseInterface
    {
        $q = $this->query();
        $r = $this->dir()->slots((int) $id, (string) ($q['date'] ?? date('Y-m-d')), (string) ($q['all'] ?? '') === '1');

        return $this->ok($r['data'], $r['meta']);
    }

    public function photo(string $id): ResponseInterface
    {
        return $this->cached($this->dir()->photo((int) $id), [], 3600);
    }
}
