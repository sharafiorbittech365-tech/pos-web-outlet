<?php

declare(strict_types=1);

namespace App\Modules\Users\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Users\Services\TeamService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class UsersController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = new TeamService();
        $me = $this->s()->userId();
        $table = DataTable::make('team', $this->request)->from($svc->usersQuery())
            ->columns([
                Column::make('name', 'Team member')->sortBy('u.name')->searchIn('u.name', 'u.phone', 'u.email')
                    ->cell(static fn (array $r): string => '<div class="pl-cell"><span class="av" style="--ac:' . css_color($r['color'] ?? '#64748B') . '">' . esc(initials($r['name'])) . '</span><div class="grow"><a class="pname" href="' . esc(site_url('users/' . $r['id']), 'attr') . '">' . esc($r['name']) . '</a>'
                        . ((int) $r['id'] === $me ? ' <span class="tag primary">You</span>' : '') . '<small class="muted">' . esc(implode(' · ', array_filter([$r['phone'], $r['email']])) ?: 'PIN only') . '</small></div></div>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('role', 'Role here')->sortBy('r.name')->cell(static fn (array $r): string => $r['role'] ? '<span class="role-pill" style="--ac:' . css_color($r['color'] ?? '#64748B') . '">' . sym_icon($r['icon'] ?? 'user') . ' ' . esc($r['role']) . '</span>' : '<span class="muted">No access here</span>')
                    ->csv(static fn (array $r): string => (string) ($r['role'] ?? '')),
                Column::make('outlets', 'Outlets')->num()->sortBy('outlets')->cell(static fn (array $r): string => (string) (int) $r['outlets']),
                Column::make('last', 'Last sign-in')->nowrap()->sortBy('u.last_login_at')->cell(static fn (array $r): string => $r['last_login_at'] ? esc(datetime_str($r['last_login_at'])) : '<span class="muted">Never</span>')->csv(static fn (array $r): string => (string) $r['last_login_at']),
                Column::make('status', 'Status')->sortBy('u.is_active')->cell(static fn (array $r): string => ($r['is_owner'] ? tag_html('Owner', 'primary') . ' ' : '') . ($r['is_active'] ? tag_html('Active', 'ok') : tag_html('Disabled')))->csv(static fn (array $r): string => $r['is_active'] ? 'Active' : 'Disabled'),
                Column::make('act', '')->cls('num')->noCsv()->cell(static fn (array $r): string => '<a class="btn sm" href="' . esc(site_url('users/' . $r['id']), 'attr') . '">' . icon('edit', 'sm') . 'Edit</a>'),
            ])->defaultSort('name')->placeholder('Name, phone, email…')->unit('members')->exportable('team');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Users\Views\index', ['title' => 'Users & roles', 'table' => $table->render(), 'counts' => $svc->counts(), 'tab' => 'users']);
    }

    public function create(): string
    {
        return $this->form(null);
    }

    public function edit(int $id): string
    {
        return $this->form((new TeamService())->user($id));
    }

    /** @param array<string, mixed>|null $u */
    private function form(?array $u): string
    {
        $svc = new TeamService();

        return $this->page('App\Modules\Users\Views\form', [
            'title' => $u === null ? 'Add team member' : 'Edit team member',
            'u' => $u,
            'outlets' => $svc->outlets(),
            'roles' => $svc->roles(),
            'counts' => $svc->counts(),
            'me' => $this->s()->userId(),
            'bid' => $this->s()->businessId(),
        ]);
    }

    public function store(): RedirectResponse
    {
        (new TeamService())->saveUser(null, (array) $this->request->getPost());

        return $this->done('Team member added.', site_url('users'));
    }

    public function update(int $id): RedirectResponse
    {
        (new TeamService())->saveUser($id, (array) $this->request->getPost());

        return $this->done('Team member saved.', site_url('users'));
    }

    public function roles(): string
    {
        $svc = new TeamService();
        $roles = $svc->roles();
        $sel = $this->getInt('role');
        $current = null;
        foreach ($roles as $r) {
            if ((int) $r['id'] === $sel) {
                $current = $r;
            }
        }
        $isNew = $this->getStr('new', 1) === '1';
        if (! $isNew) {
            $current ??= $roles[0] ?? null;
        }

        return $this->page('App\Modules\Users\Views\roles', [
            'title' => 'Users & roles', 'tab' => 'roles', 'roles' => $roles, 'role' => $current, 'isNew' => $isNew || $current === null,
            'groups' => config('PosPermissions')->groups, 'all' => count(config('PosPermissions')->all()), 'homes' => TeamService::HOMES, 'icons' => TeamService::ROLE_ICONS,
        ]);
    }

    public function storeRole(): RedirectResponse
    {
        $id = (new TeamService())->saveRole(null, (array) $this->request->getPost());

        return $this->done('Role added.', site_url('users/roles?role=' . $id));
    }

    public function updateRole(int $id): RedirectResponse
    {
        (new TeamService())->saveRole($id, (array) $this->request->getPost());

        return $this->done('Role saved — it applies at the next action of its users.', site_url('users/roles?role=' . $id));
    }

    public function deleteRole(int $id): RedirectResponse
    {
        (new TeamService())->deleteRole($id);

        return $this->done('Role deleted.', site_url('users/roles'));
    }

    public function activity(): string|ResponseInterface
    {
        $svc = new TeamService();
        $all = $this->getStr('scope', 3) === 'all';
        $uid = $this->getInt('user');
        $table = DataTable::make('act', $this->request)->from($svc->activityQuery($all, $uid))->keep(['scope' => $all ? 'all' : '', 'user' => $uid ?: ''])
            ->columns(array_values(array_filter([
                Column::make('at', 'When')->nowrap()->sortBy('a.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at'])))->csv(static fn (array $r): string => (string) $r['created_at']),
                Column::make('user', 'User')->sortBy('a.user_name')->searchIn('a.user_name', 'a.action', 'a.detail')->cell(static fn (array $r): string => '<b>' . esc($r['user_name']) . '</b>')->csv(static fn (array $r): string => (string) $r['user_name']),
                $all ? Column::make('outlet', 'Outlet')->sortBy('b.name')->cell(static fn (array $r): string => esc($r['outlet'] ?? '—')) : null,
                Column::make('action', 'Action')->sortBy('a.action')->cell(static fn (array $r): string => tag_html($r['action'], preg_match('/Void|Return|Refund|deleted|disabled/i', (string) $r['action']) ? 'warn' : (preg_match('/Sign/i', (string) $r['action']) ? '' : 'primary')))->csv(static fn (array $r): string => (string) $r['action']),
                Column::make('detail', 'Details')->cell(static fn (array $r): string => esc((string) $r['detail'])),
                Column::make('ip', 'IP')->cell(static fn (array $r): string => '<small class="muted">' . esc((string) $r['ip_address']) . '</small>'),
            ])))->defaultSort('at', 'desc')->placeholder('User, action, details…')->unit('events')->exportable('activity-log');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Users\Views\activity', ['title' => 'Users & roles', 'tab' => 'log', 'table' => $table->render(), 'all' => $all, 'user' => $uid, 'users' => $svc->userOptions()]);
    }
}
