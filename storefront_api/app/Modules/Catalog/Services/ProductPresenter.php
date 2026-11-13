<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use OmniPOS\Core\Pricing\TaxPolicy;

/**
 * Public (snake_case) shape of a product for one outlet. Prices are what the customer pays:
 * VAT that is added on top (exclusive rule) is already included; `tax` explains the rule.
 */
final class ProductPresenter
{
    public function __construct(
        private readonly TaxPolicy $policy,
        private readonly int $decimals,
        private readonly bool $stockOn,
    ) {
    }

    public static function forBusiness(array $business): self
    {
        return new self(TaxPolicy::fromBusiness($business), (int) ($business['currency_decimals'] ?? 2), (bool) ($business['stock_enabled'] ?? true));
    }

    /**
     * @param array<string, mixed>       $p          products row
     * @param list<array<string, mixed>> $variants   product_variants rows of this product
     * @param list<array<string, mixed>> $warranties product_warranties rows of this product
     * @return array<string, mixed>
     */
    public function present(array $p, array $variants = [], array $warranties = [], bool $detail = false): array
    {
        $rule = $this->policy->ruleFor((string) $p['tax_mode'], $p['tax_rate'] !== null ? (float) $p['tax_rate'] : null);
        $tracked = $this->isTracked($p);
        $stock = $tracked ? self::num((float) $p['stock_qty']) : null;
        $inStock = ! $tracked || (float) $p['stock_qty'] > 0;

        $vOut = [];
        $dims = [];
        foreach ($variants as $v) {
            $attrs = json_decode((string) ($v['attributes'] ?? ''), true) ?: [];
            $mult = max(1.0, (float) ($attrs['mult'] ?? 1));
            unset($attrs['mult']);
            if ($tracked && (string) $p['stock_type'] === 'variant' && $v['stock_qty'] !== null) {
                $vStock = (float) $v['stock_qty'];
                $vIn = $vStock > 0;
            } else {
                $vStock = null;
                $vIn = ! $tracked || (float) $p['stock_qty'] >= $mult;
            }
            $row = ['id' => (int) $v['id'], 'name' => (string) $v['name'], 'price' => $this->money($rule->grossPrice((float) $p['price'] + (float) $v['price_delta'])), 'in_stock' => $vIn];
            if ($detail) {
                $row['stock'] = $vStock !== null ? self::num($vStock) : null;
                $row['sku'] = ($v['sku'] ?? '') !== '' ? (string) $v['sku'] : null;
                $row['image'] = self::imageUrl($v['image_path'] ?? null);
                $row['attributes'] = (object) $attrs;
            }
            $vOut[] = $row;
            foreach ($attrs as $dim => $val) {
                $dims[(string) $dim][(string) $val] = true;
            }
        }

        $out = [
            'id' => (int) $p['id'],
            'name' => (string) $p['name'],
            'brand' => $p['brand'] !== null && $p['brand'] !== '' ? (string) $p['brand'] : null,
            'category_id' => $p['category_id'] !== null ? (int) $p['category_id'] : null,
            'price' => $this->money($rule->grossPrice((float) $p['price'])),
            'compare_at' => $p['compare_at'] !== null && (float) $p['compare_at'] > 0 ? $this->money($rule->grossPrice((float) $p['compare_at'])) : null,
            'tax' => ['label' => $this->policy->label, 'rate' => self::num($rule->rate), 'mode' => $rule->mode],
            'icon' => $p['icon'] ?: null,
            'image' => self::imageUrl($p['image_path'] ?? null),
            'code' => ($p['sku'] ?? '') !== '' ? (string) $p['sku'] : null,
            'badge' => $p['badge'] ?: null,
            'featured' => (bool) $p['is_featured'],
            'unit' => $p['unit'] ?: null,
            'in_stock' => $inStock,
            'stock' => $stock,
            'duration_min' => $p['duration_min'] !== null && (int) $p['duration_min'] > 0 ? (int) $p['duration_min'] : null,
            'prescription_required' => (bool) $p['is_rx'],
            'generic' => $p['generic_name'] ?: null,
            'warranty' => self::warranties($p, $warranties),
            'variants' => $vOut,
        ];
        if ($detail) {
            $out['description'] = (string) ($p['description'] ?? '');
            // every picture, main first (the first of image_path, then the gallery)
            $pics = array_values(array_filter(array_merge([$p['image_path'] ?? null], (array) (json_decode((string) ($p['gallery'] ?? ''), true) ?: [])), static fn ($x): bool => is_string($x) && $x !== ''));
            $out['images'] = array_values(array_filter(array_map(static fn (string $x): ?string => self::imageUrl($x), $pics)));
            $out['options'] = (object) array_map(static fn (array $vals): array => array_keys($vals), $dims);
        }

        return $out;
    }

    public function isTracked(array $p): bool
    {
        return $this->stockOn && (string) $p['stock_type'] !== 'none';
    }

    public function money(float $n): float
    {
        return round($n, $this->decimals);
    }

    /** 16.000 -> 16, 2.5 -> 2.5 */
    public static function num(float $n): int|float
    {
        return floor($n) === $n && abs($n) < PHP_INT_MAX ? (int) $n : round($n, 3);
    }

    public static function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        // a picture kept in the shared database (site_media) is served by this API
        if (preg_match('#^/?(media/\d+-[a-f0-9]{16}\.(?:png|jpg|webp|gif))$#', $path, $m) === 1) {
            return rtrim(config(\OmniPOS\Core\Config\OmniPOS::class)->apiUrl, '/') . '/' . $m[1];
        }

        return rtrim(config(\OmniPOS\Core\Config\OmniPOS::class)->posUrl, '/') . '/' . ltrim($path, '/');
    }

    /** ["Full product 1 year", "Battery 6 months"] */
    public static function warranties(array $p, array $parts): array
    {
        $out = [];
        if ((int) ($p['warranty_months'] ?? 0) > 0) {
            $out[] = 'Full product ' . self::months((int) $p['warranty_months']);
        }
        foreach ($parts as $w) {
            if ((int) $w['months'] > 0 && trim((string) $w['part']) !== '') {
                $out[] = trim((string) $w['part']) . ' ' . self::months((int) $w['months']);
            }
        }

        return $out;
    }

    private static function months(int $m): string
    {
        if ($m >= 9999) {
            return 'Lifetime';
        }
        if ($m >= 12 && $m % 12 === 0) {
            return ($m / 12) . ' year' . ($m === 12 ? '' : 's');
        }

        return $m . ' month' . ($m === 1 ? '' : 's');
    }
}
