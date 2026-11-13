<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\OutletProvisioner;

/**
 * Self sign-up: a new account (tenant) with a free trial, the role set of its business type,
 * the owner user and a first outlet built from the business-type template, assigned to the
 * least-loaded POS server. Everything runs in one transaction.
 */
final class SignupService
{
    /** Owner's till PIN until they change it in Users & roles. */
    public const FIRST_PIN = '1111';

    public function __construct(private readonly OutletProvisioner $outlets = new OutletProvisioner())
    {
    }

    /** @return array<string, string> type code => name (types that have a template) */
    public function businessTypes(): array
    {
        $tpl = $this->outlets->templates()['templates'];
        $out = [];
        foreach (service('catalog')->businessTypes() as $code => $t) {
            if (isset($tpl[$code])) {
                $out[$code] = (string) $t['name'];
            }
        }
        if ($out === []) {
            foreach ($tpl as $code => $t) {
                $out[$code] = (string) $t['name'];
            }
        }

        return $out;
    }

    /** Picker groups: business-type code => group label (a type not listed falls under "Other"). */
    private const GROUPS = [
        'Retail & shops' => ['grocery', 'departmental', 'fashion', 'footwear', 'electronics', 'mobile', 'accessories', 'pharmacy', 'cosmetics', 'jewellery', 'books', 'sports', 'gifts', 'pet', 'optical', 'furniture'],
        'Hardware, building & trade' => ['hardware', 'sanitary', 'paint', 'electrical', 'autoparts', 'agro', 'wholesale'],
        'Food & drink' => ['restaurant', 'cafe', 'bakery'],
        'Salon, repair & other services' => ['salon', 'barber', 'parlour', 'carwash', 'laundry', 'tailor', 'mobilerepair', 'computerrepair', 'photography', 'printing', 'diagnostic', 'travel', 'agency', 'karaoke'],
    ];

    /**
     * @param array<string, string> $types code => name
     * @return array<string, array<string, string>> group label => [code => name], names sorted inside each group
     */
    public function groupedTypes(array $types): array
    {
        $out = [];
        $listed = [];
        foreach (self::GROUPS as $label => $codes) {
            $listed = array_merge($listed, $codes);
            $g = array_intersect_key($types, array_flip($codes));
            if ($g !== []) {
                asort($g, SORT_NATURAL | SORT_FLAG_CASE);
                $out[$label] = $g;
            }
        }
        $rest = array_diff_key($types, array_flip($listed));
        if ($rest !== []) {
            asort($rest, SORT_NATURAL | SORT_FLAG_CASE);
            $out['Other'] = $rest;
        }

        return $out;
    }

    /**
     * @param array{account: string, owner: string, email: string, phone: string, password: string, type: string} $in
     * @return array{tenant_id: int, business_id: int, user_id: int, server: array<string, mixed>}
     */
    public function register(array $in): array
    {
        $catalog = service('catalog');
        if (! (bool) $catalog->setting('signup', true)) {
            throw new BusinessRuleException('New sign-ups are closed right now. Please contact support.');
        }
        $email = mb_strtolower(trim($in['email']));
        $types = $this->businessTypes();
        if (! isset($types[$in['type']])) {
            throw new ValidationException('Choose a business type.', ['type' => 'Choose a business type']);
        }
        $db = db_connect();
        if ($db->table('users')->where('email', $email)->countAllResults() > 0 || $db->table('tenants')->where('owner_email', $email)->countAllResults() > 0) {
            throw new ValidationException('This email already has an account. Sign in instead.', ['email' => 'Already registered']);
        }
        $plan = $catalog->planByCode((string) $catalog->setting('trialPlan', 'business')) ?? (array_values($catalog->plans(true))[0] ?? null);
        if ($plan === null) {
            throw new BusinessRuleException('No plan is available for sign-up.');
        }
        $trialDays = max(1, (int) $catalog->setting('trialDays', 14));
        $roles = $this->outlets->roleLibrary();

        $result = Tx::run(function ($db) use ($in, $email, $plan, $trialDays, $roles): array {
            $now = date('Y-m-d H:i:s');
            $db->table('tenants')->insert([
                'code' => $this->tenantCode($in['account']), 'name' => mb_substr(trim($in['account']), 0, 160), 'owner_name' => mb_substr(trim($in['owner']), 0, 100),
                'owner_email' => $email, 'owner_phone' => $in['phone'] !== '' ? mb_substr($in['phone'], 0, 32) : null, 'business_type' => $in['type'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $tid = (int) $db->insertID();
            $db->table('subscriptions')->insert(['tenant_id' => $tid, 'plan_id' => (int) $plan['id'], 'billing_cycle' => 'monthly', 'status' => 'trial',
                'trial_ends_at' => date('Y-m-d H:i:s', strtotime("+{$trialDays} days")), 'created_at' => $now, 'updated_at' => $now]);

            // the business type's role set; the owner role holds every permission
            $roleIds = [];
            foreach ($roles['sets'][$in['type']] ?? ['owner', 'manager', 'cashier'] as $rc) {
                $r = $roles['roles'][$rc] ?? null;
                if ($r === null) {
                    continue;
                }
                $db->table('roles')->insert(['tenant_id' => $tid, 'code' => $rc, 'name' => $roles['names'][$in['type']][$rc] ?? $r['name'], 'color' => $r['color'], 'icon' => $r['icon'],
                    'home' => $r['home'], 'max_discount' => $r['maxDiscount'], 'is_locked' => $rc === 'owner' ? 1 : 0, 'permissions' => json_encode($rc === 'owner' ? config('PosPermissions')->all() : $r['perms']),
                    'created_at' => $now, 'updated_at' => $now]);
                $roleIds[$rc] = (int) $db->insertID();
            }
            if (! isset($roleIds['owner'])) {
                throw new BusinessRuleException('The role library has no owner role.');
            }
            $db->table('users')->insert(['tenant_id' => $tid, 'name' => mb_substr(trim($in['owner']), 0, 100), 'email' => $email, 'phone' => $in['phone'] ?: null,
                'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT), 'pin_hash' => password_hash(self::FIRST_PIN, PASSWORD_DEFAULT),
                'is_owner' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $uid = (int) $db->insertID();

            $bid = $this->outlets->create($tid, $in['type'], ['name' => $in['account'], 'phone' => $in['phone'] ?: null, 'email' => $email], [$uid => $roleIds['owner']]);
            $server = service('servers')->autoAssign($tid, ['name' => 'Self sign-up', 'role' => 'System']);
            service('audit')->platform('Sign-up', trim($in['account']) . ' · ' . $in['type'] . ' · trial ' . $trialDays . ' days', $tid, ['name' => trim($in['owner']), 'role' => 'Owner']);

            return ['tenant_id' => $tid, 'business_id' => $bid, 'user_id' => $uid, 'server' => $server];
        });
        service('moduleAccess')->invalidate($result['tenant_id']);
        // recommended modules of the business type: on the trial, ones the plan lacks are added as add-ons
        $recommended = new \OmniPOS\Core\Services\RecommendedModules(service('catalog'), service('moduleAccess'));
        $actor = ['name' => trim($in['owner']), 'role' => 'Owner'];
        $recommended->apply($result['tenant_id'], (string) $in['type'], $actor);
        // modules that do not fit the business type start switched off (the owner can add them back)
        $recommended->hideOthers($result['tenant_id'], (string) $in['type'], $actor);
        service('usage')->refresh($result['tenant_id']);

        return $result;
    }

    private function tenantCode(string $name): string
    {
        $db = db_connect();
        $base = 't_' . substr(trim((string) preg_replace('/[^a-z0-9]+/', '', strtolower($name))), 0, 12);
        $code = $base;
        while ($db->table('tenants')->where('code', $code)->countAllResults() > 0) {
            $code = $base . '_' . bin2hex(random_bytes(2));
        }

        return $code;
    }
}
