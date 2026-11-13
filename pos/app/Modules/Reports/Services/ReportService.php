<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Sales reports of the current outlet for a period [from, to). Every block is one aggregate query
 * on indexed columns (orders.business_id + created_at, order_payments.business_id + created_at …);
 * no order is ever loaded into PHP.
 *
 * Final sales = orders with status paid / partial / due / returned (open, held and cancelled are not sales).
 * "Net of returns": an order's VAT and value are scaled by (total − returned_total) / total.
 */
final class ReportService
{
    public const FINAL = ['paid', 'partial', 'due', 'returned'];

    public function __construct(private readonly string $from, private readonly string $to)
    {
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    /** SQL factor that keeps the not-returned share of an order (alias o). */
    public static function keep(string $o = 'o'): string
    {
        return "(CASE WHEN {$o}.total > 0 THEN GREATEST(0, ({$o}.total - {$o}.returned_total) / {$o}.total) ELSE 1 END)";
    }

    private function finals(string $alias = 'o'): string
    {
        return $alias . ".status IN ('" . implode("','", self::FINAL) . "')";
    }

    /** Orders of the period (alias o) — base for several blocks. */
    private function orders(string $alias = 'o'): BaseBuilder
    {
        return $this->db()->table('orders ' . $alias)
            ->where($alias . '.business_id', $this->bid())->where($alias . '.created_at >=', $this->from)->where($alias . '.created_at <', $this->to)
            ->where($this->finals($alias), null, false);
    }

    /**
     * Headline numbers and the profit & loss.
     *
     * @return array<string, float|int>
     */
    public function summary(): array
    {
        $db = $this->db();
        $bid = $this->bid();
        $o = $this->orders()->select('COUNT(*) AS n, COALESCE(SUM(o.total), 0) AS sales, COALESCE(SUM(o.tax_total), 0) AS tax, COALESCE(SUM(o.shipping), 0) AS shipping,
                COALESCE(SUM(o.discount_total), 0) AS discount, COALESCE(SUM(o.due_total), 0) AS due_new, COALESCE(SUM(o.service_charge), 0) AS service,
                COALESCE(SUM(o.tax_total * ' . self::keep() . '), 0) AS tax_kept', false)->get()->getRowArray() ?? [];
        $collected = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS a FROM order_payments WHERE business_id = ? AND method <> ? AND created_at >= ? AND created_at < ?', [$bid, 'exchange', $this->from, $this->to])->getRow('a') ?? 0);
        $returns = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS a FROM order_returns WHERE business_id = ? AND created_at >= ? AND created_at < ?', [$bid, $this->from, $this->to])->getRow('a') ?? 0);
        $cogs = (float) ($db->query('SELECT COALESCE(SUM(oi.unit_cost * (oi.qty - oi.returned_qty)), 0) AS c FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE o.business_id = ? AND o.created_at >= ? AND o.created_at < ? AND ' . $this->finals(), [$bid, $this->from, $this->to])->getRow('c') ?? 0);
        $expenses = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS a FROM expenses WHERE business_id = ? AND spent_at >= ? AND spent_at < ?', [$bid, $this->from, $this->to])->getRow('a') ?? 0);

        $sales = (float) ($o['sales'] ?? 0);
        $taxKept = (float) ($o['tax_kept'] ?? 0);
        $shipping = (float) ($o['shipping'] ?? 0);
        $revenue = $sales - $returns - $taxKept - $shipping;
        $gp = $revenue - $cogs;

        return [
            'n' => (int) ($o['n'] ?? 0), 'sales' => $sales, 'tax' => (float) ($o['tax'] ?? 0), 'tax_kept' => $taxKept, 'shipping' => $shipping, 'discount' => (float) ($o['discount'] ?? 0),
            'due_new' => (float) ($o['due_new'] ?? 0), 'service' => (float) ($o['service'] ?? 0), 'collected' => $collected, 'returns' => $returns, 'cogs' => $cogs,
            'expenses' => $expenses, 'revenue' => $revenue, 'gp' => $gp, 'np' => $gp - $expenses,
        ];
    }

    /**
     * VAT by rate and mode from order_taxes, net of returns.
     *
     * @return list<array{mode: string, rate: float, net: float, tax: float, n: int}>
     */
    public function vatByRate(): array
    {
        $k = self::keep();
        $rows = $this->db()->table('order_taxes ot')
            ->select("ot.tax_mode, ot.rate, SUM(ot.taxable * {$k}) AS net, SUM(ot.tax * {$k}) AS tax, COUNT(DISTINCT ot.order_id) AS n", false)
            ->join('orders o', 'o.id = ot.order_id')
            ->where('o.business_id', $this->bid())->where('o.created_at >=', $this->from)->where('o.created_at <', $this->to)->where($this->finals(), null, false)
            ->groupBy(['ot.tax_mode', 'ot.rate'])->orderBy('ot.rate', 'DESC')->orderBy('ot.tax_mode')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['mode' => $r['tax_mode'], 'rate' => (float) $r['rate'], 'net' => round((float) $r['net'], 2), 'tax' => round((float) $r['tax'], 2), 'n' => (int) $r['n']], $rows);
    }

    /** VAT register: one row per invoice (net, included, added, VAT, total — net of returns). */
    public function vatRegisterQuery(): BaseBuilder
    {
        $k = self::keep();

        return $this->orders()->select("o.id, o.doc_no, o.number, o.created_at, o.customer_name, o.returned_total,
                ROUND((o.total - o.tax_total - o.shipping) * {$k}, 2) AS net, ROUND(o.tax_included * {$k}, 2) AS incl, ROUND(o.tax_added * {$k}, 2) AS added,
                ROUND(o.tax_total * {$k}, 2) AS tax, ROUND(o.total * {$k}, 2) AS gross", false);
    }

    /** Day-wise: invoices, sales, collected, returns, expenses per day — one UNION query. */
    public function daysQuery(): BaseBuilder
    {
        $db = $this->db();
        $bid = $this->bid();
        $p = [$this->from, $this->to];
        $sales = $db->table('orders')->select('DATE(created_at) AS d, COUNT(*) AS n, SUM(total) AS sales, 0 AS collected, 0 AS returns, 0 AS expenses', false)
            ->where('business_id', $bid)->where('created_at >=', $p[0])->where('created_at <', $p[1])->where($this->finals('orders'), null, false)->groupBy('d');
        $pay = $db->table('order_payments')->select('DATE(created_at) AS d, 0 AS n, 0 AS sales, SUM(amount) AS collected, 0 AS returns, 0 AS expenses', false)
            ->where('business_id', $bid)->where('method !=', 'exchange')->where('created_at >=', $p[0])->where('created_at <', $p[1])->groupBy('d');
        $ret = $db->table('order_returns')->select('DATE(created_at) AS d, 0 AS n, 0 AS sales, 0 AS collected, SUM(amount) AS returns, 0 AS expenses', false)
            ->where('business_id', $bid)->where('created_at >=', $p[0])->where('created_at <', $p[1])->groupBy('d');
        $exp = $db->table('expenses')->select('DATE(spent_at) AS d, 0 AS n, 0 AS sales, 0 AS collected, 0 AS returns, SUM(amount) AS expenses', false)
            ->where('business_id', $bid)->where('spent_at >=', $p[0])->where('spent_at <', $p[1])->groupBy('d');

        return $db->newQuery()->fromSubquery($sales->unionAll($pay)->unionAll($ret)->unionAll($exp), 'x')
            ->select('x.d, SUM(x.n) AS n, SUM(x.sales) AS sales, SUM(x.collected) AS collected, SUM(x.returns) AS returns, SUM(x.expenses) AS expenses', false)
            ->groupBy('x.d');
    }

    /** Net (before VAT) value of the not-returned part of a line (alias oi). */
    private static function lineNet(): string
    {
        return "((oi.line_total - CASE WHEN oi.tax_mode = 'inclusive' THEN oi.tax_amount ELSE 0 END) * CASE WHEN oi.qty > 0 THEN (oi.qty - oi.returned_qty) / oi.qty ELSE 0 END)";
    }

    /** Products sold: qty (net of returns), sales before VAT, cost, profit. */
    public function productsQuery(): BaseBuilder
    {
        $net = self::lineNet();

        return $this->db()->table('order_items oi')
            ->select("oi.product_id, oi.name, MAX(oi.unit) AS unit, SUM(oi.qty - oi.returned_qty) AS qty, SUM({$net}) AS rev,
                      SUM(oi.unit_cost * (oi.qty - oi.returned_qty)) AS cost, SUM({$net}) - SUM(oi.unit_cost * (oi.qty - oi.returned_qty)) AS profit", false)
            ->join('orders o', 'o.id = oi.order_id')
            ->where('o.business_id', $this->bid())->where('o.created_at >=', $this->from)->where('o.created_at <', $this->to)->where($this->finals(), null, false)
            ->groupBy(['oi.product_id', 'oi.name']);
    }

    /** @return list<array{name: string, rev: float, cost: float, qty: float}> */
    public function byCategory(): array
    {
        $net = self::lineNet();
        $rows = $this->db()->table('order_items oi')
            ->select("COALESCE(c.name, 'Other') AS name, SUM({$net}) AS rev, SUM(oi.unit_cost * (oi.qty - oi.returned_qty)) AS cost, SUM(oi.qty - oi.returned_qty) AS qty", false)
            ->join('orders o', 'o.id = oi.order_id')
            ->join('products p', 'p.id = oi.product_id', 'left')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('o.business_id', $this->bid())->where('o.created_at >=', $this->from)->where('o.created_at <', $this->to)->where($this->finals(), null, false)
            ->groupBy('c.id')->orderBy('rev', 'DESC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['name' => $r['name'], 'rev' => (float) $r['rev'], 'cost' => (float) $r['cost'], 'qty' => (float) $r['qty']], $rows);
    }

    /** @return list<array{method: string, amount: float, n: int}> money collected by payment method */
    public function byMethod(): array
    {
        $rows = $this->db()->table('order_payments')->select('method, SUM(amount) AS amount, COUNT(*) AS n')
            ->where('business_id', $this->bid())->where('created_at >=', $this->from)->where('created_at <', $this->to)
            ->groupBy('method')->orderBy('amount', 'DESC')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['method' => (string) $r['method'], 'amount' => (float) $r['amount'], 'n' => (int) $r['n']], $rows);
    }

    /** @return array<int, array{sales: float, n: int}> sales per hour of the day (0-23) */
    public function byHour(): array
    {
        $rows = $this->orders()->select('HOUR(o.created_at) AS h, COUNT(*) AS n, SUM(o.total) AS sales', false)->groupBy('h')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['h']] = ['sales' => (float) $r['sales'], 'n' => (int) $r['n']];
        }

        return $out;
    }

    /** Top products by sales before tax. @return list<array{name: string, rev: float, qty: float, profit: float}> */
    public function topProducts(int $limit = 8): array
    {
        $rows = $this->productsQuery()->orderBy('rev', 'DESC')->limit($limit)->get()->getResultArray();

        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'rev' => (float) $r['rev'], 'qty' => (float) $r['qty'], 'profit' => (float) $r['profit']], $rows);
    }

    /** @return list<array{name: string, n: int, amount: float}> invoices per user who billed */
    public function byUser(): array
    {
        return $this->grouped("COALESCE(o.user_name, '—')");
    }

    /** @return list<array{name: string, n: int, amount: float}> invoices per salesperson / stylist */
    public function byStaff(): array
    {
        return $this->grouped("COALESCE(NULLIF(o.staff_name, ''), 'Unassigned')");
    }

    /** @return list<array{name: string, n: int, amount: float}> */
    public function byChannel(): array
    {
        return $this->grouped('o.channel');
    }

    /** @return list<array{name: string, n: int, amount: float}> */
    private function grouped(string $expr): array
    {
        $rows = $this->orders()->select($expr . ' AS name, COUNT(*) AS n, SUM(o.total) AS amount', false)->groupBy('name')->orderBy('amount', 'DESC')->limit(100)->get()->getResultArray();

        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'n' => (int) $r['n'], 'amount' => (float) $r['amount']], $rows);
    }
}
