<?php

declare(strict_types=1);

namespace App\Modules\Servers\Controllers;

use App\Controllers\BaseController;
use App\Modules\Servers\Services\ServerAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class ServersController extends BaseController
{
    private const RULES = [
        'name' => 'required|max_length[80]',
        'base_url' => 'required|valid_url_strict|max_length[255]',
        'region' => 'required|alpha_dash|max_length[48]',
        'capacity' => 'required|is_natural_no_zero|less_than_equal_to[100000]',
        'status' => 'required|in_list[active,draining,maintenance,offline]',
        'accepts_new' => 'permit_empty|in_list[0,1]',
    ];

    public function index(): string|ResponseInterface
    {
        $table = DataTable::make('servers', $this->request)
            ->from(ServerAdmin::listQuery())
            ->columns([
                Column::make('name', 'Server')->sortBy('s.name')->searchIn('s.name', 's.code', 's.region', 's.base_url')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('servers/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted"><code>' . esc($r['code']) . '</code> · ' . esc($r['base_url']) . '</small>')
                    ->csv(static fn (array $r): string => $r['code'] . ' ' . $r['name']),
                Column::make('region', 'Region')->sortBy('s.region'),
                Column::make('status', 'Status')->sortBy('s.status')
                    ->cell(static fn (array $r): string => status_tag($r['status']) . ($r['accepts_new'] ? '' : ' ' . tag_html('No new tenants')))
                    ->csv(static fn (array $r): string => $r['status'] . ($r['accepts_new'] ? '' : ' (closed)')),
                Column::make('health', 'Health')->sortBy('s.last_heartbeat_at')
                    ->cell(static fn (array $r): string => (ServerAdmin::isOnline($r['last_heartbeat_at']) ? tag_html('Online', 'ok') : tag_html('Offline', 'danger'))
                        . '<br><small class="muted">' . ($r['last_heartbeat_at'] ? 'seen ' . esc(datetime_str($r['last_heartbeat_at'])) : 'no heartbeat yet') . '</small>')
                    ->csv(static fn (array $r): string => ServerAdmin::isOnline($r['last_heartbeat_at']) ? 'online' : 'offline'),
                Column::make('tenants', 'Tenants')->num()->sortBy('tenants')
                    ->cell(static fn (array $r): string => '<b>' . (int) $r['tenants'] . '</b><small class="muted">/' . number_format((int) $r['capacity']) . '</small>')
                    ->csv(static fn (array $r): string => (string) (int) $r['tenants']),
                Column::make('load', 'Load')->sortBy('load_ratio')->width('160px')
                    ->cell(static function (array $r): string {
                        $pct = (int) $r['capacity'] > 0 ? round((int) $r['tenants'] / (int) $r['capacity'] * 100) : 0;

                        return '<div class="meter ' . ($pct >= 90 ? 'over' : ($pct >= 75 ? 'near' : '')) . '"><div class="meter-h"><span class="grow"></span><b>' . $pct . '%</b></div><div class="meter-bar"><i style="width:' . max(2, min(100, $pct)) . '%"></i></div></div>';
                    })
                    ->csv(static fn (array $r): string => (string) ((int) $r['capacity'] > 0 ? round((int) $r['tenants'] / (int) $r['capacity'] * 100) : 0)),
                Column::make('cpu', 'CPU / RPM')->num()->sortBy('s.cpu_load')
                    ->cell(static fn (array $r): string => ($r['cpu_load'] !== null ? esc((string) round((float) $r['cpu_load'])) . '%' : '—') . '<br><small class="muted">' . ($r['requests_per_min'] !== null ? number_format((int) $r['requests_per_min']) . ' rpm' : '') . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['cpu_load']),
                Column::make('version', 'Version')->sortBy('s.app_version')->cell(static fn (array $r): string => esc($r['app_version'] ?? '—'))->csv(static fn (array $r): string => (string) $r['app_version']),
            ])
            ->defaultSort('name')
            ->placeholder('Server, code, region…')
            ->unit('servers')
            ->exportable('pos-servers');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $all = service('servers')->overview();

        return $this->page('App\Modules\Servers\Views\index', ['title' => 'POS servers', 'table' => $table->render(), 'servers' => $all, 'unassigned' => ServerAdmin::unassignedCount()]);
    }

    public function new(): string
    {
        return $this->page('App\Modules\Servers\Views\form', ['title' => 'New POS server', 'back' => [site_url('servers'), 'POS servers'], 'server' => null]);
    }

    public function create(): RedirectResponse
    {
        $data = $this->validated(['code' => 'required|alpha_dash|max_length[32]'] + self::RULES);
        $id = (new ServerAdmin($this->actor()))->create($data);

        return $this->done('POS server added. Set omnipos.serverCode = ' . strtolower($data['code']) . ' in that server\'s .env.', site_url('servers/' . $id));
    }

    public function show(int $id): string|ResponseInterface
    {
        $srv = ServerAdmin::find($id);
        $others = array_values(array_filter(ServerAdmin::options(), static fn (array $s): bool => $s['id'] !== $id));
        $tenants = DataTable::make('st', $this->request)
            ->from(ServerAdmin::tenantsQuery($id))
            ->columns([
                Column::make('pick', '')->width('36px')->noCsv()
                    ->cell(static fn (array $r): string => '<input type="checkbox" name="tenant_ids[]" value="' . (int) $r['id'] . '" form="bulk-move" aria-label="Select ' . esc($r['name'], 'attr') . '">'),
                Column::make('name', 'Account')->sortBy('t.name')->searchIn('t.name', 't.owner_name')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['id'] . '?tab=server'), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted">' . esc($r['owner_name']) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('plan', 'Plan')->sortBy('p.name')->cell(static fn (array $r): string => esc($r['plan_name']) . ' ' . status_tag($r['status']))->csv(static fn (array $r): string => (string) $r['plan_name']),
                Column::make('outlets', 'Outlets')->num()->sortBy('u.outlets')->cell(static fn (array $r): string => (string) (int) $r['outlets']),
                Column::make('orders', 'Orders / month')->num()->sortBy('u.orders_month')->cell(static fn (array $r): string => number_format((int) $r['orders_month']))->csv(static fn (array $r): string => (string) (int) $r['orders_month']),
                Column::make('since', 'On this server since')->nowrap()->sortBy('a.assigned_at')
                    ->cell(static fn (array $r): string => esc(date_str($r['assigned_at'])) . ($r['reason'] ? '<br><small class="muted">' . esc($r['reason']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['assigned_at']),
            ])
            ->defaultSort('name')
            ->placeholder('Account or owner…')
            ->unit('tenants')
            ->exportable('server-tenants');
        if ($csv = $tenants->csvResponse($this->response)) {
            return $csv;
        }
        $history = DataTable::make('sh', $this->request)
            ->from(ServerAdmin::historyQuery($id))
            ->columns([
                Column::make('at', 'When')->nowrap()->sortBy('h.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at']))),
                Column::make('tenant', 'Account')->sortBy('t.name')->searchIn('t.name', 'h.reason', 'h.actor_name')
                    ->cell(static fn (array $r): string => $r['tenant_name'] ? '<a class="link" href="' . esc(site_url('tenants/' . $r['tenant_id']), 'attr') . '">' . esc($r['tenant_name']) . '</a>' : '#' . (int) $r['tenant_id']),
                Column::make('move', 'Move')->cell(static fn (array $r): string => esc($r['from_name'] ?? 'New') . ' → ' . esc($r['to_name'] ?? '?') . ' ' . ((int) $r['to_server_id'] === $id ? tag_html('In', 'ok') : tag_html('Out', 'warn'))),
                Column::make('by', 'By')->sortBy('h.actor_name')->cell(static fn (array $r): string => esc($r['actor_name'])),
                Column::make('reason', 'Reason')->cell(static fn (array $r): string => esc($r['reason'] ?? '')),
            ])
            ->defaultSort('at', 'desc')
            ->perPage(10)
            ->compact()
            ->placeholder('Account, reason, staff…')
            ->unit('moves');

        return $this->page('App\Modules\Servers\Views\show', [
            'title' => $srv['name'], 'back' => [site_url('servers'), 'POS servers'], 'server' => $srv, 'others' => $others,
            'tenants' => $tenants->render(), 'history' => $history->render(), 'beats' => ServerAdmin::heartbeats($id),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $data = $this->validated(self::RULES);
        (new ServerAdmin($this->actor()))->update($id, $data);

        return $this->done('Server saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        $name = (new ServerAdmin($this->actor()))->delete($id);

        return $this->done($name . ' removed.', site_url('servers'));
    }

    public function drain(int $id): RedirectResponse
    {
        $data = $this->validated([
            'to' => 'required|is_natural_no_zero',
            'reason' => 'required|max_length[160]',
            'scope' => 'required|in_list[all,selected]',
            'tenant_ids' => 'permit_empty',
            'tenant_ids.*' => 'permit_empty|is_natural_no_zero',
        ]);
        $ids = $data['scope'] === 'selected' ? array_values(array_map('intval', (array) ($this->request->getPost('tenant_ids') ?? []))) : null;
        $n = (new ServerAdmin($this->actor()))->drain($id, (int) $data['to'], trim($data['reason']), $ids);

        return $this->done($n . ' tenant(s) moved. They are served by the new server from their next request — no data was moved.');
    }
}
