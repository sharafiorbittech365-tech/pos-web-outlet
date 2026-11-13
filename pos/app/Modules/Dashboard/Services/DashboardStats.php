<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use CodeIgniter\Database\BaseBuilder;

/**
 * Dashboard KPIs for the current outlet — aggregate queries on indexed columns only
 * (orders: business_id + created_at, order_payments: business_id + created_at,
 * customers: business_id + balance_due, products: business_id + stock_type + stock_qty).
 */
final class DashboardStats
{
    private const LIVE = ['cancelled', 'held'];

    public function __construct(private readonly int $businessId, private readonly ?int $onlyUserId = null)
    {
    }

    private function orders(string $alias = 'o'): BaseBuilder
    {
        $b = db_connect()->table('orders ' . $alias)->where($alias . '.business_id', $this->businessId)->whereNotIn($alias . '.status', self::LIVE);
        if ($this->onlyUserId !== null) {
            $b->where($alias . '.user_id', $this->onlyUserId);
        }

        return $b;
    }

    /** @return array<string, mixed> */
    public function summary(array $business): array
    {
        $db = db_connect();
        $t0 = date('Y-m-d 00:00:00');
        $y0 = date('Y-m-d 00:00:00', strtotime('-1 day'));
        $today = $this->orders()->select('COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS v, COALESCE(SUM(o.total - o.tax_total - o.cost_total - o.shipping), 0) AS profit')
            ->where('o.created_at >=', $t0)->get()->getRowArray();
        $yday = $this->orders()->select('COALESCE(SUM(o.total), 0) AS v')->where('o.created_at >=', $y0)->where('o.created_at <', $t0)->get()->getRowArray();
        $pay = $db->table('order_payments')->select('COALESCE(SUM(amount), 0) AS v')->where('business_id', $this->businessId)->where('created_at >=', $t0);
        if ($this->onlyUserId !== null) {
            $pay->where('user_id', $this->onlyUserId);
        }
        $collected = (float) $pay->get()->getRow('v');
        $due = $db->table('customers')->select('COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS v')->where('business_id', $this->businessId)->where('balance_due >', 0)->get()->getRowArray();
        $low = 0;
        $out = 0;
        if ((bool) ($business['stock_enabled'] ?? false)) {
            $alert = (float) ($business['low_stock_alert'] ?? 0);
            $row = $db->table('products')->select('SUM(stock_qty <= 0) AS out_n, SUM(stock_qty > 0 AND stock_qty <= COALESCE(low_stock_at, ' . $db->escape($alert) . ')) AS low_n', false)
                ->where('business_id', $this->businessId)->where('is_active', 1)->where('deleted_at', null)->where('stock_type !=', 'none')->get()->getRowArray();
            $low = (int) ($row['low_n'] ?? 0);
            $out = (int) ($row['out_n'] ?? 0);
        }
        $kitchen = $db->table('orders')->where('business_id', $this->businessId)->whereIn('kitchen_status', ['new', 'cooking'])
            ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-1 day')))->whereNotIn('status', ['cancelled'])->countAllResults();
        $open = $db->table('orders')->where('business_id', $this->businessId)->where('status', 'open')->countAllResults();

        return [
            'sales' => (float) $today['v'], 'orders' => (int) $today['n'], 'avg' => (int) $today['n'] > 0 ? (float) $today['v'] / (int) $today['n'] : 0.0,
            'profit' => (float) $today['profit'], 'yesterday' => (float) $yday['v'], 'collected' => $collected,
            'due' => (float) $due['v'], 'due_customers' => (int) $due['n'], 'low_stock' => $low, 'out_of_stock' => $out, 'kitchen' => $kitchen, 'open' => $open,
        ];
    }

    /** @return list<array{at: string, v: float, n: int}> last 7 days, oldest first */
    public function days(): array
    {
        $from = date('Y-m-d 00:00:00', strtotime('-6 days'));
        $rows = $this->orders()->select('DATE(o.created_at) AS d, COUNT(*) AS n, SUM(o.total) AS v')->where('o.created_at >=', $from)
            ->groupBy('DATE(o.created_at)')->get()->getResultArray();
        $by = array_column($rows, null, 'd');
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[] = ['at' => $d, 'v' => (float) ($by[$d]['v'] ?? 0), 'n' => (int) ($by[$d]['n'] ?? 0)];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> best sellers of the last 7 days */
    public function topProducts(int $limit = 5): array
    {
        return $this->orders()->select('i.product_id, i.name, SUM(i.qty - i.returned_qty) AS qty, SUM(i.line_total) AS amount, MAX(i.unit) AS unit')
            ->join('order_items i', 'i.order_id = o.id')
            ->where('o.created_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->groupBy('i.product_id, i.name')->orderBy('amount', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 6): array
    {
        return $this->orders()->select('o.id, o.doc_no, o.customer_name, o.total, o.status, o.returned_total, o.channel, o.user_name, o.created_at')
            ->orderBy('o.created_at', 'DESC')->limit($limit)->get()->getResultArray();
    }
}
