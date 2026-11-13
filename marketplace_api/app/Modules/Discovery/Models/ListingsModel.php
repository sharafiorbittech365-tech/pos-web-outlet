<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Models;

use App\Libraries\Geo;
use OmniPOS\Core\Models\Generated\ProductsModel;

/**
 * Marketplace listings: online products and services of listed shops, read across tenants on
 * purpose (the shop set is already filtered by ModuleAccess, see ListedShops).
 *
 * Distance: bounding-box prefilter on businesses (lat, lng) (index idx_biz_geo), then the exact
 * Haversine distance, computed once per row in SQL. Products are reached through
 * idx_prod_biz_online (business_id, is_online, is_active); text search uses ft_prod_search.
 * One COUNT query and one page query per request.
 */
class ListingsModel extends ProductsModel
{
    public const PER_PAGE = 24;

    /**
     * @param array{ids: list<int>, service_ids: list<int>, nostock_ids: list<int>, name_ids?: list<int>,
     *              lat?: ?float, lng?: ?float, radius?: float, q?: string, kind?: string, category?: string,
     *              in_stock?: bool, sort?: string} $f
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function search(array $f, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$from, $where, $binds, $dist] = $this->parts($f, true);
        $total = (int) ($this->db->query('SELECT COUNT(*) AS n ' . $from . $where, $binds)->getRow('n') ?? 0);
        $last = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $last));
        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }
        $hasLoc = $dist !== 'NULL';
        $order = match ($f['sort'] ?? 'distance') {
            'price' => 'gross ASC, p.id ASC',
            '-price' => 'gross DESC, p.id ASC',
            default => $hasLoc ? 'distance_km IS NULL, distance_km ASC, gross ASC, p.id ASC' : 'p.is_featured DESC, p.business_id ASC, p.id ASC',
        };
        $sql = 'SELECT p.id, p.business_id, p.name, p.brand, p.unit, p.price, p.compare_at, p.tax_mode, p.tax_rate, p.stock_type, p.stock_qty,
                       p.duration_min, p.image_path, p.icon, p.is_rx, p.badge, c.name AS category, '
            . $dist . ' AS distance_km, ' . self::gross() . ' AS gross, ' . $this->serviceExpr($f['service_ids']) . ' AS is_service '
            . $from . $where . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        $rows = $this->db->query($sql, $binds)->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $last];
    }

    /**
     * Category names available for the current place / radius / kind (for the filter select).
     *
     * @return list<string>
     */
    public function categories(array $f): array
    {
        $f['q'] = '';
        $f['category'] = '';
        $f['in_stock'] = false;
        [$from, $where, $binds] = $this->parts($f, true);
        $key = 'mk_cats_' . md5($where . json_encode($binds));
        $list = cache($key);
        if (! is_array($list)) {
            $list = array_column($this->db->query('SELECT DISTINCT c.name ' . $from . $where . ' AND c.name IS NOT NULL ORDER BY c.name LIMIT 200', $binds)->getResultArray(), 'name');
            cache()->save($key, $list, 60);
        }

        return $list;
    }

    /** What the customer pays for one unit (VAT added on top for exclusive rules) — same rule as TaxRule::grossPrice(). */
    public static function gross(): string
    {
        return "(CASE WHEN p.tax_mode = 'exclusive' OR (p.tax_mode = 'default' AND b.tax_inclusive = 0)
                      THEN p.price * (1 + COALESCE(p.tax_rate, b.tax_rate) / 100) ELSE p.price END)";
    }

    /** Haversine distance in km from a point to the shop (b.lat, b.lng). */
    public static function haversine(float $lat, float $lng): string
    {
        $la = sprintf('%.6F', $lat);
        $ln = sprintf('%.6F', $lng);

        return '(' . Geo::EARTH_KM . ' * 2 * ASIN(LEAST(1, SQRT(POW(SIN(RADIANS(b.lat - ' . $la . ') / 2), 2)'
            . ' + COS(RADIANS(' . $la . ')) * COS(RADIANS(b.lat)) * POW(SIN(RADIANS(b.lng - ' . $ln . ') / 2), 2)))))';
    }

    /** @param list<int> $serviceShopIds */
    private function serviceExpr(array $serviceShopIds): string
    {
        $ids = implode(',', array_map('intval', $serviceShopIds ?: [0]));

        return "(p.duration_min > 0 OR (p.business_id IN ({$ids}) AND p.stock_type = 'none'))";
    }

    /**
     * FROM + WHERE shared by the count, page and category queries.
     *
     * @return array{0: string, 1: string, 2: list<mixed>, 3: string}
     */
    private function parts(array $f, bool $withCategories): array
    {
        $binds = [];
        $from = ' FROM products p JOIN businesses b ON b.id = p.business_id' . ($withCategories ? ' LEFT JOIN categories c ON c.id = p.category_id' : '');
        $w = ['p.business_id IN ?', 'p.is_online = 1', 'p.is_active = 1', 'p.deleted_at IS NULL'];
        $binds[] = $f['ids'] !== [] ? array_values(array_map('intval', $f['ids'])) : [0];

        $dist = 'NULL';
        $lat = isset($f['lat']) && $f['lat'] !== null ? (float) $f['lat'] : null;
        $lng = isset($f['lng']) && $f['lng'] !== null ? (float) $f['lng'] : null;
        if ($lat !== null && $lng !== null) {
            $dist = self::haversine($lat, $lng);
            $radius = (float) ($f['radius'] ?? 0);
            if ($radius > 0) {
                $box = Geo::box($lat, $lng, $radius);
                $w[] = 'b.lat BETWEEN ? AND ?';
                $w[] = 'b.lng BETWEEN ? AND ?';
                array_push($binds, $box['minLat'], $box['maxLat'], $box['minLng'], $box['maxLng']);
                $w[] = $dist . ' <= ?';
                $binds[] = $radius;
            }
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $terms = [];
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
                if (mb_strlen($t) >= 3) {
                    $terms[] = '+' . $t . '*';
                }
            }
            $text = $terms !== []
                ? 'MATCH (p.name, p.brand, p.sku, p.generic_name) AGAINST (? IN BOOLEAN MODE)'
                : 'p.name LIKE ?';
            $binds[] = $terms !== [] ? implode(' ', array_slice($terms, 0, 8)) : '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_substr($q, 0, 40)) . '%';
            $nameIds = array_values(array_map('intval', $f['name_ids'] ?? []));
            if ($nameIds !== []) {
                // a search for a shop's name or type also lists that shop's range
                $w[] = '(' . $text . ' OR p.business_id IN ?)';
                $binds[] = $nameIds;
            } else {
                $w[] = $text;
            }
        }

        $kind = (string) ($f['kind'] ?? '');
        if ($kind === 'service' || $kind === 'product') {
            $w[] = ($kind === 'product' ? 'NOT ' : '') . $this->serviceExpr($f['service_ids']);
        }
        if (($f['category'] ?? '') !== '' && $withCategories) {
            $w[] = 'c.name = ?';
            $binds[] = mb_substr((string) $f['category'], 0, 100);
        }
        if (! empty($f['in_stock'])) {
            $noStock = implode(',', array_map('intval', $f['nostock_ids'] ?: [0]));
            $w[] = "(p.stock_type = 'none' OR p.stock_qty > 0 OR p.business_id IN ({$noStock}))";
        }

        return [$from, ' WHERE ' . implode(' AND ', $w), $binds, $dist];
    }
}
