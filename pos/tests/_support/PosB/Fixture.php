<?php

declare(strict_types=1);

namespace Tests\Support\PosB;

/**
 * A small, self-contained workspace for back-office tests: tenant + subscription + POS server
 * assignment (pos-1) + one outlet + roles (owner, cashier) + users + one category.
 */
final class Fixture
{
    /**
     * @param array{plan?: string, status?: string, type?: string, limit_users?: int|null, limit_outlets?: int|null, addons?: list<string>, stock?: bool} $o
     * @return array{tenant_id: int, business_id: int, users: array<string, int>, roles: array<string, int>, category_id: int, plan_id: int}
     */
    public static function workspace(array $o = []): array
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $u = substr(bin2hex(random_bytes(5)), 0, 8);
        $type = $o['type'] ?? 'electronics';
        $plan = $db->table('plans')->where('code', $o['plan'] ?? 'business')->get()->getRowArray();
        $db->table('tenants')->insert(['code' => 't_' . $u, 'name' => 'Test Co ' . $u, 'owner_name' => 'Owner ' . $u, 'owner_email' => 'owner' . $u . '@example.test',
            'business_type' => $type, 'limit_users' => $o['limit_users'] ?? null, 'limit_outlets' => $o['limit_outlets'] ?? null, 'created_at' => $now, 'updated_at' => $now]);
        $tid = (int) $db->insertID();
        $status = $o['status'] ?? 'active';
        $db->table('subscriptions')->insert(['tenant_id' => $tid, 'plan_id' => $plan['id'], 'billing_cycle' => 'monthly', 'status' => $status,
            'trial_ends_at' => $status === 'trial' ? date('Y-m-d H:i:s', strtotime('+10 days')) : null,
            'period_start' => $status === 'trial' ? null : date('Y-m-d H:i:s', strtotime('-5 days')), 'period_end' => $status === 'trial' ? null : date('Y-m-d H:i:s', strtotime('+25 days')),
            'created_at' => $now, 'updated_at' => $now]);
        foreach ($o['addons'] ?? [] as $m) {
            $db->table('tenant_addons')->insert(['tenant_id' => $tid, 'module_code' => $m, 'added_at' => $now]);
        }
        $srv = (int) $db->table('pos_servers')->select('id')->where('code', 'pos-1')->get()->getRow('id');
        $db->table('tenant_server_assignments')->insert(['tenant_id' => $tid, 'server_id' => $srv, 'assigned_at' => $now]);

        $settings = ['features' => ['stock' => true, 'serials' => true, 'warranty' => true, 'batches' => true, 'variants' => true], 'labels' => [], 'payments' => ['cash', 'card', 'mobile', 'credit'],
            'expenseCats' => ['Rent', 'Utilities', 'Other'], 'invoice' => ['prefix' => '', 'num' => ['year' => false, 'pad' => 0]], 'loyalty' => ['earnPer' => 100, 'pointValue' => 1]];
        $db->table('businesses')->insert(['tenant_id' => $tid, 'business_type' => $type, 'name' => 'Test Shop ' . $u, 'slug' => 'test-shop-' . $u, 'currency_symbol' => 'Tk',
            'tax_label' => 'VAT', 'tax_rate' => 5, 'tax_inclusive' => 1, 'tax_rates' => '0, 5, 7.5, 10, 15', 'low_stock_alert' => 3, 'stock_enabled' => ($o['stock'] ?? true) ? 1 : 0,
            'settings' => json_encode($settings), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $bid = (int) $db->insertID();

        $all = config('PosPermissions')->all();
        $roles = [];
        foreach ([['owner', 'Owner', 1, $all], ['cashier', 'Cashier', 0, ['pos.sell', 'orders.view', 'customers.view', 'inventory.view']]] as [$code, $name, $locked, $perms]) {
            $db->table('roles')->insert(['tenant_id' => $tid, 'code' => $code, 'name' => $name, 'is_locked' => $locked, 'permissions' => json_encode($perms), 'home' => 'dashboard', 'created_at' => $now, 'updated_at' => $now]);
            $roles[$code] = (int) $db->insertID();
        }
        $users = [];
        foreach (['owner' => 1, 'cashier' => 0] as $who => $isOwner) {
            $db->table('users')->insert(['tenant_id' => $tid, 'name' => ucfirst($who) . ' ' . $u, 'email' => $isOwner ? 'owner' . $u . '@example.test' : null, 'pin_hash' => password_hash($isOwner ? '1111' : '2222', PASSWORD_DEFAULT),
                'password_hash' => $isOwner ? password_hash('secret123', PASSWORD_DEFAULT) : null, 'is_owner' => $isOwner, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $users[$who] = (int) $db->insertID();
            $db->table('user_businesses')->insert(['user_id' => $users[$who], 'business_id' => $bid, 'role_id' => $roles[$who]]);
        }
        $db->table('categories')->insert(['tenant_id' => $tid, 'business_id' => $bid, 'name' => 'Phones', 'icon' => 'smartphone', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $cat = (int) $db->insertID();
        $db->table('sites')->insert(['tenant_id' => $tid, 'business_id' => $bid, 'mode' => 'off', 'slug' => 'test-shop-' . $u, 'created_at' => $now, 'updated_at' => $now]);

        return ['tenant_id' => $tid, 'business_id' => $bid, 'users' => $users, 'roles' => $roles, 'category_id' => $cat, 'plan_id' => (int) $plan['id']];
    }
}
