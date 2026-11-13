<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\Products\Config\SizeScales;
use OmniPOS\Core\Services\VariantMatrix;

/**
 * Size x colour option generator of the product form (footwear, clothing): turns a size list
 * (a scale from Config\SizeScales or typed, ranges like "36-45" allowed) and a colour list into
 * option rows — name "41 / Black", attributes {Size, Colour}, a unique SKU per option (also the
 * scannable code on its label) and the opening stock per option.
 */
final class MatrixGenerator
{
    public function __construct(private readonly int $businessId)
    {
    }

    public static function config(): SizeScales
    {
        return config(SizeScales::class) ?? new SizeScales();
    }

    /**
     * @param array{sizes?: string, colours?: string, scale?: string, sku?: string, stock?: mixed, price_delta?: mixed} $in
     * @param list<string> $existingNames option names already on the product (skipped)
     * @return list<array{id: int, name: string, sku: ?string, price_delta: float, attributes: array<string, string>, stock: float}>
     */
    public function build(array $in, string $productSku, array $existingNames = [], ?int $productId = null): array
    {
        $sizesText = trim((string) ($in['sizes'] ?? ''));
        $scale = (string) ($in['scale'] ?? '');
        if ($sizesText === '' && isset(self::config()->scales[$scale])) {
            $sizesText = self::config()->scales[$scale]['sizes'];
        }
        $sizes = VariantMatrix::parseList($sizesText);
        $colours = VariantMatrix::parseList((string) ($in['colours'] ?? ''), 48);
        $dims = array_filter(['Size' => $sizes, 'Colour' => $colours]);
        $combos = VariantMatrix::combos($dims);
        if ($combos === []) {
            return [];
        }
        $base = trim((string) ($in['sku'] ?? '')) ?: $productSku;
        $have = array_map('mb_strtolower', $existingNames);
        $stock = max(0.0, (float) ($in['stock'] ?? 0));
        $delta = round((float) ($in['price_delta'] ?? 0), 2);
        $rows = [];
        $skus = [];
        foreach ($combos as $c) {
            $name = VariantMatrix::name($c);
            if (in_array(mb_strtolower($name), $have, true)) {
                continue;
            }
            $sku = $base !== '' ? VariantMatrix::sku($base, $c) : null;
            if ($sku !== null) {
                $skus[] = $sku;
            }
            $rows[] = ['id' => 0, 'name' => $name, 'sku' => $sku, 'price_delta' => $delta, 'attributes' => $c, 'stock' => $stock];
        }

        return $this->uniqueSkus($rows, $skus, $productId);
    }

    /**
     * A generated SKU that is already used in the outlet (product SKU / barcode or another
     * option's SKU) gets a numeric suffix.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $skus
     * @return list<array<string, mixed>>
     */
    private function uniqueSkus(array $rows, array $skus, ?int $productId): array
    {
        if ($skus === []) {
            return $rows;
        }
        $db = db_connect();
        $taken = [];
        $q = $db->table('products')->select('sku, barcode')->where('business_id', $this->businessId)->where('deleted_at', null)
            ->groupStart()->whereIn('sku', $skus)->orWhereIn('barcode', $skus)->groupEnd();
        foreach ($q->get()->getResultArray() as $r) {
            $taken[mb_strtolower((string) $r['sku'])] = true;
            $taken[mb_strtolower((string) $r['barcode'])] = true;
        }
        $vq = $db->table('product_variants')->select('sku')->where('business_id', $this->businessId)->whereIn('sku', $skus);
        if ($productId !== null) {
            // the product's own options are being replaced by name, not by SKU
            $vq->where('product_id <>', $productId);
        }
        foreach ($vq->get()->getResultArray() as $r) {
            $taken[mb_strtolower((string) $r['sku'])] = true;
        }
        foreach ($rows as &$r) {
            if ($r['sku'] === null) {
                continue;
            }
            $sku = (string) $r['sku'];
            for ($i = 2; isset($taken[mb_strtolower($sku)]); $i++) {
                $sku = mb_substr((string) $r['sku'], 0, 60) . '-' . $i;
            }
            $taken[mb_strtolower($sku)] = true;
            $r['sku'] = $sku;
        }
        unset($r);

        return $rows;
    }
}
