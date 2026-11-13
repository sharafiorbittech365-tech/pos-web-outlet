<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Services;

use App\Modules\Discovery\Models\ListingsModel;
use App\Modules\Shops\Services\ListedShops;
use Config\Geo as GeoConfig;

/**
 * Home & search: listings near the chosen location with filters, sort and server-side paging.
 * Shop details for each card come from the cached listed-shops set (no query per row).
 */
final class DiscoveryService
{
    public function __construct(private readonly ListedShops $shops, private readonly ListingsModel $listings = new ListingsModel())
    {
    }

    /**
     * Normalise the query-string filters.
     *
     * @return array{q: string, kind: string, category: string, radius: float, sort: string, in_stock: bool, page: int}
     */
    public static function filters(array $get, ?array $loc): array
    {
        $chips = config(GeoConfig::class)->radiusChips;
        $radius = isset($get['radius']) && is_numeric($get['radius']) ? (float) $get['radius'] : (float) config(GeoConfig::class)->defaultRadiusKm;
        if (! in_array((int) $radius, $chips, true)) {
            $radius = max(0.0, min(200.0, $radius));
        }

        return [
            'q' => mb_substr(trim((string) ($get['q'] ?? '')), 0, 80),
            'kind' => in_array($get['kind'] ?? '', ['product', 'service'], true) ? $get['kind'] : '',
            'category' => mb_substr(trim((string) ($get['category'] ?? '')), 0, 100),
            'radius' => $loc !== null ? $radius : 0.0,
            'sort' => in_array($get['sort'] ?? '', ['distance', 'price', '-price'], true) ? $get['sort'] : 'distance',
            'in_stock' => ! empty($get['stock']),
            'page' => max(1, (int) ($get['page'] ?? 1)),
        ];
    }

    /**
     * @param list<int>|null $onlyShops limit to these shops (seller page)
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int, categories: list<string>}
     */
    public function search(array $filters, ?array $loc, ?array $onlyShops = null, int $perPage = ListingsModel::PER_PAGE): array
    {
        $all = $this->shops->all();
        $ids = $onlyShops ?? array_keys($all);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => isset($all[$id])));
        $f = [
            'ids' => $ids,
            'service_ids' => array_keys(array_filter($all, static fn (array $s): bool => $s['is_service_shop'])),
            'nostock_ids' => array_keys(array_filter($all, static fn (array $s): bool => ! $s['stock_enabled'])),
            'name_ids' => $filters['q'] !== '' ? array_values(array_intersect($this->shops->matchingName($filters['q']), $ids)) : [],
            'lat' => $loc['lat'] ?? null, 'lng' => $loc['lng'] ?? null, 'radius' => $filters['radius'],
            'q' => $filters['q'], 'kind' => $filters['kind'], 'category' => $filters['category'], 'in_stock' => $filters['in_stock'], 'sort' => $filters['sort'],
        ];
        $res = $ids === [] ? ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1] : $this->listings->search($f, $filters['page'], $perPage);
        $at = [];
        $items = [];
        foreach ($res['rows'] as $r) {
            $bid = (int) $r['business_id'];
            $at[$bid] ??= ListedShops::at($all[$bid], $loc);
            $items[] = self::card($r, $at[$bid]);
        }

        return [
            'items' => $items, 'total' => $res['total'], 'page' => $res['page'], 'per_page' => $res['per_page'], 'last_page' => $res['last_page'],
            'categories' => $ids === [] ? [] : $this->listings->categories($f),
        ];
    }

    /** Absolute URL of a product picture (uploads live on the POS app); null when it has none. */
    public static function imageUrl(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim(config(\OmniPOS\Core\Config\OmniPOS::class)->posUrl, '/') . '/' . ltrim($path, '/');
    }

    /** One listing card: what the customer pays (VAT rule applied), stock state, kind. */
    public static function card(array $r, array $shop): array
    {
        $rule = ListedShops::taxPolicy($shop)->ruleFor((string) $r['tax_mode'], $r['tax_rate'] !== null ? (float) $r['tax_rate'] : null);
        $tracked = $shop['stock_enabled'] && $r['stock_type'] !== 'none';
        $stock = $tracked ? (float) $r['stock_qty'] : null;
        $service = (bool) ($r['is_service'] ?? ((int) ($r['duration_min'] ?? 0) > 0 || ($shop['is_service_shop'] && $r['stock_type'] === 'none')));

        return [
            'id' => (int) $r['id'], 'business_id' => (int) $r['business_id'], 'name' => $r['name'], 'brand' => $r['brand'], 'category' => $r['category'] ?? '',
            'kind' => $service ? 'service' : 'product', 'price' => round($rule->grossPrice((float) $r['price']), 2),
            'compare_at' => $r['compare_at'] !== null && (float) $r['compare_at'] > (float) $r['price'] ? round($rule->grossPrice((float) $r['compare_at']), 2) : null,
            'tax_label' => $rule->label(), 'currency' => $shop['currency'], 'image_path' => $r['image_path'], 'image_url' => self::imageUrl($r['image_path']), 'icon' => $r['icon'],
            'in_stock' => $stock === null || $stock > 0, 'stock' => $stock, 'duration_min' => $r['duration_min'] !== null ? (int) $r['duration_min'] : null,
            'is_rx' => (bool) $r['is_rx'], 'badge' => $r['badge'], 'unit' => $r['unit'] ?? null,
            'shop' => $shop, 'distance_km' => $shop['distance_km'] ?? null,
        ];
    }
}
