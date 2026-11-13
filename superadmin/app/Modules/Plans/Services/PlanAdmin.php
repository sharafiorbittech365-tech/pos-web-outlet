<?php

declare(strict_types=1);

namespace App\Modules\Plans\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Plans (limits, modules, features) and the plan price list per business type.
 * After a change the platform catalogue cache is flushed and the module access of the affected
 * tenants is recomputed on their next request.
 */
final class PlanAdmin
{
    public const FEATURES = [
        'addons' => 'Add-on modules (loyalty…)',
        'invoiceDesigns' => 'Logo & letterhead invoices',
        'csv' => 'CSV import / export',
        'multiOutlet' => 'Multi-outlet switching',
        'prioritySupport' => 'Priority support',
    ];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    /** @return array<int, array<string, int>> plan id => [business type => tenants] */
    public static function tenantCounts(): array
    {
        $out = [];
        foreach (db_connect()->table('subscriptions s')->select('s.plan_id, t.business_type, COUNT(*) AS n')->join('tenants t', 't.id = s.tenant_id')
            ->where('t.deleted_at', null)->groupBy('s.plan_id, t.business_type')->get()->getResultArray() as $r) {
            $out[(int) $r['plan_id']][$r['business_type']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $in validated fields (+ modules[] / features[] arrays)
     * @return int plan id
     */
    public function save(?int $id, array $in, array $modules, array $features): int
    {
        $catalog = service('catalog');
        $known = $catalog->modules();
        $all = ($in['all_modules'] ?? '0') === '1';
        $mods = $all ? [] : array_values(array_filter($modules, static fn (string $m): bool => isset($known[$m]) && ! (bool) $known[$m]['is_core']));
        // compatibility rules: a plan never bundles two modules that cannot run together
        $pairs = \OmniPOS\Core\Services\ModuleRules::conflictPairs($known, $mods);
        if ($pairs !== []) {
            [$a, $b] = $pairs[0];
            throw new ValidationException('These modules cannot be in one plan.', ['modules' => $known[$a]['name'] . ' cannot run together with ' . $known[$b]['name'] . '.']);
        }
        $feat = [];
        foreach (array_keys(self::FEATURES) as $k) {
            $feat[$k] = in_array($k, $features, true);
        }
        $now = date('Y-m-d H:i:s');
        $row = [
            'name' => trim((string) $in['name']), 'tagline' => trim((string) ($in['tagline'] ?? '')) ?: null, 'color' => strtoupper((string) $in['color']),
            'limit_outlets' => (int) $in['limit_outlets'], 'limit_users' => (int) $in['limit_users'], 'all_modules' => $all ? 1 : 0,
            'modules' => json_encode($mods), 'features' => json_encode($feat), 'is_active' => ($in['is_active'] ?? '0') === '1' ? 1 : 0,
            'is_popular' => ($in['is_popular'] ?? '0') === '1' ? 1 : 0, 'sort_order' => (int) ($in['sort_order'] ?? 0), 'updated_at' => $now,
        ];
        $planId = Tx::run(function (BaseConnection $db) use ($id, $in, $row, $now): int {
            if ($id === null) {
                $code = strtolower(trim((string) $in['code']));
                if ($db->table('plans')->where('code', $code)->countAllResults() > 0) {
                    throw new ValidationException('That plan code is taken.', ['code' => 'That code is already used.']);
                }
                $db->table('plans')->insert($row + ['code' => $code, 'created_at' => $now]);
                $planId = (int) $db->insertID();
                service('audit')->platform('Plan created', $code . ' · ' . $row['name'], null, $this->actor);
            } else {
                $old = $db->table('plans')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Plan not found.');
                $db->table('plans')->where('id', $id)->update($row);
                $planId = $id;
                $changes = [];
                foreach (['name', 'tagline', 'color', 'limit_outlets', 'limit_users', 'all_modules', 'modules', 'features', 'is_active', 'is_popular', 'sort_order'] as $k) {
                    if ((string) $old[$k] !== (string) $row[$k]) {
                        $changes[] = $k;
                    }
                }
                service('audit')->platform('Plan changed', $old['code'] . ' · ' . ($changes ? implode(', ', $changes) : 'no change'), null, $this->actor);
            }
            if ($row['is_popular'] === 1) {
                $db->table('plans')->where('id !=', $planId)->update(['is_popular' => 0]);
            }

            return $planId;
        });
        $this->refresh($planId);

        return $planId;
    }

    public function delete(int $id): string
    {
        $db = db_connect();
        $plan = $db->table('plans')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Plan not found.');
        $used = $db->table('subscriptions')->groupStart()->where('plan_id', $id)->orWhere('next_plan_id', $id)->groupEnd()->countAllResults()
            + $db->table('platform_invoices')->where('plan_id', $id)->countAllResults();
        if ($used > 0) {
            throw new BusinessRuleException($plan['name'] . ' has subscriptions or invoices — hide it (untick "Offered to new customers") instead of deleting.');
        }
        if ((string) service('catalog')->setting('trialPlan', '') === $plan['code']) {
            throw new BusinessRuleException($plan['name'] . ' is the free-trial plan. Choose another trial plan in System first.');
        }
        $db->table('plans')->where('id', $id)->delete();
        service('audit')->platform('Plan deleted', $plan['code'] . ' · ' . $plan['name'], null, $this->actor);
        service('catalog')->flush();

        return (string) $plan['name'];
    }

    /**
     * Save the price list: prices[planId][type] = '' (not offered) or a monthly price.
     *
     * @param array<int|string, array<string, string>> $prices
     */
    public function savePrices(array $prices): int
    {
        $catalog = service('catalog');
        $plans = $catalog->plans();
        $types = $catalog->businessTypes();
        $rows = [];
        foreach ($prices as $planId => $byType) {
            if (! isset($plans[(int) $planId]) || ! is_array($byType)) {
                continue;
            }
            foreach ($byType as $type => $v) {
                if (! isset($types[$type])) {
                    continue;
                }
                $v = trim((string) $v);
                if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 10000000)) {
                    throw new ValidationException('Prices must be positive numbers.', ['prices' => 'Invalid price for ' . $plans[(int) $planId]['name'] . ' / ' . $types[$type]['name'] . '.']);
                }
                $rows[] = [(int) $planId, (string) $type, $v === '' ? null : round((float) $v, 2)];
            }
        }
        $changed = Tx::run(function (BaseConnection $db) use ($rows, $catalog): int {
            $n = 0;
            foreach ($rows as [$planId, $type, $price]) {
                if ($catalog->planPrice($planId, $type) !== $price) {
                    $n++;
                }
            }
            if ($rows !== []) {
                $sql = 'INSERT INTO plan_prices (plan_id, business_type, monthly_price) VALUES ' . implode(',', array_fill(0, count($rows), '(?,?,?)'))
                    . ' ON DUPLICATE KEY UPDATE monthly_price = VALUES(monthly_price)';
                $db->query($sql, array_merge(...array_map(static fn (array $r): array => $r, $rows)));
            }
            service('audit')->platform('Plan prices saved', $n . ' price(s) changed', null, $this->actor);

            return $n;
        });
        $this->refresh(null);

        return $changed;
    }

    /** Flush the catalogue and the module access of the plan's tenants (all tenants when null). */
    private function refresh(?int $planId): void
    {
        service('catalog')->flush();
        $b = db_connect()->table('subscriptions')->select('tenant_id');
        if ($planId !== null) {
            $b->where('plan_id', $planId);
        }
        $access = service('moduleAccess');
        foreach (array_column($b->get()->getResultArray(), 'tenant_id') as $tid) {
            $access->invalidate((int) $tid);
        }
    }
}
