<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Creates a customer account from Super Admin in one transaction:
 *   tenants → subscriptions (free trial, or a first invoice) → owner role + owner user with a
 *   one-time password → the first outlet (businesses) → POS server (ServerAllocation::autoAssign).
 * The owner finishes the workspace setup at first sign-in.
 */
final class TenantProvisioner
{
    /** Permissions of the locked Owner role (same set as the POS role library). */
    public const OWNER_PERMS = ['pos.sell', 'pos.discount', 'pos.price', 'pos.credit', 'pos.quotation', 'pos.return', 'pos.void', 'pos.backdate', 'orders.view', 'orders.all',
        'kitchen.view', 'tasks.manage', 'customers.view', 'customers.manage', 'customers.receive', 'inventory.view', 'inventory.manage', 'products.manage', 'reports.view',
        'expenses.manage', 'register.view', 'register.close', 'users.manage', 'settings.manage', 'billing.manage'];

    /**
     * @param array{name: string, owner_name: string, owner_email: string, owner_phone?: string, business_type: string, plan_id: int|string, billing_cycle: string, start?: string} $in
     * @param array{name: string, role: string, id?: int|null} $actor
     * @return array{tenant_id: int, password: string, server: string}
     */
    public function create(array $in, array $actor): array
    {
        $catalog = service('catalog');
        $email = mb_strtolower(trim($in['owner_email']));
        $type = (string) $in['business_type'];
        $planId = (int) $in['plan_id'];
        $cycle = $in['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly';
        $plan = $catalog->plan($planId);
        if (! isset($catalog->businessTypes()[$type])) {
            throw new ValidationException('Choose a business type.', ['business_type' => 'Unknown business type.']);
        }
        if ($catalog->planPrice($planId, $type) === null) {
            throw new BusinessRuleException($plan['name'] . ' is not offered for ' . $catalog->typeName($type) . '.');
        }
        $db = db_connect();
        if ($db->table('tenants')->where('owner_email', $email)->countAllResults() > 0 || $db->table('users')->where('email', $email)->countAllResults() > 0) {
            throw new ValidationException('That email already has an account.', ['owner_email' => 'That email already has an account.']);
        }
        $password = self::oneTimePassword();

        $result = Tx::run(function (BaseConnection $db) use ($in, $email, $type, $planId, $cycle, $plan, $password, $actor, $catalog): array {
            $now = date('Y-m-d H:i:s');
            $name = trim($in['name']);
            $db->table('tenants')->insert([
                'code' => $this->uniqueCode($db, $name), 'name' => $name, 'owner_name' => trim($in['owner_name']), 'owner_email' => $email,
                'owner_phone' => trim((string) ($in['owner_phone'] ?? '')) ?: null, 'business_type' => $type, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $tid = (int) $db->insertID();
            $trialDays = (int) $catalog->setting('trialDays', 14);
            $db->table('subscriptions')->insert([
                'tenant_id' => $tid, 'plan_id' => $planId, 'billing_cycle' => $cycle, 'status' => 'trial',
                'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days')), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $start = ($in['start'] ?? 'trial') === 'invoice' ? 'invoice' : 'trial';
            if ($start === 'invoice') {
                // paid from day one: the first invoice is open, so the account starts as past due until it is paid
                $inv = service('billing')->start($tid, $planId, $cycle);
                $db->table('subscriptions')->where('tenant_id', $tid)->update(['status' => 'past_due', 'trial_ends_at' => null,
                    'period_start' => $inv['period_from'], 'period_end' => $inv['period_to'], 'updated_at' => $now]);
            }

            $db->table('roles')->insert(['tenant_id' => $tid, 'code' => 'owner', 'name' => 'Owner', 'color' => '#7C3AED', 'icon' => 'crown', 'home' => 'dashboard',
                'max_discount' => 100, 'is_locked' => 1, 'permissions' => json_encode(self::OWNER_PERMS), 'created_at' => $now, 'updated_at' => $now]);
            $roleId = (int) $db->insertID();
            $db->table('users')->insert(['tenant_id' => $tid, 'name' => trim($in['owner_name']), 'email' => $email, 'phone' => trim((string) ($in['owner_phone'] ?? '')) ?: null,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'is_owner' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $userId = (int) $db->insertID();

            $db->table('businesses')->insert([
                'tenant_id' => $tid, 'business_type' => $type, 'name' => $name, 'slug' => $this->uniqueSlug($db, $name),
                'phone' => trim((string) ($in['owner_phone'] ?? '')) ?: null, 'email' => $email,
                'settings' => json_encode(['setup' => false, 'features' => (object) [], 'payments' => ['cash', 'card', 'mobile']]),
                'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $bid = (int) $db->insertID();
            $db->table('user_businesses')->insert(['user_id' => $userId, 'business_id' => $bid, 'role_id' => $roleId]);
            $db->table('tenant_usage')->insert(['tenant_id' => $tid, 'outlets' => 1, 'users' => 1, 'products' => 0, 'orders_month' => 0, 'computed_at' => $now]);

            $server = service('servers')->autoAssign($tid, $actor);
            service('audit')->platform('Tenant created', $name . ' · ' . $plan['name'] . ' · ' . $cycle . ' · ' . $start, $tid, $actor);

            return ['tenant_id' => $tid, 'server' => (string) $server['name']];
        });
        service('moduleAccess')->invalidate($result['tenant_id']);
        // recommended modules of the business type (add-ons only while on the free trial)
        $recommended = new \OmniPOS\Core\Services\RecommendedModules(service('catalog'), service('moduleAccess'));
        $result['recommended'] = $recommended->apply($result['tenant_id'], $type, $actor);
        // modules that do not fit the business type start switched off (the owner can add them back)
        $result['recommended']['hidden'] = $recommended->hideOthers($result['tenant_id'], $type, $actor);
        cache()->delete('omnipos_sa_pastdue');

        return $result + ['password' => $password];
    }

    /** 10 characters without look-alikes (0/O, 1/l/I). */
    public static function oneTimePassword(int $length = 10): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    private static function slugify(string $text, int $max): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));

        return substr($s !== '' ? $s : 'shop', 0, $max);
    }

    private function uniqueCode(BaseConnection $db, string $name): string
    {
        $base = 't_' . str_replace('-', '', self::slugify($name, 12));
        $code = $base;
        while ($db->table('tenants')->where('code', $code)->countAllResults() > 0) {
            $code = $base . '_' . bin2hex(random_bytes(2));
        }

        return $code;
    }

    private function uniqueSlug(BaseConnection $db, string $name): string
    {
        $base = self::slugify($name, 48);
        $slug = $base;
        while ($db->table('businesses')->where('slug', $slug)->countAllResults() > 0) {
            $slug = $base . '-' . bin2hex(random_bytes(2));
        }

        return $slug;
    }
}
