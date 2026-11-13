<?php

declare(strict_types=1);

namespace App\Modules\Audit\Controllers;

use App\Controllers\BaseController;
use App\Modules\Audit\Services\AuditLog;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class AuditController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $actions = AuditLog::actions();
        $action = $this->pick('action', $actions);
        $tenant = AuditLog::tenant((int) $this->request->getGet('tenant'));
        $table = DataTable::make('audit', $this->request)
            ->from(AuditLog::listQuery($action, $tenant !== null ? (int) $tenant['id'] : null))
            ->keep(['action' => $action, 'tenant' => $tenant ? (string) $tenant['id'] : ''])
            ->columns([
                Column::make('at', 'When')->nowrap()->sortBy('a.id')->cell(static fn (array $r): string => esc(datetime_str($r['created_at'])))->csv(static fn (array $r): string => (string) $r['created_at']),
                Column::make('who', 'Who')->sortBy('a.actor_name')->searchIn('a.actor_name')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['actor_name']) . '</b><br><small class="muted">' . esc($r['actor_role']) . '</small>')
                    ->csv(static fn (array $r): string => $r['actor_name'] . ' (' . $r['actor_role'] . ')'),
                Column::make('action', 'Action')->sortBy('a.action')->searchIn('a.action'),
                Column::make('tenant', 'Tenant')->sortBy('t.name')->searchIn('t.name')
                    ->cell(static fn (array $r): string => $r['tenant_id'] ? '<a class="link" href="' . esc(site_url('audit') . '?tenant=' . (int) $r['tenant_id'], 'attr') . '" title="Only this tenant">' . esc($r['tenant_name'] ?? ('#' . $r['tenant_id'])) . '</a>' : '—')
                    ->csv(static fn (array $r): string => (string) ($r['tenant_name'] ?? '')),
                Column::make('detail', 'Detail')->searchIn('a.detail')->cell(static fn (array $r): string => esc($r['detail'] ?? '')),
                Column::make('ip', 'IP')->cell(static fn (array $r): string => '<small class="muted">' . esc($r['ip_address'] ?? '') . '</small>')->csv(static fn (array $r): string => (string) $r['ip_address']),
            ])
            ->defaultSort('at', 'desc')
            ->perPage(50)
            ->placeholder('Actions, staff, tenants, detail…')
            ->unit('entries')
            ->exportable('platform-audit');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Audit\Views\index', ['title' => 'Audit log', 'table' => $table->render(), 'actionList' => $actions, 'action' => $action, 'tenant' => $tenant]);
    }
}
