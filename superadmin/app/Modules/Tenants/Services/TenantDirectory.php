<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Read side of customer accounts for Super Admin: account, subscription, plan, POS server and
 * the usage counts the workspace reports. Never touches workspace records (sales, customers, products).
 */
final class TenantDirectory
{
    public const STATUSES = ['all', 'active', 'trial', 'past_due', 'suspended', 'expired', 'cancelled'];

    /**
     * Filtered list query (one row per tenant) for the data table — all joins are on primary /
     * unique keys, so it stays a single indexed pass however many tenants there are.
     *
     * @param array{status?: string, plan?: string, server?: string} $filters
     */
    public function listQuery(array $filters): BaseBuilder
    {
        $b = db_connect()->table('tenants t')
            ->select('t.id, t.code, t.name, t.owner_name, t.owner_email, t.business_type, t.created_at, s.status, s.billing_cycle, s.trial_ends_at, s.period_end, s.cancel_at,
                      p.name AS plan_name, p.code AS plan_code, p.color AS plan_color, p.limit_outlets AS plan_outlets, p.limit_users AS plan_users,
                      t.limit_outlets, t.limit_users, bt.name AS type_name, bt.icon AS type_icon,
                      srv.name AS server_name, srv.code AS server_code, u.outlets, u.users, u.products, u.orders_month')
            ->select("IF(s.status = 'trial', s.trial_ends_at, COALESCE(s.cancel_at, s.period_end)) AS renew_at", false)
            ->join('subscriptions s', 's.tenant_id = t.id')
            ->join('plans p', 'p.id = s.plan_id')
            ->join('business_types bt', 'bt.code = t.business_type')
            ->join('tenant_server_assignments a', 'a.tenant_id = t.id', 'left')
            ->join('pos_servers srv', 'srv.id = a.server_id', 'left')
            ->join('tenant_usage u', 'u.tenant_id = t.id', 'left')
            ->where('t.deleted_at', null);
        if (! empty($filters['status']) && $filters['status'] !== 'all' && in_array($filters['status'], self::STATUSES, true)) {
            $b->where('s.status', $filters['status']);
        }
        if (! empty($filters['plan'])) {
            $b->where('p.code', $filters['plan']);
        }
        if (! empty($filters['server'])) {
            $b->where('srv.code', $filters['server']);
        }

        return $b;
    }

    /** @return array<string, int> status => count (for the filter chips) */
    public function statusCounts(): array
    {
        $rows = db_connect()->table('subscriptions s')->select('s.status, COUNT(*) AS n')->join('tenants t', 't.id = s.tenant_id')
            ->where('t.deleted_at', null)->groupBy('s.status')->get()->getResultArray();
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }

        return $out;
    }

    /** @return array<string, mixed> one account with subscription, server and usage */
    public function find(int $id): array
    {
        $row = $this->listQuery([])
            ->select('t.owner_phone, t.feature_overrides, s.id AS sub_id, s.plan_id, s.next_plan_id, s.period_start, s.auto_pay, s.pay_method, s.suspend_reason,
                      p.features AS plan_features, p.all_modules AS plan_all_modules, p.modules AS plan_modules, a.server_id, a.assigned_at, a.reason AS assign_reason,
                      srv.base_url AS server_url, srv.status AS server_status, u.computed_at AS usage_at')
            ->where('t.id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new NotFoundException('Account not found.');
        }
        $row['feature_overrides'] = json_decode((string) ($row['feature_overrides'] ?? ''), true) ?: [];
        $row['plan_features'] = json_decode((string) ($row['plan_features'] ?? ''), true) ?: [];
        $open = db_connect()->table('platform_invoices')->select('COUNT(*) AS n, COALESCE(SUM(total), 0) AS total')
            ->where('tenant_id', $id)->where('status', 'open')->get()->getRowArray();
        $row['open_count'] = (int) $open['n'];
        $row['open_total'] = (float) $open['total'];

        return $row;
    }

    /** Tabs that show counts: invoices, notes, server moves. @return array<string, int> */
    public function tabCounts(int $id): array
    {
        $db = db_connect();

        return [
            'invoices' => $db->table('platform_invoices')->where('tenant_id', $id)->countAllResults(),
            'notes' => $db->table('tenant_notes')->where('tenant_id', $id)->countAllResults(),
        ];
    }

    public function notesQuery(int $id): BaseBuilder
    {
        return db_connect()->table('tenant_notes n')->select('n.id, n.author, n.body, n.created_at')->where('n.tenant_id', $id);
    }

    public function serverHistoryQuery(int $id): BaseBuilder
    {
        return db_connect()->table('server_assignment_history h')
            ->select('h.id, h.created_at, h.actor_name, h.reason, f.name AS from_name, d.name AS to_name')
            ->join('pos_servers f', 'f.id = h.from_server_id', 'left')
            ->join('pos_servers d', 'd.id = h.to_server_id', 'left')
            ->where('h.tenant_id', $id);
    }

    /** Outlet count for the overview tab — counts only, never the outlet records' contents. @return list<string> type names */
    public function outletTypes(int $id): array
    {
        return array_column(db_connect()->table('businesses b')->select('bt.name')->distinct()->join('business_types bt', 'bt.code = b.business_type')
            ->where('b.tenant_id', $id)->get()->getResultArray(), 'name');
    }

    /** Last owner sign-in (account data, not workspace data). */
    public function ownerLastLogin(int $id): ?string
    {
        $row = db_connect()->table('users')->select('last_login_at')->where('tenant_id', $id)->where('is_owner', 1)->orderBy('id')->limit(1)->get()->getRowArray();

        return $row['last_login_at'] ?? null;
    }
}
