<?php

declare(strict_types=1);

namespace App\Modules\Overview\Services;

use App\Modules\Subscriptions\Services\MrrCalculator;
use App\Modules\Tenants\Services\TenantDirectory;

/**
 * Platform KPIs for the overview page — aggregate queries only (no per-tenant loops).
 */
final class OverviewStats
{
    /** @return array<string, mixed> */
    public function kpis(): array
    {
        $db = db_connect();
        $counts = (new TenantDirectory())->statusCounts();
        $open = $db->table('platform_invoices i')->select('COALESCE(SUM(i.total), 0) AS total, COUNT(*) AS n, SUM(i.due_at < NOW()) AS overdue')
            ->where('i.status', 'open')->get()->getRowArray();
        $month = $db->table('platform_payments')->select('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n')
            ->where('paid_at >=', date('Y-m-01 00:00:00'))->get()->getRowArray();
        $mrr = (new MrrCalculator())->total();

        return [
            'counts' => $counts,
            'mrr' => $mrr,
            'open_total' => (float) $open['total'],
            'open_count' => (int) $open['n'],
            'overdue' => (int) $open['overdue'],
            'paid_month' => (float) $month['total'],
            'paid_month_count' => (int) $month['n'],
        ];
    }

    /** @return list<array{key: string, label: string, total: float}> payments per month, oldest first */
    public function revenue(int $months = 6): array
    {
        $from = date('Y-m-01 00:00:00', strtotime('first day of this month -' . ($months - 1) . ' months'));
        $rows = db_connect()->table('platform_payments')->select("DATE_FORMAT(paid_at, '%Y-%m') AS ym, SUM(amount) AS total")
            ->where('paid_at >=', $from)->groupBy('ym')->get()->getResultArray();
        $by = array_column($rows, 'total', 'ym');
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime('first day of this month -' . $i . ' months');
            $key = date('Y-m', $ts);
            $out[] = ['key' => $key, 'label' => date('M', $ts), 'total' => (float) ($by[$key] ?? 0)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> accounts that need a look: past due, trials ending, suspended */
    public function attention(int $limit = 8): array
    {
        return db_connect()->table('subscriptions s')
            ->select('t.id, t.name, s.status, s.trial_ends_at, s.suspend_reason, s.period_end')
            ->join('tenants t', 't.id = s.tenant_id')->where('t.deleted_at', null)
            ->groupStart()
                ->whereIn('s.status', ['past_due', 'suspended'])
                ->orGroupStart()->where('s.status', 'trial')->where('s.trial_ends_at <', date('Y-m-d H:i:s', strtotime('+4 days')))->groupEnd()
            ->groupEnd()
            ->orderBy("FIELD(s.status, 'past_due', 'suspended', 'trial')", '', false)->orderBy('t.name')
            ->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> newest accounts */
    public function newest(int $limit = 6): array
    {
        return db_connect()->table('tenants t')->select('t.id, t.name, t.created_at, s.status, p.name AS plan_name')
            ->join('subscriptions s', 's.tenant_id = t.id')->join('plans p', 'p.id = s.plan_id')
            ->where('t.deleted_at', null)->orderBy('t.created_at', 'desc')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> latest platform audit rows with the tenant name */
    public function recentAudit(int $limit = 8): array
    {
        return db_connect()->table('platform_audit a')->select('a.id, a.actor_name, a.actor_role, a.action, a.detail, a.tenant_id, a.created_at, t.name AS tenant_name')
            ->join('tenants t', 't.id = a.tenant_id', 'left')->orderBy('a.id', 'desc')->limit($limit)->get()->getResultArray();
    }
}
