<?php

declare(strict_types=1);

namespace App\Modules\Staff\Controllers;

use App\Controllers\BaseController;
use App\Modules\Staff\Services\StaffAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class StaffController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $me = service('adminSession')->actor()['id'];
        $table = DataTable::make('staff', $this->request)
            ->from(StaffAdmin::listQuery())
            ->columns([
                Column::make('name', 'Name')->sortBy('a.name')->searchIn('a.name', 'a.email', 'r.name')
                    ->cell(static fn (array $r): string => '<span class="av xs" style="--ac:' . css_color($r['role_color']) . '">' . esc(initials($r['name'])) . '</span> <b>' . esc($r['name']) . '</b>' . ((int) $r['id'] === $me ? ' ' . tag_html('You', 'primary') : ''))
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('email', 'Email')->sortBy('a.email'),
                Column::make('role', 'Role')->sortBy('r.name')->cell(static fn (array $r): string => '<span class="tag" style="color:' . css_color($r['role_color']) . '">' . esc($r['role_name']) . '</span>')->csv(static fn (array $r): string => (string) $r['role_name']),
                Column::make('status', 'Status')->sortBy('a.is_active')->cell(static fn (array $r): string => (int) $r['is_active'] ? tag_html('Active', 'ok') : tag_html('Disabled'))
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'active' : 'disabled'),
                Column::make('last', 'Last sign-in')->nowrap()->sortBy('a.last_login_at')
                    ->cell(static fn (array $r): string => esc(datetime_str($r['last_login_at'])) . ($r['last_login_ip'] ? '<br><small class="muted">' . esc($r['last_login_ip']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['last_login_at']),
                Column::make('act', '')->num()->noCsv()->cell(static fn (array $r): string => '<button class="btn sm" type="button" data-modal-open="staff-' . (int) $r['id'] . '">' . icon('edit', 'sm') . 'Edit</button>'),
            ])
            ->defaultSort('name')
            ->placeholder('Name, email, role…')
            ->unit('staff')
            ->exportable('platform-staff');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Staff\Views\index', [
            'title' => 'Staff & roles', 'tab' => 'staff', 'table' => $table->render(), 'rows' => $table->result()['rows'], 'roles' => StaffAdmin::roles(),
        ]);
    }

    public function roles(): string
    {
        return $this->page('App\Modules\Staff\Views\index', ['title' => 'Staff & roles', 'tab' => 'roles', 'roles' => StaffAdmin::roles()]);
    }

    public function create(): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[100]',
            'email' => 'required|valid_email|max_length[160]',
            'role_id' => 'required|is_natural_no_zero',
            'password' => 'required|min_length[10]|max_length[200]',
            'is_active' => 'permit_empty|in_list[0,1]',
        ]);
        (new StaffAdmin($this->actor()))->create($data);

        return $this->done('Staff member added. Share the password over a secure channel.', site_url('staff'));
    }

    public function update(int $id): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[100]',
            'email' => 'required|valid_email|max_length[160]',
            'role_id' => 'required|is_natural_no_zero',
            'password' => 'permit_empty|min_length[10]|max_length[200]',
            'is_active' => 'permit_empty|in_list[0,1]',
        ]);
        (new StaffAdmin($this->actor()))->update($id, $data);

        return $this->done('Staff member saved.', site_url('staff'));
    }

    public function delete(int $id): RedirectResponse
    {
        $name = (new StaffAdmin($this->actor()))->delete($id);

        return $this->done($name . ' removed.', site_url('staff'));
    }

    public function createRole(): RedirectResponse
    {
        $data = $this->validated(['code' => 'required|alpha_dash|max_length[32]', 'name' => 'required|max_length[60]', 'color' => 'required|regex_match[/^#[0-9A-Fa-f]{6}$/]']);
        (new StaffAdmin($this->actor()))->createRole($data);

        return $this->done('Role created. Tick its permissions below.', site_url('staff/roles'));
    }

    public function updateRole(int $id): RedirectResponse
    {
        $data = $this->validated(['name' => 'required|max_length[60]', 'color' => 'required|regex_match[/^#[0-9A-Fa-f]{6}$/]']);
        (new StaffAdmin($this->actor()))->updateRole($id, $data);

        return $this->done('Role saved.', site_url('staff/roles'));
    }

    public function permissions(): RedirectResponse
    {
        $this->validated(['perm' => 'permit_empty']);
        (new StaffAdmin($this->actor()))->savePermissions((array) ($this->request->getPost('perm') ?? []));

        return $this->done('Permissions saved. They apply within a minute.', site_url('staff/roles'));
    }

    public function deleteRole(int $id): RedirectResponse
    {
        $name = (new StaffAdmin($this->actor()))->deleteRole($id);

        return $this->done('Role ' . $name . ' deleted.', site_url('staff/roles'));
    }
}
