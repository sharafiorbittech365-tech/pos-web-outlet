<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use OmniPOS\Core\Services\PlatformCatalog;

/**
 * Monthly recurring revenue for many subscriptions at once: two queries (subscriptions, add-ons)
 * plus the cached catalogue — the same rule as BillingService::mrr() without a query per tenant.
 *
 *   MRR = (plan price for the business type + billed add-ons) for monthly,
 *         that × yearlyMonths / 12 for yearly; only active and past-due subscriptions count.
 */
final class MrrCalculator
{
    private readonly PlatformCatalog $catalog;

    public function __construct(?PlatformCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? service('catalog');
    }

    /**
     * @param list<int>|null $tenantIds null = every tenant
     * @return array<int, float> tenant id => MRR (tenants without revenue are left out)
     */
    public function forTenants(?array $tenantIds = null): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $db = db_connect();
        $b = $db->table('subscriptions s')->select('s.tenant_id, s.plan_id, s.billing_cycle, t.business_type')
            ->join('tenants t', 't.id = s.tenant_id')
            ->whereIn('s.status', ['active', 'past_due'])->where('t.deleted_at', null);
        if ($tenantIds !== null) {
            $b->whereIn('s.tenant_id', $tenantIds);
        }
        $subs = $b->get()->getResultArray();
        if ($subs === []) {
            return [];
        }
        $addons = [];
        $ab = $db->table('tenant_addons')->select('tenant_id, module_code');
        if ($tenantIds !== null) {
            $ab->whereIn('tenant_id', array_map('intval', array_column($subs, 'tenant_id')));
        }
        foreach ($ab->get()->getResultArray() as $a) {
            $addons[(int) $a['tenant_id']][] = (string) $a['module_code'];
        }
        $yearlyMonths = (float) $this->catalog->setting('yearlyMonths', 10);
        $engine = service('pricing');
        $useEngine = $engine->active();
        $out = [];
        foreach ($subs as $s) {
            $tid = (int) $s['tenant_id'];
            // database-driven pricing: the same quote as the invoice (outlets, modules, discounts, packages)
            $out[$tid] = $useEngine ? (float) $engine->quote($tid, [], (int) $s['plan_id'], (string) $s['business_type'])['monthly']
                : $this->monthly($tid, (int) $s['plan_id'], (string) $s['business_type'], $addons[$tid] ?? []);
            if ($s['billing_cycle'] === 'yearly') {
                $out[$tid] = $out[$tid] * $yearlyMonths / 12;
            }
        }

        return $out;
    }

    public function total(): float
    {
        $key = 'omnipos_sa_mrr_total';
        $hit = cache($key);
        if (is_numeric($hit)) {
            return (float) $hit;
        }
        $sum = array_sum($this->forTenants());
        cache()->save($key, $sum, 60);

        return $sum;
    }

    /** @param list<string> $addons */
    private function monthly(int $tenantId, int $planId, string $type, array $addons): float
    {
        $plans = $this->catalog->plans();
        if (! isset($plans[$planId])) {
            return 0.0;
        }
        $sum = $this->catalog->planPrice($planId, $type) ?? 0.0;
        foreach ($addons as $m) {
            if (! $this->catalog->planIncludes($plans[$planId], $m)) {
                $sum += $this->catalog->modulePrice($m, $type) ?? 0.0;
            }
        }

        return $sum;
    }
}
