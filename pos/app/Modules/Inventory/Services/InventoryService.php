<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Stock read-side (levels, movements, batches, serials) and the manual adjustments of the current
 * outlet. Every change goes through core StockService, which writes the stock_movements row.
 */
final class InventoryService
{
    /** Days before expiry a batch counts as "expiring". */
    public const NEAR_DAYS = 30;

    /** reason shown in the form => [stock_movements.reason, sign hint] */
    public const REASONS = [
        'received' => ['Purchase / received', 'purchase'],
        'count' => ['Stock count correction', 'adjust'],
        'damaged' => ['Damaged / expired', 'adjust'],
        'supplier' => ['Returned to supplier', 'adjust'],
        'opening' => ['Opening stock', 'opening'],
    ];

    public const MOVE_REASONS = ['sale' => 'Sale', 'return' => 'Return', 'purchase' => 'Purchase', 'adjust' => 'Adjustment', 'web' => 'Online order', 'opening' => 'Opening', 'transfer' => 'Transfer', 'void' => 'Void'];

    public function __construct(private readonly array $business)
    {
        helper("ui");
    }

    public static function make(): self
    {
        return new self(service('posSession')->business());
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    private function lowExpr(string $alias = 'p'): string
    {
        return 'COALESCE(' . $alias . '.low_stock_at, ' . $this->db()->escape((float) ($this->business['low_stock_alert'] ?? 0)) . ')';
    }

    private function nearDate(): string
    {
        // the outlet's near-expiry window (Pharmacy settings; 30 days by default)
        return date('Y-m-d', strtotime('+' . \App\Modules\Pharmacy\Services\PharmacySettings::for($this->business)['nearDays'] . ' days'));
    }

    /** Stock levels list (tracked products only). $filter: all | low | out | expiring */
    public function levelsQuery(string $filter): BaseBuilder
    {
        $db = $this->db();
        $b = $db->table('products p')
            ->select('p.id, p.name, p.sku, p.barcode, p.generic_name, p.unit, p.icon, p.price, p.cost, p.stock_type, p.stock_qty, p.low_stock_at, c.name AS category,
                      (p.stock_qty * p.cost) AS value, ' . $this->lowExpr() . ' AS low_at')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.business_id', $this->bid())->where('p.deleted_at', null)->where('p.stock_type <>', 'none');
        if ($filter === 'out') {
            $b->where('p.stock_qty <=', 0);
        } elseif ($filter === 'low') {
            $b->where('p.stock_qty >', 0)->where('p.stock_qty <= ' . $this->lowExpr(), null, false);
        } elseif ($filter === 'expiring') {
            $b->where('EXISTS (SELECT 1 FROM product_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.qty > 0 AND pb.expires_on IS NOT NULL AND pb.expires_on <= ' . $db->escape($this->nearDate()) . ')', null, false);
        }

        return $b;
    }

    /** @return array{tracked: int, value: float, retail: float, low: int, out: int, expiring: int} */
    public function stats(): array
    {
        $db = $this->db();
        $r = $db->query('SELECT COUNT(*) AS n, SUM(GREATEST(stock_qty, 0) * cost) AS value, SUM(GREATEST(stock_qty, 0) * price) AS retail,
                                SUM(stock_qty <= 0) AS out_n, SUM(stock_qty > 0 AND stock_qty <= ' . $this->lowExpr('products') . ') AS low_n
                           FROM products WHERE business_id = ? AND deleted_at IS NULL AND stock_type <> \'none\'', [$this->bid()])->getRowArray() ?? [];
        $exp = (int) ($db->query('SELECT COUNT(DISTINCT pb.product_id) AS n FROM product_batches pb JOIN products p ON p.id = pb.product_id AND p.deleted_at IS NULL
                                   WHERE pb.business_id = ? AND pb.qty > 0 AND pb.expires_on IS NOT NULL AND pb.expires_on <= ?', [$this->bid(), $this->nearDate()])->getRow('n') ?? 0);

        return ['tracked' => (int) ($r['n'] ?? 0), 'value' => (float) ($r['value'] ?? 0), 'retail' => (float) ($r['retail'] ?? 0),
            'low' => (int) ($r['low_n'] ?? 0), 'out' => (int) ($r['out_n'] ?? 0), 'expiring' => $exp];
    }

    /**
     * Small detail line per product on the current page (options, batches, serial count) — one query per kind.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function details(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }
        $db = $this->db();
        $out = [];
        foreach ($db->table('product_variants')->select('product_id, name, stock_qty')->whereIn('product_id', $ids)->where('business_id', $this->bid())->where('stock_qty >', 0)->orderBy('sort_order')->get()->getResultArray() as $v) {
            $out[(int) $v['product_id']][] = $v['name'] . ': ' . qty($v['stock_qty']);
        }
        foreach ($db->table('product_batches')->select('product_id, batch_no, expires_on, qty')->whereIn('product_id', $ids)->where('business_id', $this->bid())->where('qty >', 0)->orderBy('expires_on')->get()->getResultArray() as $b) {
            $out[(int) $b['product_id']][] = $b['batch_no'] . ($b['expires_on'] ? ' (' . date('M Y', strtotime($b['expires_on'])) . ($b['expires_on'] < date('Y-m-d') ? ', expired' : '') . ')' : '') . ': ' . qty($b['qty']);
        }
        foreach ($db->table('product_serials')->select('product_id, serial')->whereIn('product_id', $ids)->where('business_id', $this->bid())->whereIn('status', ['in_stock', 'returned'])->orderBy('id')->get()->getResultArray() as $s) {
            $out[(int) $s['product_id']][] = $s['serial'];
        }
        $html = [];
        foreach ($out as $pid => $list) {
            $html[$pid] = implode(', ', array_slice($list, 0, 4)) . (count($list) > 4 ? ' +' . (count($list) - 4) : '');
        }

        return $html;
    }

    /** @return array<string, mixed> tracked product with variants / batches / serials */
    public function product(int $id): array
    {
        $db = $this->db();
        $p = $db->table('products')->where('id', $id)->where('business_id', $this->bid())->where('deleted_at', null)->get()->getRowArray() ?? throw new NotFoundException('Product not found.');
        $p['variants'] = $db->table('product_variants')->select('id, name, stock_qty')->where('product_id', $id)->where('business_id', $this->bid())->orderBy('sort_order')->get()->getResultArray();
        $p['batches'] = $db->table('product_batches')->select('id, batch_no, expires_on, qty')->where('product_id', $id)->where('business_id', $this->bid())->orderBy('expires_on IS NULL', 'ASC', false)->orderBy('expires_on')->get()->getResultArray();
        $p['serials'] = $db->table('product_serials')->select('id, serial, status, created_at')->where('product_id', $id)->where('business_id', $this->bid())->whereIn('status', ['in_stock', 'returned'])->orderBy('id')->limit(1000)->get()->getResultArray();
        $p['sold'] = $db->table('product_serials')->where('product_id', $id)->where('business_id', $this->bid())->where('status', 'sold')->countAllResults();

        return $p;
    }

    /**
     * Manual adjustment of a simple / option-stocked product.
     *
     * @param array{delta?: string|float, reason?: string, note?: string, variant_id?: int|string, cost?: string|float} $in
     */
    public function adjust(int $productId, array $in): float
    {
        $p = $this->product($productId);
        $delta = (float) ($in['delta'] ?? 0);
        if (abs($delta) < 1e-9) {
            throw new ValidationException('Enter a quantity to adjust.', ['delta' => 'Enter + to receive or − to remove, e.g. 50 or -2.']);
        }
        $key = (string) ($in['reason'] ?? 'count');
        [$label, $reason] = self::REASONS[$key] ?? self::REASONS['count'];
        $variantId = (int) ($in['variant_id'] ?? 0) ?: null;
        if ($p['stock_type'] === 'variant') {
            if ($variantId === null || ! in_array($variantId, array_map('intval', array_column($p['variants'], 'id')), true)) {
                throw new ValidationException('Choose the option to adjust.', ['variant_id' => 'Choose the option (size / colour).']);
            }
            $v = array_values(array_filter($p['variants'], static fn (array $x): bool => (int) $x['id'] === $variantId))[0];
            if ((float) ($v['stock_qty'] ?? 0) + $delta < -1e-9) {
                throw new BusinessRuleException($v['name'] . ' has only ' . qty($v['stock_qty']) . ' in stock.');
            }
        } else {
            $variantId = null;
        }
        $note = trim($label . ' · ' . mb_substr(trim((string) ($in['note'] ?? '')), 0, 200), ' ·');
        $after = service('stock')->adjust($productId, $delta, $reason, $note, $variantId);
        if (isset($in['cost']) && is_numeric($in['cost']) && (float) $in['cost'] >= 0 && $delta > 0 && $key === 'received') {
            $this->db()->table('products')->where('id', $productId)->where('business_id', $this->bid())->update(['cost' => round((float) $in['cost'], 2), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        service('audit')->activity('Stock adjusted', $p['name'] . ': ' . ($delta > 0 ? '+' : '') . qty($delta) . ' · ' . $label . ' (now ' . qty($after) . ')');

        return $after;
    }

    /** @param list<string> $serials */
    public function addSerials(int $productId, array $serials, string $note = ''): int
    {
        $p = $this->product($productId);
        $n = service('stock')->receiveSerials($productId, $serials, 'purchase', $note !== '' ? $note : 'Received');
        if ($n > 0) {
            service('audit')->activity('Serials received', $p['name'] . ': +' . $n);
        }

        return $n;
    }

    public function removeSerial(int $productId, int $serialId, string $note): void
    {
        $p = $this->product($productId);
        service('stock')->writeOffSerial($productId, $serialId, $note);
        service('audit')->activity('Serial removed', $p['name'] . ($note !== '' ? ' · ' . $note : ''));
    }

    public function receiveBatch(int $productId, string $no, ?string $exp, float $qty): void
    {
        $p = $this->product($productId);
        service('stock')->receiveBatch($productId, $no, $exp, $qty, 'purchase', 'Received');
        service('audit')->activity('Batch received', $p['name'] . ': ' . $no . ' +' . qty($qty));
    }

    public function adjustBatch(int $productId, int $batchId, float $delta, string $reasonKey, string $note): void
    {
        if (abs($delta) < 1e-9) {
            throw new ValidationException('Enter a quantity to adjust.', ['delta' => 'Enter + or − quantity.']);
        }
        $p = $this->product($productId);
        [$label, $reason] = self::REASONS[$reasonKey] ?? self::REASONS['count'];
        service('stock')->adjustBatch($productId, $batchId, $delta, trim($label . ' · ' . mb_substr($note, 0, 200), ' ·'), $reason);
        service('audit')->activity('Batch adjusted', $p['name'] . ': ' . ($delta > 0 ? '+' : '') . qty($delta) . ' · ' . $label);
    }

    /** Stock movements (whole outlet or one product). */
    public function movementsQuery(?int $productId, string $reason, string $from, string $to): BaseBuilder
    {
        $b = $this->db()->table('stock_movements m')
            ->select('m.id, m.created_at, m.product_id, m.qty_change, m.qty_after, m.reason, m.ref_type, m.ref_id, m.note, p.name AS product, p.unit, v.name AS variant, u.name AS user_name, o.doc_no')
            ->join('products p', 'p.id = m.product_id', 'left')
            ->join('product_variants v', 'v.id = m.variant_id', 'left')
            ->join('users u', 'u.id = m.user_id', 'left')
            ->join('orders o', "o.id = m.ref_id AND m.ref_type = 'order'", 'left')
            ->where('m.business_id', $this->bid())->where('m.created_at >=', $from)->where('m.created_at <', $to);
        if ($productId !== null) {
            $b->where('m.product_id', $productId);
        }
        if (array_key_exists($reason, self::MOVE_REASONS)) {
            $b->where('m.reason', $reason);
        }

        return $b;
    }

    /** Batches with stock, soonest expiry first (expired and expiring within $days). */
    public function batchesQuery(int $days): BaseBuilder
    {
        $b = $this->db()->table('product_batches b')
            ->select('b.id, b.batch_no, b.expires_on, b.qty, b.product_id, p.name AS product, p.unit, p.cost, (b.qty * p.cost) AS value, DATEDIFF(b.expires_on, CURDATE()) AS days_left')
            ->join('products p', 'p.id = b.product_id')
            ->where('b.business_id', $this->bid())->where('p.deleted_at', null)->where('b.qty >', 0);
        if ($days > 0) {
            $b->where('b.expires_on IS NOT NULL', null, false)->where('b.expires_on <=', date('Y-m-d', strtotime('+' . $days . ' days')));
        }

        return $b;
    }

    /** Serial / IMEI lookup across the outlet (every status). */
    public function serialsQuery(string $status): BaseBuilder
    {
        $b = $this->db()->table('product_serials s')
            ->select('s.id, s.serial, s.status, s.sold_at, s.created_at, s.product_id, s.order_id, p.name AS product, o.doc_no, o.customer_name, p.warranty_months')
            ->join('products p', 'p.id = s.product_id')
            ->join('orders o', 'o.id = s.order_id', 'left')
            ->where('s.business_id', $this->bid());
        if (in_array($status, ['in_stock', 'sold', 'returned', 'faulty'], true)) {
            $b->where('s.status', $status);
        }

        return $b;
    }
}
