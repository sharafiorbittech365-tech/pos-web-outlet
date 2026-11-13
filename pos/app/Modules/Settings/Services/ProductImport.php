<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Setup › Outlets › Import products: copy products from one outlet into another of the SAME business
 * type, so a new outlet starts with the catalogue it needs.
 *
 * A copy carries everything that describes the product: details, prices, VAT, its category (created in
 * the target when missing), options, pictures (duplicated, so removing one in either outlet never breaks
 * the other), warranty parts, attributes and online-store details. Stock is never copied — the
 * person sets each product's opening stock (per option for size × colour products), recorded as an
 * "opening" stock movement like the product form does. Serial- and batch-tracked products arrive with no
 * stock (serials and batches are physical units: receive them through Purchases or Inventory).
 * Products the target already has (same name and SKU) are skipped, so importing twice adds nothing twice.
 */
final class ProductImport
{
    /** Most products one import takes (one request, one transaction). */
    public const MAX = 5000;

    /** Opening stock above this is a typing slip. */
    private const MAX_QTY = 9_999_999;

    private function tid(): int
    {
        return service('tenant')->tenantId();
    }

    /**
     * An outlet the signed-in person can open (open outlets only).
     *
     * @return array<string, mixed> id, name, business_type, type_name, type_icon, area
     */
    public function outlet(int $id): array
    {
        foreach (service('posSession')->outlets() as $o) {
            if ((int) $o['id'] === $id) {
                return $o;
            }
        }

        throw new NotFoundException('Outlet not found, closed, or not one you can open.');
    }

    /**
     * Outlets the person can open with the same business type as the target, with their product count.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(int $targetId): array
    {
        $target = $this->outlet($targetId);
        $out = array_values(array_filter(service('posSession')->outlets(), static fn (array $o): bool => (int) $o['id'] !== $targetId && (string) $o['business_type'] === (string) $target['business_type']));
        if ($out === []) {
            return [];
        }
        $counts = [];
        foreach (db_connect()->table('products')->select('business_id, COUNT(*) AS n')->where('tenant_id', $this->tid())->whereIn('business_id', array_map(static fn (array $o): int => (int) $o['id'], $out))
            ->where('deleted_at', null)->groupBy('business_id')->get()->getResultArray() as $c) {
            $counts[(int) $c['business_id']] = (int) $c['n'];
        }
        foreach ($out as &$o) {
            $o['products'] = $counts[(int) $o['id']] ?? 0;
        }

        return $out;
    }

    /**
     * Both outlets, checked: open, the person can open both, same business type, not the same outlet.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [source, target]
     */
    public function pair(int $sourceId, int $targetId): array
    {
        $target = $this->outlet($targetId);
        $source = $this->outlet($sourceId);
        if ($sourceId === $targetId) {
            throw new BusinessRuleException('Choose a different outlet to copy from.');
        }
        if ((string) $source['business_type'] !== (string) $target['business_type']) {
            throw new BusinessRuleException('Products can be imported only between outlets of the same business type (' . $source['type_name'] . ' ≠ ' . $target['type_name'] . ').');
        }

        return [$source, $target];
    }

    /**
     * The source outlet's products for the picker: price, stock, options, and whether the target already has it.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(int $sourceId, int $targetId): array
    {
        $this->pair($sourceId, $targetId);
        $db = db_connect();
        $rows = $db->table('products p')->select('p.id, p.name, p.sku, p.barcode, p.unit, p.icon, p.image_path, p.price, p.stock_type, p.stock_qty, p.is_active, c.name AS category')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.tenant_id', $this->tid())->where('p.business_id', $sourceId)->where('p.deleted_at', null)
            ->orderBy('c.name')->orderBy('p.name')->limit(self::MAX)->get()->getResultArray();
        if ($rows === []) {
            return [];
        }
        $variants = [];
        foreach ($db->table('product_variants')->select('id, product_id, name, stock_qty')->where('business_id', $sourceId)
            ->whereIn('product_id', array_column($rows, 'id'))->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $v) {
            $variants[(int) $v['product_id']][] = ['id' => (int) $v['id'], 'name' => (string) $v['name'], 'stock' => (float) ($v['stock_qty'] ?? 0)];
        }
        $have = $this->existingKeys($targetId);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['variants'] = $r['stock_type'] === 'variant' ? ($variants[$r['id']] ?? []) : [];
            $r['exists'] = isset($have[self::key((string) $r['name'], (string) ($r['sku'] ?? ''))]);
        }

        return $rows;
    }

    /**
     * Copy the chosen products. $items: product id => ['q' => opening stock] or, for size × colour
     * products, ['v' => [variant id => opening stock]].
     *
     * @param array<int|string, mixed> $items
     * @return array{imported: int, skipped: int, categories: int, stock: int}
     */
    public function import(int $sourceId, int $targetId, array $items): array
    {
        [$source, $target] = $this->pair($sourceId, $targetId);
        $want = [];
        foreach ($items as $pid => $it) {
            if ((int) $pid > 0) {
                $want[(int) $pid] = is_array($it) ? $it : ['q' => $it];
            }
        }
        if ($want === []) {
            throw new ValidationException('Select at least one product to import.', ['items' => 'Select at least one product.']);
        }
        if (count($want) > self::MAX) {
            throw new ValidationException('Import at most ' . self::MAX . ' products at a time.', ['items' => 'Too many products.']);
        }
        @set_time_limit(300);
        $tid = $this->tid();

        $res = Tx::run(function (BaseConnection $db) use ($tid, $sourceId, $targetId, $want): array {
            $now = date('Y-m-d H:i:s');
            $res = ['imported' => 0, 'skipped' => 0, 'categories' => 0, 'stock' => 0];
            $products = $db->table('products')->where('tenant_id', $tid)->where('business_id', $sourceId)->where('deleted_at', null)
                ->whereIn('id', array_keys($want))->orderBy('id')->get()->getResultArray();
            $res['skipped'] = count($want) - count($products);
            $have = $this->existingKeys($targetId);
            $ctx = ['cats' => [], 'attrs' => [], 'media' => [], 'slugs' => []];

            foreach ($products as $p) {
                $key = self::key((string) $p['name'], (string) ($p['sku'] ?? ''));
                if (isset($have[$key])) {
                    $res['skipped']++;
                    continue;
                }
                $have[$key] = true;
                $res['stock'] += $this->copyProduct($db, $tid, $sourceId, $targetId, $p, $want[(int) $p['id']], $ctx, $now, $res);
                $res['imported']++;
            }

            return $res;
        });

        service('audit')->activity('Products imported', $res['imported'] . ' from ' . $source['name'] . ' into ' . $target['name'] . ($res['skipped'] > 0 ? ' · ' . $res['skipped'] . ' skipped' : ''));
        service('usage')->refresh($tid);

        return $res;
    }

    /** @return int how many stock movements (opening stock lines) were written */
    private function copyProduct(BaseConnection $db, int $tid, int $sourceId, int $targetId, array $p, array $want, array &$ctx, string $now, array &$res): int
    {
        $srcId = (int) $p['id'];
        $type = (string) $p['stock_type'];
        $opening = $type === 'simple' ? self::qty($want['q'] ?? 0) : 0.0;

        $variants = $db->table('product_variants')->where('business_id', $sourceId)->where('product_id', $srcId)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        $vQty = [];
        if ($type === 'variant') {
            $given = is_array($want['v'] ?? null) ? $want['v'] : [];
            foreach ($variants as $v) {
                $vQty[(int) $v['id']] = self::qty($given[(string) $v['id']] ?? $given[(int) $v['id']] ?? 0);
            }
        }

        // the product row: every column, re-pointed at the target outlet; stock starts at the opening stock
        $row = $p;
        unset($row['id'], $row['deleted_at']);
        $row['tenant_id'] = $tid;
        $row['business_id'] = $targetId;
        $row['category_id'] = $p['category_id'] !== null ? $this->category($db, $tid, $sourceId, $targetId, (int) $p['category_id'], $ctx, $now, $res) : null;
        $row['image_path'] = $this->media($db, $p['image_path'], $sourceId, $targetId, $ctx);
        $row['gallery'] = $this->gallery($db, $p['gallery'], $sourceId, $targetId, $ctx);
        $row['stock_qty'] = $type === 'variant' ? array_sum($vQty) : $opening;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $db->table('products')->insert($row);
        $pid = (int) $db->insertID();

        $moves = 0;
        foreach ($variants as $v) {
            $vid = (int) $v['id'];
            $nv = $v;
            unset($nv['id']);
            $nv['tenant_id'] = $tid;
            $nv['business_id'] = $targetId;
            $nv['product_id'] = $pid;
            $nv['image_path'] = $this->media($db, $v['image_path'], $sourceId, $targetId, $ctx);
            $nv['stock_qty'] = $type === 'variant' ? $vQty[$vid] : ($v['stock_qty'] === null ? null : 0);
            $db->table('product_variants')->insert($nv);
            $newVid = (int) $db->insertID();
            if ($type === 'variant' && $vQty[$vid] > 0) {
                $this->opening($db, $tid, $targetId, $pid, $newVid, $vQty[$vid], 'Opening stock · ' . $v['name'], $now);
                $moves++;
            }
        }
        if ($type === 'simple' && $opening > 0) {
            $this->opening($db, $tid, $targetId, $pid, null, $opening, 'Opening stock', $now);
            $moves++;
        }

        foreach ($db->table('product_images')->where('business_id', $sourceId)->where('product_id', $srcId)->orderBy('sort_order')->get()->getResultArray() as $img) {
            $db->table('product_images')->insert([
                'tenant_id' => $tid, 'business_id' => $targetId, 'product_id' => $pid, 'path' => (string) $this->media($db, $img['path'], $sourceId, $targetId, $ctx),
                'thumb_path' => $this->media($db, $img['thumb_path'], $sourceId, $targetId, $ctx), 'alt' => $img['alt'], 'sort_order' => $img['sort_order'], 'created_at' => $now,
            ]);
        }
        foreach ($db->table('product_warranties')->where('product_id', $srcId)->get()->getResultArray() as $w) {
            $db->table('product_warranties')->insert(['product_id' => $pid, 'part' => $w['part'], 'months' => $w['months']]);
        }
        foreach ($db->table('product_attributes')->where('product_id', $srcId)->get()->getResultArray() as $a) {
            $attr = $this->attribute($db, $tid, $sourceId, $targetId, (int) $a['attribute_id'], $ctx, $now);
            if ($attr !== null) {
                unset($a['id']);
                $db->table('product_attributes')->insert(['product_id' => $pid, 'attribute_id' => $attr] + $a);
            }
        }
        $d = $db->table('product_details')->where('business_id', $sourceId)->where('product_id', $srcId)->get()->getRowArray();
        if ($d !== null) {
            $d['product_id'] = $pid;
            $d['tenant_id'] = $tid;
            $d['business_id'] = $targetId;
            $d['slug'] = $d['slug'] === null || $d['slug'] === '' ? $d['slug'] : $this->freeSlug($db, $targetId, (string) $d['slug'], $ctx);
            $d['updated_at'] = $now;
            $db->table('product_details')->insert($d);
        }

        return $moves;
    }

    private function opening(BaseConnection $db, int $tid, int $bid, int $pid, ?int $vid, float $qty, string $note, string $now): void
    {
        $db->table('stock_movements')->insert([
            'tenant_id' => $tid, 'business_id' => $bid, 'product_id' => $pid, 'variant_id' => $vid, 'qty_change' => $qty, 'qty_after' => $qty,
            'reason' => 'opening', 'ref_type' => 'manual', 'user_id' => service('tenant')->userId(), 'note' => mb_substr($note, 0, 255), 'created_at' => $now,
        ]);
    }

    /** The target's category for a source category: same name under the same parent, created when missing (parents first). */
    private function category(BaseConnection $db, int $tid, int $sourceId, int $targetId, int $catId, array &$ctx, string $now, array &$res): ?int
    {
        if (array_key_exists($catId, $ctx['cats'])) {
            return $ctx['cats'][$catId];
        }
        $c = $db->table('categories')->where('id', $catId)->where('business_id', $sourceId)->get()->getRowArray();
        if ($c === null) {
            return $ctx['cats'][$catId] = null;
        }
        $ctx['cats'][$catId] = null; // a parent loop stops here
        $parent = $c['parent_id'] !== null ? $this->category($db, $tid, $sourceId, $targetId, (int) $c['parent_id'], $ctx, $now, $res) : null;
        $q = $db->table('categories')->select('id')->where('business_id', $targetId)->where('name', (string) $c['name']); // case-insensitive collation
        $parent === null ? $q->where('parent_id', null) : $q->where('parent_id', $parent);
        $found = $q->limit(1)->get()->getRow('id');
        if ($found !== null) {
            return $ctx['cats'][$catId] = (int) $found;
        }
        $db->table('categories')->insert(['tenant_id' => $tid, 'business_id' => $targetId, 'parent_id' => $parent, 'code' => $c['code'], 'name' => $c['name'], 'icon' => $c['icon'],
            'sort_order' => $c['sort_order'], 'created_at' => $now, 'updated_at' => $now]);
        $res['categories']++;

        return $ctx['cats'][$catId] = (int) $db->insertID();
    }

    /** The target's attribute definition for a source one (matched by code, copied when missing). */
    private function attribute(BaseConnection $db, int $tid, int $sourceId, int $targetId, int $attrId, array &$ctx, string $now): ?int
    {
        if (array_key_exists($attrId, $ctx['attrs'])) {
            return $ctx['attrs'][$attrId];
        }
        $a = $db->table('product_attribute_defs')->where('id', $attrId)->where('business_id', $sourceId)->get()->getRowArray();
        if ($a === null) {
            return $ctx['attrs'][$attrId] = null;
        }
        $found = $db->table('product_attribute_defs')->select('id')->where('business_id', $targetId)->where('code', $a['code'])->limit(1)->get()->getRow('id');
        if ($found !== null) {
            return $ctx['attrs'][$attrId] = (int) $found;
        }
        unset($a['id']);
        $db->table('product_attribute_defs')->insert(['tenant_id' => $tid, 'business_id' => $targetId, 'created_at' => $now] + $a);

        return $ctx['attrs'][$attrId] = (int) $db->insertID();
    }

    /**
     * A picture for the target outlet: a /media address of the source outlet is duplicated into the target
     * (its own site_media row), so either outlet can remove its copy safely. Other addresses stay as they are.
     */
    private function media(BaseConnection $db, mixed $address, int $sourceId, int $targetId, array &$ctx): ?string
    {
        $a = $address === null ? '' : (string) $address;
        if ($a === '' || preg_match('#/media/(\d+)-([a-f0-9]{16})\.([a-z]{3,4})$#', $a, $m) !== 1) {
            return $address === null ? null : $a;
        }
        if (isset($ctx['media'][$a])) {
            return $ctx['media'][$a];
        }
        $db->query('INSERT INTO site_media (tenant_id, business_id, slot, mime, ext, size, hash, data, created_at)
            SELECT tenant_id, ?, slot, mime, ext, size, hash, data, ? FROM site_media WHERE id = ? AND business_id = ?', [$targetId, date('Y-m-d H:i:s'), (int) $m[1], $sourceId]);
        if ($db->affectedRows() !== 1) {
            return $ctx['media'][$a] = $a; // not this outlet's picture: keep the address
        }

        return $ctx['media'][$a] = preg_replace('#/media/\d+-#', '/media/' . (int) $db->insertID() . '-', $a);
    }

    private function gallery(BaseConnection $db, mixed $gallery, int $sourceId, int $targetId, array &$ctx): ?string
    {
        $list = is_string($gallery) && $gallery !== '' ? json_decode($gallery, true) : null;
        if (! is_array($list)) {
            return $gallery === null ? null : (string) $gallery;
        }

        return json_encode(array_map(fn ($a): mixed => is_string($a) ? $this->media($db, $a, $sourceId, $targetId, $ctx) : $a, $list), JSON_UNESCAPED_SLASHES);
    }

    private function freeSlug(BaseConnection $db, int $targetId, string $slug, array &$ctx): string
    {
        $base = mb_substr($slug, 0, 150);
        for ($i = 1, $s = $slug; isset($ctx['slugs'][$s]) || $db->table('product_details')->where('business_id', $targetId)->where('slug', $s)->countAllResults() > 0; $i++) {
            $s = $base . '-' . ($i + 1);
        }
        $ctx['slugs'][$s] = true;

        return $s;
    }

    /** @return array<string, true> name|sku keys of the outlet's products */
    private function existingKeys(int $bid): array
    {
        $out = [];
        foreach (db_connect()->table('products')->select('name, sku')->where('business_id', $bid)->where('deleted_at', null)->get()->getResultArray() as $r) {
            $out[self::key((string) $r['name'], (string) ($r['sku'] ?? ''))] = true;
        }

        return $out;
    }

    private static function key(string $name, string $sku): string
    {
        return mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($sku));
    }

    private static function qty(mixed $v): float
    {
        $n = is_numeric($v) ? (float) $v : 0.0;

        return round(max(0.0, min((float) self::MAX_QTY, $n)), 3);
    }
}
