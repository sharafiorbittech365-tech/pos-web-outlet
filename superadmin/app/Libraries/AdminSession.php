<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * The signed-in platform staff member and their role (RBAC).
 * Registered as service('adminSession') in app/Config/Services.php.
 */
final class AdminSession
{
    /** @var array<string, mixed>|false|null */
    private array|false|null $admin = null;

    /** @return array{id:int,name:string,email:string,role:string,role_code:string,color:string,locked:bool,perms:list<string>}|null */
    public function current(): ?array
    {
        if ($this->admin !== null) {
            return $this->admin ?: null;
        }
        $id = (int) (session('admin_id') ?? 0);
        if ($id === 0) {
            return null;
        }
        $key = 'omnipos_admin_' . $id;
        $row = cache($key);
        if ($row === null) {
            $r = db_connect()->table('platform_admins a')
                ->select('a.id, a.name, a.email, a.is_active, r.code AS role_code, r.name AS role, r.color, r.is_locked, r.permissions')
                ->join('platform_roles r', 'r.id = a.role_id')->where('a.id', $id)->get()->getRowArray();
            $row = $r && (bool) $r['is_active'] ? [
                'id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'role' => $r['role'], 'role_code' => $r['role_code'],
                'color' => $r['color'], 'locked' => (bool) $r['is_locked'], 'perms' => json_decode((string) $r['permissions'], true) ?: [],
            ] : false;
            cache()->save($key, $row, 60);
        }
        $this->admin = $row;

        return $row ?: null;
    }

    public function can(string $perm): bool
    {
        $a = $this->current();

        return $a !== null && ($a['locked'] || in_array($perm, $a['perms'], true));
    }

    /** @return array{name: string, role: string, id: int|null} for audit rows */
    public function actor(): array
    {
        $a = $this->current();

        return ['name' => $a['name'] ?? 'Platform', 'role' => $a['role'] ?? 'Staff', 'id' => $a['id'] ?? null];
    }

    public static function forget(int $adminId): void
    {
        cache()->delete('omnipos_admin_' . $adminId);
    }
}
