<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

/**
 * Cross-outlet report for owners and managers: sales, collections, returns, expenses and stock
 * per outlet for a period [from, to), with an outlet filter. Only outlets where the signed-in
 * person has a role that can view reports are included (the owner role sees every outlet), so a
 * manager of one branch never sees another branch's numbers. One grouped query per block
 * (whereIn business_id), never a query per outlet.
 */
final class OutletReport
{
    public function __construct(private readonly string $from, private readonly string $to)
    {
    }

    /**
     * Outlets the current user may report on.
     *
     * @return array<int, array{id: int, name: string, type_name: string, area: ?string, is_active: bool, currency_symbol: string, currency_code: string, currency_decimals: int}>
     */
    public function outlets(): array
    {
        $tid = service('tenant')->tenantId();
        $uid = (int) service('tenant')->userId();
        $rows = db_connect()->table('user_businesses ub')
            ->select('b.id, b.name, b.area, b.is_active, b.currency_symbol, b.currency_code, b.currency_decimals, bt.name AS type_name, r.is_locked, r.permissions')
            ->join('businesses b', 'b.id = ub.business_id')->join('roles r', 'r.id = ub.role_id')->join('business_types bt', 'bt.code = b.business_type')
            ->where('ub.user_id', $uid)->where('b.tenant_id', $tid)->where('r.tenant_id', $tid)
            ->orderBy('b.id')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $perms = json_decode((string) $r['permissions'], true) ?: [];
            if ((bool) $r['is_locked'] || in_array('reports.view', $perms, true)) {
                $out[(int) $r['id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'type_name' => (string) $r['type_name'], 'area' => $r['area'], 'is_active' => (bool) $r['is_active'],
                    'currency_symbol' => (string) ($r['currency_symbol'] ?? ''), 'currency_code' => (string) ($r['currency_code'] ?? ''), 'currency_decimals' => (int) ($r['currency_decimals'] ?? 2)];
            }
        }

        return $out;
    }

    /**
     * Per-outlet figures (+ a total row when every outlet uses the same currency).
     *
     * @param list<int> $ids outlet ids (already limited to outlets())
     * @return array{rows: list<array<string, mixed>>, total: array<string, float|int>|null}
     */
    public function figures(array $ids): array
    {
        if ($ids === []) {
            return ['rows' => [], 'total' => null];
        }
        $db = db_connect();
        $tid = service('tenant')->tenantId();
        $in = implode(',', array_map('intval', $ids));
        $finals = "'" . implode("','", ReportService::FINAL) . "'";
        $by = static fn (array $rows): array => array_column($rows, null, 'business_id');

        $sales = $by($db->query("SELECT business_id, COUNT(*) AS n, COALESCE(SUM(total), 0) AS sales, COALESCE(SUM(due_total), 0) AS due_new
            FROM orders WHERE tenant_id = ? AND business_id IN ({$in}) AND created_at >= ? AND created_at < ? AND status IN ({$finals}) GROUP BY business_id", [$tid, $this->from, $this->to])->getResultArray());
        $paid = $by($db->query("SELECT business_id, COALESCE(SUM(amount), 0) AS a FROM order_payments WHERE business_id IN ({$in}) AND method <> 'exchange' AND created_at >= ? AND created_at < ? GROUP BY business_id", [$this->from, $this->to])->getResultArray());
        $ret = $by($db->query("SELECT business_id, COALESCE(SUM(amount), 0) AS a FROM order_returns WHERE business_id IN ({$in}) AND created_at >= ? AND created_at < ? GROUP BY business_id", [$this->from, $this->to])->getResultArray());
        $exp = $by($db->query("SELECT business_id, COALESCE(SUM(amount), 0) AS a FROM expenses WHERE business_id IN ({$in}) AND spent_at >= ? AND spent_at < ? GROUP BY business_id", [$this->from, $this->to])->getResultArray());
        $stock = $by($db->query("SELECT p.business_id, COUNT(*) AS products,
                COALESCE(SUM(CASE WHEN p.stock_type <> 'none' THEN GREATEST(p.stock_qty, 0) * p.cost ELSE 0 END), 0) AS stock_value,
                COALESCE(SUM(CASE WHEN p.stock_type <> 'none' THEN GREATEST(p.stock_qty, 0) ELSE 0 END), 0) AS units,
                SUM(CASE WHEN p.stock_type <> 'none' AND p.stock_qty <= COALESCE(p.low_stock_at, b.low_stock_alert) THEN 1 ELSE 0 END) AS low
            FROM products p JOIN businesses b ON b.id = p.business_id
            WHERE p.tenant_id = ? AND p.business_id IN ({$in}) AND p.is_active = 1 AND p.deleted_at IS NULL GROUP BY p.business_id", [$tid])->getResultArray());

        $rows = [];
        foreach ($ids as $id) {
            $s = $sales[$id] ?? [];
            $rows[] = [
                'id' => $id,
                'n' => (int) ($s['n'] ?? 0), 'sales' => (float) ($s['sales'] ?? 0), 'due_new' => (float) ($s['due_new'] ?? 0),
                'collected' => (float) ($paid[$id]['a'] ?? 0), 'returns' => (float) ($ret[$id]['a'] ?? 0), 'expenses' => (float) ($exp[$id]['a'] ?? 0),
                'products' => (int) ($stock[$id]['products'] ?? 0), 'units' => (float) ($stock[$id]['units'] ?? 0), 'stock_value' => (float) ($stock[$id]['stock_value'] ?? 0), 'low' => (int) ($stock[$id]['low'] ?? 0),
            ];
        }
        $total = [];
        foreach (['n', 'sales', 'due_new', 'collected', 'returns', 'expenses', 'products', 'units', 'stock_value', 'low'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
