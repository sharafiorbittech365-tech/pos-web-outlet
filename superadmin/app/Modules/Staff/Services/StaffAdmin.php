<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use App\Libraries\AdminSession;
use App\Libraries\PlatformPermissions;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Platform staff (platform_admins) and their roles (platform_roles). The locked Super Admin role has
 * every permission and cannot be edited. The signed-in staff cache (AdminSession, 60 s) is cleared
 * for everyone a change affects.
 */
final class StaffAdmin
{
    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    public static function listQuery(): BaseBuilder
    {
        return db_connect()->table('platform_admins a')
            ->select('a.id, a.name, a.email, a.is_active, a.last_login_at, a.last_login_ip, a.role_id, a.created_at, r.name AS role_name, r.color AS role_color, r.is_locked')
            ->join('platform_roles r', 'r.id = a.role_id');
    }

    /** @return list<array<string, mixed>> roles with decoded permissions and staff counts */
    public static function roles(): array
    {
        $db = db_connect();
        $counts = array_column($db->table('platform_admins')->select('role_id, COUNT(*) AS n')->groupBy('role_id')->get()->getResultArray(), 'n', 'role_id');
        $roles = $db->table('platform_roles')->orderBy('is_locked', 'desc')->orderBy('id')->get()->getResultArray();
        foreach ($roles as &$r) {
            $r['perms'] = json_decode((string) $r['permissions'], true) ?: [];
            $r['staff'] = (int) ($counts[$r['id']] ?? 0);
        }

        return $roles;
    }

    private function role(BaseConnection $db, int $roleId): array
    {
        return $db->table('platform_roles')->where('id', $roleId)->get()->getRowArray() ?? throw new ValidationException('Choose a role.', ['role_id' => 'Unknown role.']);
    }

    private function emailTaken(BaseConnection $db, string $email, ?int $exceptId): bool
    {
        $b = $db->table('platform_admins')->where('email', $email);
        if ($exceptId !== null) {
            $b->where('id !=', $exceptId);
        }

        return $b->countAllResults() > 0;
    }

    /** Active staff on the locked (Super Admin) role, optionally ignoring one account. */
    private function activeSuperAdmins(BaseConnection $db, ?int $exceptId = null): int
    {
        $b = $db->table('platform_admins a')->join('platform_roles r', 'r.id = a.role_id')->where('r.is_locked', 1)->where('a.is_active', 1);
        if ($exceptId !== null) {
            $b->where('a.id !=', $exceptId);
        }

        return $b->countAllResults();
    }

    /** @param array{name: string, email: string, role_id: int|string, password: string, is_active?: string} $in */
    public function create(array $in): int
    {
        return Tx::run(function (BaseConnection $db) use ($in): int {
            $email = mb_strtolower(trim($in['email']));
            if ($this->emailTaken($db, $email, null)) {
                throw new ValidationException('That email is already a staff account.', ['email' => 'Already used.']);
            }
            $role = $this->role($db, (int) $in['role_id']);
            $now = date('Y-m-d H:i:s');
            $db->table('platform_admins')->insert(['role_id' => (int) $role['id'], 'name' => trim($in['name']), 'email' => $email,
                'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT), 'is_active' => ($in['is_active'] ?? '1') === '1' ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $db->insertID();
            service('audit')->platform('Staff added', trim($in['name']) . ' · ' . $email . ' · ' . $role['name'], null, $this->actor);

            return $id;
        });
    }

    /** @param array{name: string, email: string, role_id: int|string, password?: string, is_active?: string} $in */
    public function update(int $id, array $in): void
    {
        Tx::run(function (BaseConnection $db) use ($id, $in): void {
            $old = $db->table('platform_admins')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Staff member not found.');
            $email = mb_strtolower(trim($in['email']));
            if ($this->emailTaken($db, $email, $id)) {
                throw new ValidationException('That email is already a staff account.', ['email' => 'Already used.']);
            }
            $role = $this->role($db, (int) $in['role_id']);
            $active = ($in['is_active'] ?? '0') === '1';
            $me = ($this->actor['id'] ?? null) === $id;
            if ($me && ! $active) {
                throw new BusinessRuleException('You cannot disable your own account.');
            }
            $oldRole = $this->role($db, (int) $old['role_id']);
            if ((bool) $oldRole['is_locked'] && (bool) $old['is_active'] && (! $active || ! (bool) $role['is_locked']) && $this->activeSuperAdmins($db, $id) === 0) {
                throw new BusinessRuleException('Keep at least one active Super Admin.');
            }
            $row = ['name' => trim($in['name']), 'email' => $email, 'role_id' => (int) $role['id'], 'is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
            if (! empty($in['password'])) {
                $row['password_hash'] = password_hash((string) $in['password'], PASSWORD_DEFAULT);
                $row['failed_logins'] = 0;
                $row['locked_until'] = null;
            }
            $db->table('platform_admins')->where('id', $id)->update($row);
            $changes = [];
            foreach (['name', 'email', 'role_id', 'is_active'] as $k) {
                if ((string) $old[$k] !== (string) $row[$k]) {
                    $changes[] = $k === 'role_id' ? 'role ' . $role['name'] : ($k === 'is_active' ? ($active ? 'enabled' : 'disabled') : $k);
                }
            }
            if (isset($row['password_hash'])) {
                $changes[] = 'password reset';
            }
            service('audit')->platform('Staff changed', $row['name'] . ' · ' . ($changes ? implode(', ', $changes) : 'no change'), null, $this->actor);
        });
        AdminSession::forget($id);
    }

    /** Remove a staff account (audit rows keep the name). */
    public function delete(int $id): string
    {
        $name = Tx::run(function (BaseConnection $db) use ($id): string {
            $a = $db->table('platform_admins')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Staff member not found.');
            if (($this->actor['id'] ?? null) === $id) {
                throw new BusinessRuleException('You cannot delete your own account.');
            }
            $role = $this->role($db, (int) $a['role_id']);
            if ((bool) $role['is_locked'] && (bool) $a['is_active'] && $this->activeSuperAdmins($db, $id) === 0) {
                throw new BusinessRuleException('Keep at least one active Super Admin.');
            }
            $db->table('platform_admins')->where('id', $id)->delete();
            service('audit')->platform('Staff removed', $a['name'] . ' · ' . $a['email'], null, $this->actor);

            return (string) $a['name'];
        });
        AdminSession::forget($id);

        return $name;
    }

    /** @param array{code: string, name: string, color: string} $in */
    public function createRole(array $in): int
    {
        $db = db_connect();
        $code = strtolower(trim($in['code']));
        if ($db->table('platform_roles')->where('code', $code)->countAllResults() > 0) {
            throw new ValidationException('That role code is taken.', ['code' => 'Already used.']);
        }
        $now = date('Y-m-d H:i:s');
        $db->table('platform_roles')->insert(['code' => $code, 'name' => trim($in['name']), 'color' => strtoupper($in['color']), 'is_locked' => 0,
            'permissions' => json_encode(['overview.view']), 'created_at' => $now, 'updated_at' => $now]);
        service('audit')->platform('Role created', trim($in['name']), null, $this->actor);

        return (int) $db->insertID();
    }

    /**
     * Save the permission matrix for every unlocked role.
     *
     * @param array<int|string, list<string>> $matrix role id => permissions
     */
    public function savePermissions(array $matrix): void
    {
        $all = PlatformPermissions::all();
        $affected = Tx::run(function (BaseConnection $db) use ($matrix, $all): array {
            $changed = [];
            foreach (self::roles() as $r) {
                if ((bool) $r['is_locked']) {
                    continue;
                }
                $new = array_values(array_intersect($all, array_map('strval', (array) ($matrix[$r['id']] ?? []))));
                $old = array_values(array_intersect($all, $r['perms']));
                if ($new === $old) {
                    continue;
                }
                $db->table('platform_roles')->where('id', $r['id'])->update(['permissions' => json_encode($new), 'updated_at' => date('Y-m-d H:i:s')]);
                $added = array_diff($new, $old);
                $removed = array_diff($old, $new);
                service('audit')->platform('Role permissions changed', $r['name'] . ($added ? ' · +' . implode(', +', $added) : '') . ($removed ? ' · -' . implode(', -', $removed) : ''), null, $this->actor);
                $changed[] = (int) $r['id'];
            }

            return $changed;
        });
        $this->forgetRoles($affected);
    }

    /** @param array{name: string, color: string} $in */
    public function updateRole(int $id, array $in): void
    {
        $db = db_connect();
        $r = $this->role($db, $id);
        if ((bool) $r['is_locked']) {
            throw new BusinessRuleException('The Super Admin role cannot be edited.');
        }
        $db->table('platform_roles')->where('id', $id)->update(['name' => trim($in['name']), 'color' => strtoupper($in['color']), 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform('Role changed', $r['name'] . ' → ' . trim($in['name']), null, $this->actor);
        $this->forgetRoles([$id]);
    }

    public function deleteRole(int $id): string
    {
        $db = db_connect();
        $r = $this->role($db, $id);
        if ((bool) $r['is_locked']) {
            throw new BusinessRuleException('The Super Admin role cannot be deleted.');
        }
        if ($db->table('platform_admins')->where('role_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('Move the staff on ' . $r['name'] . ' to another role first.');
        }
        $db->table('platform_roles')->where('id', $id)->delete();
        service('audit')->platform('Role deleted', (string) $r['name'], null, $this->actor);

        return (string) $r['name'];
    }

    /** @param list<int> $roleIds */
    private function forgetRoles(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }
        foreach (array_column(db_connect()->table('platform_admins')->select('id')->whereIn('role_id', $roleIds)->get()->getResultArray(), 'id') as $aid) {
            AdminSession::forget((int) $aid);
        }
    }
}
