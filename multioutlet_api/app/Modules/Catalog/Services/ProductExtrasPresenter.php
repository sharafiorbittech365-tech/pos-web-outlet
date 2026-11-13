<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

/**
 * The rich product information (details, pictures with alt text, attributes, supplier, packaging, customization,
 * review summary) for the product detail response. Products without any of it simply get empty values.
 */
final class ProductExtrasPresenter
{
    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> product id => fields to merge into the product
     */
    public static function forProducts(array $ids, bool $detail): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $db = db_connect();
        $json = static fn (mixed $v): array => is_array($a = json_decode((string) $v, true)) ? $a : [];
        $url = static fn (?string $p): ?string => ProductPresenter::imageUrl($p);

        // thumbnail of the main picture (lists) and every picture (detail)
        $imgs = [];
        foreach ($db->table('product_images')->select('product_id, path, thumb_path, alt')->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $r) {
            $imgs[(int) $r['product_id']][] = $r;
        }
        foreach ($ids as $id) {
            $first = $imgs[$id][0] ?? null;
            $out[$id] = ['thumb' => $first !== null ? $url((string) ($first['thumb_path'] ?: $first['path'])) : null];
        }
        // review summary (approved only)
        foreach ($db->table('product_reviews')->select('product_id, COUNT(*) AS n, AVG(rating) AS avg')->whereIn('product_id', $ids)->where('status', 'approved')->groupBy('product_id')->get()->getResultArray() as $r) {
            $out[(int) $r['product_id']]['rating'] = ['average' => round((float) $r['avg'], 1), 'count' => (int) $r['n']];
        }
        if (! $detail) {
            return $out;
        }

        $id = $ids[0];
        $out[$id]['image_items'] = array_map(static fn (array $r): array => ['url' => $url((string) $r['path']), 'thumb' => $url((string) ($r['thumb_path'] ?: $r['path'])), 'alt' => (string) ($r['alt'] ?? '')], $imgs[$id] ?? []);
        $d = $db->table('product_details')->where('product_id', $id)->get()->getRowArray() ?? [];
        $cur = trim((string) ($d['currency'] ?? ''));
        $out[$id] += [
            'slug' => $d['slug'] ?? null,
            'short_description' => ($d['short_description'] ?? '') !== '' ? $d['short_description'] : null,
            'price_range' => isset($d['price_min']) || isset($d['price_max']) ? ['min' => isset($d['price_min']) ? (float) $d['price_min'] : null, 'max' => isset($d['price_max']) ? (float) $d['price_max'] : null, 'currency' => $cur !== '' ? $cur : null] : null,
            'sample_price' => isset($d['sample_price']) ? (float) $d['sample_price'] : null,
            'moq' => isset($d['moq']) ? (int) $d['moq'] : null,
            'country_origin' => ($d['country_origin'] ?? '') !== '' ? $d['country_origin'] : null,
            'video_url' => ($d['video_url'] ?? '') !== '' ? $d['video_url'] : null,
            'seo' => ['title' => $d['seo_title'] ?? null, 'description' => $d['seo_description'] ?? null, 'keywords' => $d['seo_keywords'] ?? null],
            'supplier' => $json($d['supplier'] ?? null) ?: null,
            'packaging' => $json($d['packaging'] ?? null) ?: null,
            'customizations' => array_values(array_filter($json($d['customizations'] ?? null), static fn ($c): bool => is_array($c) && ! empty($c['enabled']))),
        ];
        $attrs = [];
        foreach ($db->table('product_attributes a')->select('d.name, d.code, d.type, a.value_text, a.value_num, COALESCE(a.unit, d.unit) AS unit')->join('product_attribute_defs d', 'd.id = a.attribute_id')
            ->where('a.product_id', $id)->orderBy('a.sort_order')->orderBy('a.id')->get()->getResultArray() as $a) {
            $value = (string) $a['value_text'];
            $list = null;
            if ($a['type'] === 'multiselect' && is_array($l = json_decode($value, true))) {
                $list = array_values(array_map('strval', $l));
                $value = implode(', ', $list);
            }
            $attrs[] = ['name' => (string) $a['name'], 'code' => (string) $a['code'], 'type' => (string) $a['type'], 'value' => $value, 'values' => $list,
                'number' => $a['value_num'] !== null ? (float) $a['value_num'] : null, 'unit' => ($a['unit'] ?? '') !== '' ? (string) $a['unit'] : null];
        }
        $out[$id]['attributes'] = $attrs;

        return $out;
    }

    /** @return list<array<string, mixed>> approved reviews of a product, newest first */
    public static function reviews(int $productId, int $limit, int $offset): array
    {
        $rows = db_connect()->table('product_reviews')->select('id, author, rating, title, body, images, verified_purchase, created_at')->where('product_id', $productId)->where('status', 'approved')
            ->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'author' => (string) $r['author'], 'rating' => (int) $r['rating'], 'title' => $r['title'], 'body' => $r['body'],
            'images' => array_values(array_filter(array_map(static fn ($p): ?string => is_string($p) ? ProductPresenter::imageUrl($p) : null, (array) (json_decode((string) $r['images'], true) ?: [])))),
            'verified_purchase' => (bool) $r['verified_purchase'], 'created_at' => (string) $r['created_at'],
        ], $rows);
    }
}
