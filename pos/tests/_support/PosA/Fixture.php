<?php

declare(strict_types=1);

namespace Tests\Support\PosA;

use OmniPOS\Core\Services\OutletProvisioner;

/**
 * A fresh workspace per test for the POS-A modules: tenant (business plan, active), POS server
 * assignment, the role set of the business type, owner (password + PIN 1111), manager (PIN 2222)
 * and cashier (PIN 3333), and one outlet built from the business-type template (products, customers,
 * tables …) — the same path as self sign-up.
 */
final class Fixture
{
    public const PASSWORD = 'secret123';

    /** Id of a POS server by code; a server other than the seeded pos-1 is created for the test (not taking new sign-ups). */
    public static function server(string $code): int
    {
        $db = db_connect();
        $id = $db->table('pos_servers')->select('id')->where('code', $code)->get()->getRow('id');
        if ($id === null) {
            $now = date('Y-m-d H:i:s');
            $db->table('pos_servers')->insert(['code' => $code, 'name' => strtoupper($code), 'base_url' => 'http://localhost:8092/', 'region' => 'dhaka',
                'capacity' => 500, 'status' => 'active', 'accepts_new' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $id = $db->insertID();
        }

        return (int) $id;
    }

    /**
     * @return array{tenant_id: int, business_id: int, users: array<string, int>, roles: array<string, int>, email: string}
     */
    public static function workspace(string $type = 'grocery', string $server = 'pos-1'): array
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $u = substr(bin2hex(random_bytes(5)), 0, 8);
        $email = 'owner' . $u . '@example.test';
        $plan = $db->table('plans')->where('code', 'business')->get()->getRowArray();
        $db->table('tenants')->insert(['code' => 't_' . $u, 'name' => 'Test Co ' . $u, 'owner_name' => 'Owner ' . $u, 'owner_email' => $email,
            'business_type' => $type, 'created_at' => $now, 'updated_at' => $now]);
        $tid = (int) $db->insertID();
        $db->table('subscriptions')->insert(['tenant_id' => $tid, 'plan_id' => $plan['id'], 'billing_cycle' => 'monthly', 'status' => 'active',
            'period_start' => $now, 'period_end' => date('Y-m-d H:i:s', strtotime('+1 month')), 'created_at' => $now, 'updated_at' => $now]);
        $db->table('tenant_server_assignments')->insert(['tenant_id' => $tid, 'server_id' => self::server($server), 'assigned_at' => $now]);
        // every module on (kitchen, floor, register, customers …) regardless of the plan
        foreach (['customers', 'register', 'kitchen', 'floor', 'expenses'] as $m) {
            $db->table('tenant_module_overrides')->insert(['tenant_id' => $tid, 'module_code' => $m, 'is_enabled' => 1, 'updated_at' => $now]);
        }

        $lib = (new OutletProvisioner())->roleLibrary();
        $roles = [];
        foreach (['owner', 'manager', 'cashier'] as $rc) {
            $r = $lib['roles'][$rc];
            $db->table('roles')->insert(['tenant_id' => $tid, 'code' => $rc, 'name' => $r['name'], 'color' => $r['color'], 'icon' => $r['icon'], 'home' => $r['home'],
                'max_discount' => $r['maxDiscount'], 'is_locked' => $rc === 'owner' ? 1 : 0, 'permissions' => json_encode($r['perms']), 'created_at' => $now, 'updated_at' => $now]);
            $roles[$rc] = (int) $db->insertID();
        }
        $users = [];
        foreach (['owner' => '1111', 'manager' => '2222', 'cashier' => '3333'] as $rc => $pin) {
            $db->table('users')->insert(['tenant_id' => $tid, 'name' => ucfirst($rc) . ' ' . $u, 'email' => $rc === 'owner' ? $email : null,
                'password_hash' => $rc === 'owner' ? password_hash(self::PASSWORD, PASSWORD_DEFAULT) : null, 'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
                'is_owner' => $rc === 'owner' ? 1 : 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $users[$rc] = (int) $db->insertID();
        }
        $bid = (new OutletProvisioner())->create($tid, $type, ['name' => 'Test Outlet ' . $u], array_combine(array_values($users), array_values($roles)));
        service('moduleAccess')->invalidate($tid);

        return ['tenant_id' => $tid, 'business_id' => $bid, 'users' => $users, 'roles' => $roles, 'email' => $email];
    }
}
