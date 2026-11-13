<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use CodeIgniter\Database\BaseBuilder;

/**
 * All subscriptions with plan, cycle, renewal date and unpaid total (one row per tenant).
 */
final class SubscriptionDirectory
{
    /** @param array{status?: string, plan?: string, cycle?: string, due?: string} $filters */
    public static function listQuery(array $filters): BaseBuilder
    {
        $b = db_connect()->table('subscriptions s')
            ->select('s.id, s.tenant_id, s.billing_cycle, s.status, s.trial_ends_at, s.period_start, s.period_end, s.cancel_at, s.auto_pay, s.pay_method,
                      t.name, t.code, bt.name AS type_name, p.name AS plan_name, p.color AS plan_color, np.name AS next_plan_name')
            ->select('(SELECT COALESCE(SUM(i.total), 0) FROM platform_invoices i WHERE i.tenant_id = s.tenant_id AND i.status = \'open\') AS open_total', false)
            ->select("COALESCE(s.cancel_at, IF(s.status = 'trial', s.trial_ends_at, s.period_end)) AS renew_at", false)
            ->join('tenants t', 't.id = s.tenant_id')
            ->join('business_types bt', 'bt.code = t.business_type')
            ->join('plans p', 'p.id = s.plan_id')
            ->join('plans np', 'np.id = s.next_plan_id', 'left')
            ->where('t.deleted_at', null);
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $b->where('s.status', $filters['status']);
        }
        if (! empty($filters['plan'])) {
            $b->where('p.code', $filters['plan']);
        }
        if (! empty($filters['cycle'])) {
            $b->where('s.billing_cycle', $filters['cycle']);
        }
        if (! empty($filters['due'])) {
            $until = date('Y-m-d H:i:s', strtotime('+' . (int) $filters['due'] . ' days'));
            $b->groupStart()
                ->groupStart()->whereIn('s.status', ['active', 'past_due'])->where('s.period_end <=', $until)->groupEnd()
                ->orGroupStart()->where('s.status', 'trial')->where('s.trial_ends_at <=', $until)->groupEnd()
                ->groupEnd();
        }

        return $b;
    }

    /** @return array{renewals7: int, trials7: int, cancelling: int, autopay: int} */
    public static function stats(): array
    {
        $in7 = date('Y-m-d H:i:s', strtotime('+7 days'));
        $row = db_connect()->table('subscriptions s')->select("
                COALESCE(SUM(s.status IN ('active','past_due') AND s.period_end <= " . db_connect()->escape($in7) . " AND s.cancel_at IS NULL), 0) AS renewals7,
                COALESCE(SUM(s.status = 'trial' AND s.trial_ends_at <= " . db_connect()->escape($in7) . "), 0) AS trials7,
                COALESCE(SUM(s.cancel_at IS NOT NULL AND s.status IN ('active','past_due')), 0) AS cancelling,
                COALESCE(SUM(s.auto_pay = 1 AND s.status IN ('active','past_due')), 0) AS autopay", false)
            ->join('tenants t', 't.id = s.tenant_id')->where('t.deleted_at', null)->get()->getRowArray();

        return array_map('intval', $row);
    }
}
