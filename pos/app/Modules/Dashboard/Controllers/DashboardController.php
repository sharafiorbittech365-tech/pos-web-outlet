<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Controllers\BaseController;
use App\Modules\Dashboard\Services\DashboardStats;

final class DashboardController extends BaseController
{
    public function index(): string
    {
        $s = service('posSession');
        $scopeAll = $s->can('orders.all') || $s->can('reports.view');
        $stats = new DashboardStats($s->businessId(), $scopeAll ? null : $s->userId());
        $b = $s->business();

        return $this->page('App\Modules\Dashboard\Views\index', [
            'title' => 'Dashboard',
            'scopeAll' => $scopeAll,
            'sum' => $stats->summary($b),
            'days' => $stats->days(),
            'top' => $stats->topProducts(),
            'recent' => $stats->recent(),
            'user' => $s->user(),
            'business' => $b,
            'cur' => $s->currency(),
        ]);
    }
}
