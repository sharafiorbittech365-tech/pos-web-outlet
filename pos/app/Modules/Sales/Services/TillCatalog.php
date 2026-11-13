<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * Read-only catalogue queries for the till (tiles, barcode / SKU / IMEI lookup, serial picker).
 * Every query is filtered by the current outlet and uses the products (business_id, …) indexes.
 */
final class TillCatalog
{
    public const PER_PAGE = 60;

    public function __construct(private readonly array $business)
    {
    }

    private function bid(): int
    {
        return (int) $this->business['id'];
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return db_connect()->table('categories c')
            ->select('c.id, c.name, c.icon, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.business_id = c.business_id AND p.is_active = 1 AND p.deleted_at IS NULL) AS n', false)
            ->where('c.business_id', $this->bid())->orderBy('c.sort_order')->orderBy('c.name')->get()->getResultArray();
    }

    /**
     * Product tiles (one page) with options and stock.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    public function products(string $q = '', int $cat = 0, bool $featured = false, int $page = 1): array
    {
        $db = db_connect();
        $b = $db->table('products p')
            ->select('p.id, p.category_id, p.name, p.sku, p.barcode, p.generic_name, p.brand, p.unit, p.icon, p.image_path, p.price, p.compare_at, p.stock_type, p.stock_qty, p.low_stock_at, p.is_featured, p.badge, p.is_rx, p.tax_mode, p.tax_rate, p.duration_min, p.warranty_months')
            ->where('p.business_id', $this->bid())->where('p.is_active', 1)->where('p.deleted_at', null);
        if ($cat > 0) {
            $b->where('p.category_id', $cat);
        }
        if ($featured) {
            $b->where('p.is_featured', 1);
        }
        $q = trim(mb_substr($q, 0, 60));
        if ($q !== '') {
            foreach (array_slice(preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4) as $w) {
                $b->groupStart()->like('p.name', $w)->orLike('p.sku', $w, 'after')->orLike('p.barcode', $w, 'after')->orLike('p.generic_name', $w)->orLike('p.brand', $w, 'after')->groupEnd();
            }
        }
        $page = max(1, $page);
        $rows = $b->orderBy('p.is_featured', 'DESC')->orderBy('p.name')->limit(self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE)->get()->getResultArray();
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        return ['items' => $this->shape($rows), 'more' => $more];
    }

    /**
     * Exact match for a scanned / typed code: barcode, SKU, option SKU or an in-stock IMEI / serial.
     *
     * @return array{product: array<string, mixed>, variant_id: ?int, serial: ?string}|null
     */
    public function lookup(string $code): ?array
    {
        $code = trim(mb_substr($code, 0, 64));
        if ($code === '') {
            return null;
        }
        $db = db_connect();
        $bid = $this->bid();
        $base = static fn () => $db->table('products p')->select('p.*')->where('p.business_id', $bid)->where('p.is_active', 1)->where('p.deleted_at', null);
        $p = $base()->groupStart()->where('p.barcode', $code)->orWhere('p.sku', $code)->groupEnd()->limit(1)->get()->getRowArray();
        if ($p !== null) {
            return ['product' => $this->shape([$p])[0], 'variant_id' => null, 'serial' => null];
        }
        $v = $db->table('product_variants')->select('id, product_id')->where('business_id', $bid)->where('sku', $code)->limit(1)->get()->getRowArray();
        if ($v !== null) {
            $p = $base()->where('p.id', $v['product_id'])->get()->getRowArray();
            if ($p !== null) {
                return ['product' => $this->shape([$p])[0], 'variant_id' => (int) $v['id'], 'serial' => null];
            }
        }
        $s = $db->table('product_serials')->select('product_id, serial')->where('business_id', $bid)->where('serial', $code)->where('status', 'in_stock')->limit(1)->get()->getRowArray();
        if ($s !== null) {
            $p = $base()->where('p.id', $s['product_id'])->get()->getRowArray();
            if ($p !== null) {
                return ['product' => $this->shape([$p])[0], 'variant_id' => null, 'serial' => (string) $s['serial']];
            }
        }

        return null;
    }

    /** @return list<string> in-stock serials of a product (IMEI picker) */
    public function serials(int $productId, string $q = '', int $limit = 60): array
    {
        $b = db_connect()->table('product_serials')->select('serial')->where('business_id', $this->bid())->where('product_id', $productId)->where('status', 'in_stock');
        if (trim($q) !== '') {
            $b->like('serial', trim($q));
        }

        return array_column($b->orderBy('id')->limit($limit)->get()->getResultArray(), 'serial');
    }

    /** Table / room names for the seat picker, with hourly rates. */
    public function tables(): array
    {
        return db_connect()->table('dining_tables')->select('id, name, area, seats, hourly_rate')->where('business_id', $this->bid())
            ->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
    }

    /**
     * @param list<array<string, mixed>> $rows products rows
     * @return list<array<string, mixed>>
     */
    private function shape(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $vars = [];
        foreach (db_connect()->table('product_variants')->select('id, product_id, name, sku, price_delta, attributes, stock_qty')
            ->where('business_id', $this->bid())->whereIn('product_id', $ids)->orderBy('sort_order')->get()->getResultArray() as $v) {
            $attr = json_decode((string) ($v['attributes'] ?? '{}'), true) ?: [];
            $vars[(int) $v['product_id']][] = [
                'id' => (int) $v['id'], 'name' => (string) $v['name'], 'delta' => (float) $v['price_delta'],
                'stock' => $v['stock_qty'] !== null ? (float) $v['stock_qty'] : null, 'mult' => max(1.0, (float) ($attr['mult'] ?? 1)),
                'dims' => array_diff_key($attr, ['mult' => 1]),
            ];
        }
        $features = (array) ($this->business['settings']['features'] ?? []);
        $stockOn = (bool) ($this->business['stock_enabled'] ?? false);
        // batch stock: next expiry / expired stock for the tile badge (Pharmacy)
        $batchIds = array_map(static fn ($r): int => (int) $r['id'], array_values(array_filter($rows, static fn ($r): bool => ($r['stock_type'] ?? '') === 'batch')));
        $expiry = $stockOn && $batchIds !== [] ? (new \App\Modules\Pharmacy\Services\PharmacyService($this->bid(), $this->business))->expiryFor($batchIds) : [];
        $low = (float) ($this->business['low_stock_alert'] ?? 0);
        $out = [];
        foreach ($rows as $r) {
            $tracked = $stockOn && $r['stock_type'] !== 'none';
            $unit = (string) ($r['unit'] ?? '');
            $weighed = ! empty($features['weighted']) && in_array(strtolower($unit), ['kg', 'g', 'gm', 'l', 'ltr', 'litre', 'm', 'ft'], true);
            $out[] = [
                'id' => (int) $r['id'], 'cat' => (int) ($r['category_id'] ?? 0), 'name' => (string) $r['name'], 'sku' => (string) ($r['sku'] ?? ''),
                'code' => (string) ($r['barcode'] ?? ''), 'generic' => (string) ($r['generic_name'] ?? ''), 'brand' => (string) ($r['brand'] ?? ''),
                'unit' => $unit, 'img' => $r['image_path'] ? base_url((string) $r['image_path']) : null,
                'price' => (float) $r['price'], 'compare' => $r['compare_at'] !== null ? (float) $r['compare_at'] : null,
                'type' => (string) $r['stock_type'], 'tracked' => $tracked, 'stock' => $tracked ? (float) $r['stock_qty'] : null,
                'low' => $tracked && (float) $r['stock_qty'] > 0 && (float) $r['stock_qty'] <= (float) ($r['low_stock_at'] ?? $low),
                'featured' => (bool) $r['is_featured'], 'badge' => (string) ($r['badge'] ?? ''), 'rx' => (bool) $r['is_rx'],
                'duration' => (int) ($r['duration_min'] ?? 0), 'warranty' => (int) ($r['warranty_months'] ?? 0),
                'decimal' => $weighed || ! empty($features['decimalQty']), 'weighed' => $weighed,
                'variants' => $vars[(int) $r['id']] ?? [],
            ];
            if (isset($expiry[(int) $r['id']])) {
                $e = $expiry[(int) $r['id']];
                $last = count($out) - 1;
                $out[$last]['exp'] = $e['next'];
                $out[$last]['expired'] = $e['expired'];
                if ($e['near'] && $e['next'] !== null) {
                    $out[$last]['badge'] = 'Exp ' . date('m/y', (int) strtotime($e['next']));
                } elseif ($e['next'] === null && $e['expired'] > 0) {
                    $out[$last]['badge'] = 'Expired stock';
                }
            }
        }

        return $out;
    }
}
