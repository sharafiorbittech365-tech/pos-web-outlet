<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Libraries\PosSession;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Staff of the account (users) with a role per outlet (user_businesses), roles with their
 * permission sets, and the activity log. Enforces the plan's user limit and the owner rules:
 * the owner stays active with the owner role everywhere; owner roles (is_locked) cannot be edited.
 */
final class TeamService
{
    public const HOMES = ['dashboard' => 'Dashboard', 'pos' => 'POS', 'orders' => 'Orders', 'kitchen' => 'Kitchen', 'floor' => 'Floor', 'tasks' => 'Tasks', 'inventory' => 'Inventory', 'customers' => 'Customers', 'reports' => 'Reports'];
    public const ROLE_ICONS = ['crown', 'compass', 'banknote', 'handshake', 'concierge-bell', 'chef-hat', 'pill', 'package', 'wrench', 'scissors', 'user', 'users', 'shield', 'star', 'truck', 'briefcase'];

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function tid(): int
    {
        return service('tenant')->tenantId();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    /* ------------------------------------------------------------------ users */

    public function usersQuery(): BaseBuilder
    {
        return $this->db()->table('users u')
            ->select('u.id, u.name, u.email, u.phone, u.is_owner, u.is_active, u.last_login_at, r.name AS role, r.color, r.icon,
                      (SELECT COUNT(*) FROM user_businesses x WHERE x.user_id = u.id) AS outlets')
            ->join('user_businesses ub', 'ub.user_id = u.id AND ub.business_id = ' . $this->bid(), 'left')
            ->join('roles r', 'r.id = ub.role_id', 'left')
            ->where('u.tenant_id', $this->tid());
    }

    /** @return array{active: int, limit: int, total: int} */
    public function counts(): array
    {
        $db = $this->db();
        $access = service('posSession')->access();

        return ['active' => $db->table('users')->where('tenant_id', $this->tid())->where('is_active', 1)->countAllResults(), 'limit' => $access->limitUsers,
            'total' => $db->table('users')->where('tenant_id', $this->tid())->countAllResults()];
    }

    /** @return array<string, mixed> user with roles per outlet [business_id => role_id] */
    public function user(int $id): array
    {
        $u = $this->db()->table('users')->select('id, name, email, phone, is_owner, is_active, last_login_at, created_at')->where('id', $id)->where('tenant_id', $this->tid())->get()->getRowArray()
            ?? throw new NotFoundException('User not found.');
        $u['roles'] = array_map('intval', array_column($this->db()->table('user_businesses')->select('business_id, role_id')->where('user_id', $id)->get()->getResultArray(), 'role_id', 'business_id'));

        return $u;
    }

    /** @return list<array{id: int, name: string}> outlets of the account */
    public function outlets(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
            $this->db()->table('businesses')->select('id, name')->where('tenant_id', $this->tid())->where('is_active', 1)->orderBy('id')->get()->getResultArray());
    }

    /**
     * Create or update a team member.
     *
     * @param array<string, mixed> $in name, phone, email, pin, password, is_active, roles[business_id] => role_id (0 = no access)
     */
    public function saveUser(?int $id, array $in): int
    {
        $db = $this->db();
        $tid = $this->tid();
        $before = $id !== null ? $this->user($id) : null;
        $isOwner = (bool) ($before['is_owner'] ?? false);
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 100);
        if ($name === '') {
            $errors['name'] = 'Full name is required.';
        }
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email or leave it empty.';
        } elseif ($email !== '') {
            $q = $db->table('users')->where('email', $email);
            if ($id !== null) {
                $q->where('id <>', $id);
            }
            if ($q->countAllResults() > 0) {
                $errors['email'] = 'This email is already used by another account.';
            }
        }
        if ($isOwner && $email === '') {
            $errors['email'] = 'The owner signs in with this email — it cannot be empty.';
        }
        $pin = trim((string) ($in['pin'] ?? ''));
        if ($pin !== '' && ! preg_match('/^\d{4}$/', $pin)) {
            $errors['pin'] = 'The PIN is exactly 4 digits.';
        } elseif ($pin === '' && $id === null) {
            $errors['pin'] = 'Give the new member a 4-digit PIN.';
        } elseif ($pin !== '' && $this->pinTaken($pin, $id)) {
            // the PIN identifies the person at the till ("switch user"), so it must be unique
            $errors['pin'] = 'Another team member already uses this PIN.';
        }
        $password = (string) ($in['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < 8) {
            $errors['password'] = 'At least 8 characters.';
        }
        if ($password !== '' && $email === '') {
            $errors['password'] = 'A back-office password needs an email to sign in with.';
        }
        $active = $isOwner || ! empty($in['is_active']);
        if ($id !== null && $id === service('tenant')->userId() && ! $active) {
            $errors['is_active'] = 'You cannot disable yourself.';
        }
        // roles per outlet (only outlets & roles of this account)
        $outletIds = array_column($this->outlets(), 'id');
        $roleIds = array_map('intval', array_column($db->table('roles')->select('id')->where('tenant_id', $tid)->get()->getResultArray(), 'id'));
        $roles = [];
        foreach ((array) ($in['roles'] ?? []) as $bid => $rid) {
            $bid = (int) $bid;
            $rid = (int) $rid;
            if ($rid > 0 && in_array($bid, $outletIds, true) && in_array($rid, $roleIds, true)) {
                $roles[$bid] = $rid;
            }
        }
        if ($isOwner) {
            $ownerRole = (int) ($db->table('roles')->select('id')->where('tenant_id', $tid)->where('is_locked', 1)->orderBy('id')->limit(1)->get()->getRow('id') ?? 0);
            $roles = array_fill_keys($outletIds, $ownerRole);
        }
        if ($roles === []) {
            $errors['roles'] = 'Give access to at least one outlet.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }
        // plan user limit: a new active member, or re-activating one, needs a free seat
        $wasActive = (bool) ($before['is_active'] ?? false);
        if ($active && ! $wasActive) {
            $count = $db->table('users')->where('tenant_id', $tid)->where('is_active', 1)->countAllResults();
            if (! service('posSession')->access()->withinUsers($count)) {
                throw new BusinessRuleException('Your plan allows ' . service('posSession')->access()->limitUsers . ' active users. Upgrade the plan or disable a user first.');
            }
        }

        $uid = Tx::run(function (BaseConnection $db) use ($id, $name, $email, $pin, $password, $active, $roles, $tid, $in): int {
            $now = date('Y-m-d H:i:s');
            $row = ['name' => $name, 'email' => $email !== '' ? $email : null, 'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 32) ?: null, 'is_active' => $active ? 1 : 0, 'updated_at' => $now];
            if ($pin !== '') {
                $row['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
            }
            if ($password !== '') {
                $row['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($id === null) {
                $db->table('users')->insert($row + ['tenant_id' => $tid, 'is_owner' => 0, 'created_at' => $now]);
                $uid = (int) $db->insertID();
            } else {
                $db->table('users')->where('id', $id)->where('tenant_id', $tid)->update($row);
                $uid = $id;
            }
            $db->table('user_businesses')->where('user_id', $uid)->delete();
            $db->table('user_businesses')->insertBatch(array_map(static fn (int $bid, int $rid): array => ['user_id' => $uid, 'business_id' => $bid, 'role_id' => $rid], array_keys($roles), $roles));

            return $uid;
        });
        foreach (array_unique(array_merge(array_keys($roles), array_keys($before['roles'] ?? []))) as $bid) {
            PosSession::forgetUser($uid, (int) $bid);
        }
        service('audit')->activity($id === null ? 'User added' : 'User updated', $name . ($active ? '' : ' · disabled'));
        service('usage')->refresh($tid);

        return $uid;
    }

    private function pinTaken(string $pin, ?int $exceptId): bool
    {
        $q = $this->db()->table('users')->select('id, pin_hash')->where('tenant_id', $this->tid())->where('is_active', 1)->where('pin_hash IS NOT NULL', null, false);
        if ($exceptId !== null) {
            $q->where('id <>', $exceptId);
        }
        foreach ($q->limit(500)->get()->getResultArray() as $u) {
            if (password_verify($pin, (string) $u['pin_hash'])) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ roles */

    /** @return list<array<string, mixed>> roles with permission lists and user counts (this outlet) */
    public function roles(): array
    {
        $rows = $this->db()->table('roles r')->select('r.*, (SELECT COUNT(*) FROM user_businesses ub WHERE ub.role_id = r.id) AS users')
            ->where('r.tenant_id', $this->tid())->orderBy('r.is_locked', 'DESC')->orderBy('r.id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['perms'] = json_decode((string) $r['permissions'], true) ?: [];
        }

        return $rows;
    }

    /** @param array<string, mixed> $in name, color, icon, home, max_discount, perms[] */
    public function saveRole(?int $id, array $in): int
    {
        $db = $this->db();
        $tid = $this->tid();
        if ($id !== null) {
            $role = $db->table('roles')->where('id', $id)->where('tenant_id', $tid)->get()->getRowArray() ?? throw new NotFoundException('Role not found.');
            if ((bool) $role['is_locked']) {
                throw new BusinessRuleException('The owner role always has full access and cannot be changed.');
            }
        }
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
        if ($name === '') {
            throw new ValidationException('Enter the role name.', ['name' => 'Role name is required.']);
        }
        $dup = $db->table('roles')->where('tenant_id', $tid)->where('name', $name);
        if ($id !== null) {
            $dup->where('id <>', $id);
        }
        if ($dup->countAllResults() > 0) {
            throw new ValidationException('A role with this name exists.', ['name' => 'Already exists.']);
        }
        $all = config('PosPermissions')->all();
        $perms = array_values(array_intersect($all, array_map('strval', (array) ($in['perms'] ?? []))));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['color'] ?? '')) ? strtoupper((string) $in['color']) : '#64748B';
        $row = ['name' => $name, 'color' => $color, 'icon' => in_array($in['icon'] ?? '', self::ROLE_ICONS, true) ? $in['icon'] : 'user',
            'home' => array_key_exists((string) ($in['home'] ?? ''), self::HOMES) ? $in['home'] : 'pos', 'max_discount' => max(0, min(100, round((float) ($in['max_discount'] ?? 0), 2))),
            'permissions' => json_encode($perms), 'updated_at' => date('Y-m-d H:i:s')];
        if ($id === null) {
            $db->table('roles')->insert($row + ['tenant_id' => $tid, 'is_locked' => 0, 'code' => null, 'created_at' => date('Y-m-d H:i:s')]);
            $id = (int) $db->insertID();
            service('audit')->activity('Role added', $name);
        } else {
            $db->table('roles')->where('id', $id)->where('tenant_id', $tid)->update($row);
            service('audit')->activity('Role updated', $name . ' · ' . count($perms) . ' permissions');
            $this->forgetRole($id);
        }

        return $id;
    }

    public function deleteRole(int $id): void
    {
        $db = $this->db();
        $role = $db->table('roles')->where('id', $id)->where('tenant_id', $this->tid())->get()->getRowArray() ?? throw new NotFoundException('Role not found.');
        if ((bool) $role['is_locked']) {
            throw new BusinessRuleException('The owner role cannot be deleted.');
        }
        if ($db->table('user_businesses')->where('role_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('Move the users of “' . $role['name'] . '” to another role first.');
        }
        $db->table('roles')->where('id', $id)->where('tenant_id', $this->tid())->delete();
        service('audit')->activity('Role deleted', (string) $role['name']);
    }

    private function forgetRole(int $roleId): void
    {
        foreach ($this->db()->table('user_businesses')->select('user_id, business_id')->where('role_id', $roleId)->get()->getResultArray() as $ub) {
            PosSession::forgetUser((int) $ub['user_id'], (int) $ub['business_id']);
        }
    }

    /* ------------------------------------------------------------------ activity log */

    public function activityQuery(bool $allOutlets, int $userId): BaseBuilder
    {
        $b = $this->db()->table('activity_log a')->select('a.id, a.created_at, a.user_name, a.user_id, a.action, a.detail, a.ip_address, a.business_id, b.name AS outlet')
            ->join('businesses b', 'b.id = a.business_id', 'left')
            ->where('a.tenant_id', $this->tid());
        if (! $allOutlets) {
            $b->where('a.business_id', $this->bid());
        }
        if ($userId > 0) {
            $b->where('a.user_id', $userId);
        }

        return $b;
    }

    /** @return list<array{id: int, name: string}> */
    public function userOptions(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
            $this->db()->table('users')->select('id, name')->where('tenant_id', $this->tid())->orderBy('name')->get()->getResultArray());
    }
}
