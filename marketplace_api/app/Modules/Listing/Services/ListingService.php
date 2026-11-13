<?php

declare(strict_types=1);

namespace App\Modules\Listing\Services;

use App\Modules\Discovery\Services\DiscoveryService;
use App\Modules\Shops\Services\ListedShops;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * One listing in detail: the product / service of a listed shop with its options (variants),
 * stock, VAT rule, warranty and prescription flag.
 */
final class ListingService
{
    public function __construct(private readonly ListedShops $shops)
    {
    }

    /** @return array<string, mixed> */
    public function find(int $businessId, int $productId, ?array $loc): array
    {
        $shop = ListedShops::at($this->shops->getOrFail($businessId), $loc);
        $db = db_connect();
        $p = $db->table('products p')
            ->select('p.id, p.business_id, p.name, p.brand, p.generic_name, p.unit, p.sku, p.price, p.compare_at, p.tax_mode, p.tax_rate, p.stock_type, p.stock_qty,
                p.duration_min, p.warranty_months, p.image_path, p.icon, p.is_rx, p.badge, p.description, c.name AS category')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.business_id', $businessId)->where('p.id', $productId)
            ->where('p.is_online', 1)->where('p.is_active', 1)->where('p.deleted_at', null)
            ->get()->getRowArray() ?? throw new NotFoundException('This listing is no longer available.');

        $item = DiscoveryService::card($p, $shop);
        $item['generic_name'] = $p['generic_name'];
        $item['sku'] = $p['sku'];
        $item['description'] = (string) ($p['description'] ?? '');
        $item['warranty_months'] = $p['warranty_months'] !== null ? (int) $p['warranty_months'] : null;
        $item['stock_type'] = $p['stock_type'];
        $item['warranties'] = $db->table('product_warranties')->select('part, months')->where('product_id', $productId)->orderBy('months', 'DESC')->get()->getResultArray();

        $rule = ListedShops::taxPolicy($shop)->ruleFor((string) $p['tax_mode'], $p['tax_rate'] !== null ? (float) $p['tax_rate'] : null);
        $item['tax_rule'] = ['label' => $rule->label(), 'mode' => $rule->mode, 'rate' => $rule->rate, 'name' => (string) $shop['tax']['tax_label']];
        $item['variants'] = [];
        foreach ($db->table('product_variants')->select('id, name, price_delta, stock_qty, attributes')->where('business_id', $businessId)->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $v) {
            $stock = $shop['stock_enabled'] && $p['stock_type'] === 'variant' && $v['stock_qty'] !== null ? (float) $v['stock_qty'] : null;
            $attr = json_decode((string) ($v['attributes'] ?? '{}'), true) ?: [];
            $mult = max(1.0, (float) ($attr['mult'] ?? 1));
            // pack sizes (Strip of 10) take mult units of base stock
            if ($stock === null && $shop['stock_enabled'] && $p['stock_type'] !== 'none' && $p['stock_type'] !== 'variant') {
                $stock = floor((float) $p['stock_qty'] / $mult);
            }
            $item['variants'][] = [
                'id' => (int) $v['id'], 'name' => $v['name'], 'price' => round($rule->grossPrice((float) $p['price'] + (float) $v['price_delta']), 2),
                'stock' => $stock, 'in_stock' => $stock === null || $stock > 0,
            ];
        }
        if ($item['variants'] !== [] && $p['stock_type'] === 'variant') {
            $item['in_stock'] = array_filter($item['variants'], static fn (array $v): bool => $v['in_stock']) !== [];
        }
        $item['orderable'] = $item['kind'] === 'product' || ! $shop['takes_bookings'];
        $item['max_qty'] = $p['stock_type'] === 'serial' ? min(5, (int) ($item['stock'] ?? 5)) : ($item['stock'] !== null ? (int) max(1, min(99, $item['stock'])) : 99);

        return $item;
    }
}
