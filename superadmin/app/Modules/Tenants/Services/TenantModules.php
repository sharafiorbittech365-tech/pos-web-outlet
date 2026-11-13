<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Services;

/**
 * The Modules tab: every catalogue module with what the plan gives, what the tenant bought,
 * the per-tenant switch and the resulting state (ModuleAccess, dependencies resolved).
 */
final class TenantModules
{
    /**
     * @param array<string, mixed> $tenant TenantDirectory::find()
     * @return list<array<string, mixed>>
     */
    public function rows(array $tenant): array
    {
        $catalog = service('catalog');
        $tid = (int) $tenant['id'];
        $type = (string) $tenant['business_type'];
        $plan = $catalog->plan((int) $tenant['plan_id']);
        $modules = $catalog->modules();
        $db = db_connect();
        $ovRows = $db->table('tenant_module_overrides')->where('tenant_id', $tid)->get()->getResultArray();
        // Super Admin switches only; the customer's own switches (set_by_owner) are shown separately
        $overrides = array_column(array_filter($ovRows, static fn (array $r): bool => (int) ($r['set_by_owner'] ?? 0) === 0), 'is_enabled', 'module_code');
        $ownerOff = array_column(array_filter($ovRows, static fn (array $r): bool => (int) ($r['set_by_owner'] ?? 0) === 1 && ! (int) $r['is_enabled']), 'module_code');
        $locks = $db->tableExists('tenant_module_locks', false) ? array_column($db->table('tenant_module_locks')->where('tenant_id', $tid)->get()->getResultArray(), 'self_select', 'module_code') : [];
        $tfRows = $db->tableExists('tenant_features', false) ? $db->table('tenant_features')->where('tenant_id', $tid)->get()->getResultArray() : [];
        $chosen = array_column(array_filter($tfRows, static fn (array $r): bool => (int) ($r['set_by_admin'] ?? 0) === 0), 'is_enabled', 'feature_code');
        $admin = array_column(array_filter($tfRows, static fn (array $r): bool => (int) ($r['set_by_admin'] ?? 0) === 1), 'is_enabled', 'feature_code');
        $addons = array_column($db->table('tenant_addons')->select('module_code')->where('tenant_id', $tid)->get()->getResultArray(), 'module_code');
        $access = service('moduleAccess')->forTenant($tid);
        $recommended = $catalog->recommended($type);
        $names = static fn (array $codes): array => array_map(static fn (string $n): string => (string) ($modules[$n]['name'] ?? $n), $codes);
        $graph = \OmniPOS\Core\Services\ModuleRules::graph($modules);
        $out = [];
        foreach ($modules as $code => $m) {
            $out[] = [
                'code' => $code,
                'name' => $m['name'],
                'description' => $m['description'],
                'icon' => $m['icon'],
                'core' => (bool) $m['is_core'],
                'opt_in' => (bool) $m['is_opt_in'],
                'needs' => array_map(static fn (string $n): string => (string) ($modules[$n]['name'] ?? $n), (array) $m['needs']),
                'in_plan' => $catalog->planIncludes($plan, $code),
                'price' => $catalog->modulePrice($code, $type),
                'addon' => in_array($code, $addons, true),
                'override' => array_key_exists($code, $overrides) ? ((int) $overrides[$code] ? 'on' : 'off') : 'plan',
                'on' => $access->has($code),
                'reason' => $access->reason($code),
                'conflicts' => $names($graph[$code]['conflicts'] ?? []),
                'recommended' => in_array($code, $recommended, true),
                'owner_off' => in_array($code, $ownerOff, true),
                'self_select' => (bool) ($m['self_select'] ?? true),
                'lock' => array_key_exists($code, $locks) ? ((int) $locks[$code] ? 'allow' : 'lock') : 'default',
                'features' => array_values(array_map(static fn (string $fc, array $f): array => ['code' => $fc, 'name' => (string) $f['name'], 'default' => (bool) $f['is_default'],
                    'price' => $catalog->featurePrice($fc, $type), 'on' => $access->hasFeature($fc), 'chosen' => array_key_exists($fc, $chosen) ? (bool) $chosen[$fc] : null,
                    'admin' => array_key_exists($fc, $admin) ? ((int) $admin[$fc] ? 'on' : 'off') : 'plan'],
                    array_keys($catalog->features($code)), $catalog->features($code))),
            ];
        }

        return $out;
    }
}
