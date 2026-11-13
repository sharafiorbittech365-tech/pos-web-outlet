<?php

declare(strict_types=1);

namespace App\Modules\System\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Config\Backup;
use OmniPOS\Core\Services\SystemHealth;

/** GET system/health — database, migrations, backups, disk and cache (HTML, or JSON with ?format=json). */
final class HealthController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $report = (new SystemHealth(config(Backup::class)))->report();
        if ($this->request->getGet('format') === 'json') {
            return $this->response->setStatusCode($report['status'] === 'fail' ? 503 : 200)->setJSON(['data' => $report]);
        }

        return $this->page('App\Modules\System\Views\health', ['title' => 'System health', 'r' => $report]);
    }
}
