<?php

declare(strict_types=1);

namespace App\Modules\Overview\Controllers;

use App\Controllers\BaseController;
use App\Modules\Overview\Services\OverviewStats;

final class OverviewController extends BaseController
{
    public function index(): string
    {
        $stats = new OverviewStats();
        $session = service('adminSession');

        return $this->page('App\Modules\Overview\Views\index', [
            'title' => 'Overview',
            'kpi' => $stats->kpis(),
            'revenue' => $stats->revenue(),
            'attention' => $stats->attention(),
            'newest' => $stats->newest(),
            'servers' => service('servers')->overview(),
            'audit' => $session->can('audit.view') ? $stats->recentAudit() : [],
        ]);
    }
}
