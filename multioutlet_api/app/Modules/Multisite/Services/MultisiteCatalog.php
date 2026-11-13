<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Services;

use App\Libraries\ProductSearch;
use App\Modules\Catalog\Services\ProductPresenter;
use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiEnvelope;

/**
 * One catalogue across the website's authorised outlets.
 *
 * Every outlet keeps its own products rows (own price, VAT rule and stock). The same article in
 * several outlets is shown once: rows are grouped by SKU (case-insensitive), or by name when the
 * SKU is empty. Each item lists its offers — one per outlet that sells it — with that outlet's
 * customer price, stock and options, so the customer sees where it is available.
 *
 * The item id is the lowest product id of the group (stable while that row exists); the detail
 * endpoint accepts any product id of the group. Lists run one count, one page query (grouped)
 * and one query for the page's rows, then batch-load variants, warranties and category names.
 */
final class MultisiteCatalog
{
    private const GK = "IF(p.sku IS NOT NULL AND p.sku <> '', CONCAT('s:', LOWER(p.sku)), CONCAT('n:', LOWER(TRIM(p.name))))";
    private const COLUMNS = 'p.id, p.business_id, p.category_id, p.sku, p.name, p.brand, p.generic_name, p.unit, p.icon, p.image_path, p.price, p.compare_at,'
        . ' p.tax_mode, p.tax_rate, p.stock_type, p.stock_qty, p.duration_min, p.warranty_months, p.badge, p.is_rx, p.is_featured, p.created_at';

    public function __construct(private readonly MultisiteDirectory $dir)
    {
    }

    /** Visible products of the authorised outlets (tenant + outlet filter written out, joined to the outlet). */
    private function visible(): BaseBuilder
    {
        return db_connect()->table('products p')
            ->join('businesses b', 'b.id = p.business_id AND b.tenant_id = p.tenant_id')
            ->where('p.tenant_id', $this->dir->tenantId())->whereIn('p.business_id', $this->dir->outletIds() ?: [0])
            ->where('p.is_active', 1)->where('p.is_online', 1)->where('p.deleted_at', null);
    }

    /**
     * Categories merged by name across the outlets, with the number of distinct items.
     *
     * @return list<array{key: string, name: string, icon: string, products: int}>
     */
    public function categories(): array
    {
        $ids = $this->dir->outletIds();
        if ($ids === []) {
            return [];
        }
        $rows = db_connect()->table('categories c')
            ->select('LOWER(TRIM(c.name)) AS ckey, MIN(c.name) AS name, MIN(c.icon) AS icon, MIN(c.sort_order) AS so, COUNT(DISTINCT ' . self::GK . ') AS products', false)
            ->join('products p', 'p.category_id = c.id AND p.business_id = c.business_id AND p.is_active = 1 AND p.is_online = 1 AND p.deleted_at IS NULL')
            ->where('c.tenant_id', $this->dir->tenantId())->whereIn('c.business_id', $ids)
            ->groupBy('ckey')->orderBy('so', 'ASC')->orderBy('name', 'ASC')->get()->getResultArray();

        return array_map(static fn (array $c): array => ['key' => (string) $c['ckey'], 'name' => (string) $c['name'], 'icon' => (string) ($c['icon'] ?? 'tag'), 'products' => (int) $c['products']], $rows);
    }

    /**
     * @param array<string, mixed> $f q, category (name), outlet (id), in_stock, min_price, max_price, featured, sort
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function products(array $f, int $page, int $perPage): array
    {
        $b = $this->visible();
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            ProductSearch::apply($b, $q);
        }
        $cat = trim((string) ($f['category'] ?? ''));
        if ($cat !== '') {
            if (mb_strlen($cat) > 100) {
                throw new ValidationException('Invalid category.', ['category' => 'Use a category name from GET /v1/multisite.']);
            }
            $b->join('categories c', 'c.id = p.category_id AND c.business_id = p.business_id')->where('LOWER(TRIM(c.name)) = ' . db_connect()->escape(mb_strtolower($cat)), null, false);
        }
        if (isset($f['outlet']) && $f['outlet'] !== '') {
            if (! ctype_digit((string) $f['outlet'])) {
                throw new ValidationException('Invalid outlet.', ['outlet' => 'Use an outlet id from GET /v1/multisite/outlets.']);
            }
            $b->where('p.business_id', (int) $this->dir->outlet((int) $f['outlet'])['id']);
        }
        if (self::flag($f['in_stock'] ?? null)) {
            $b->where("(b.stock_enabled = 0 OR p.stock_type = 'none' OR p.stock_qty > 0)", null, false);
        }
        if (self::flag($f['featured'] ?? null)) {
            $b->where('p.is_featured', 1);
        }
        $gross = ProductSearch::grossPriceSql('p', 'b');
        foreach (['min_price' => '>=', 'max_price' => '<='] as $k => $op) {
            $v = $f[$k] ?? '';
            if ($v === '' || $v === null) {
                continue;
            }
            if (! is_numeric($v) || (float) $v < 0) {
                throw new ValidationException('Invalid price filter.', [$k => 'Use a number of 0 or more.']);
            }
            $b->where("{$gross} {$op} " . number_format((float) $v, 2, '.', ''), null, false);
        }
        $sort = (string) ($f['sort'] ?? '');
        if (! in_array($sort, ['', 'name', 'price', '-price', 'newest'], true)) {
            throw new ValidationException('Unknown sort.', ['sort' => 'Use name, price, -price or newest.']);
        }
        if ($this->dir->outletIds() === []) {
            return ['data' => [], 'meta' => ApiEnvelope::pageMeta(0, $page, $perPage)];
        }

        $count = clone $b;
        $total = (int) ($count->select('COUNT(DISTINCT ' . self::GK . ') AS n', false)->get()->getRow('n') ?? 0);
        $b->select(self::GK . ' AS gk, MIN(p.id) AS rep', false)->groupBy('gk');
        match ($sort) {
            'name' => $b->orderBy('MIN(p.name)', 'ASC', false),
            'price' => $b->orderBy("MIN({$gross})", 'ASC', false),
            '-price' => $b->orderBy("MIN({$gross})", 'DESC', false),
            'newest' => $b->orderBy('MAX(p.created_at)', 'DESC', false),
            default => $b->orderBy('MAX(p.is_featured)', 'DESC', false)->orderBy('MIN(p.name)', 'ASC', false),
        };
        $groups = $b->orderBy('rep', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['data' => $this->present($groups, false), 'meta' => ApiEnvelope::pageMeta($total, $page, $perPage)];
    }

    /**
     * One item with every outlet's offer; $id may be any product id of the group.
     *
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        $row = $this->visible()->select(self::GK . ' AS gk', false)->where('p.id', $id)->get()->getRowArray()
            ?? throw new NotFoundException('No such product on this website.');
        $rep = $this->visible()->select('MIN(p.id) AS rep', false)->where(self::GK . ' = ' . db_connect()->escape((string) $row['gk']), null, false)->get()->getRow('rep');

        return $this->present([['gk' => (string) $row['gk'], 'rep' => (int) $rep]], true)[0];
    }

    /**
     * @param list<array{gk: string, rep: int|string}> $groups in display order
     * @return list<array<string, mixed>>
     */
    private function present(array $groups, bool $detail): array
    {
        if ($groups === []) {
            return [];
        }
        $db = db_connect();
        $keys = implode(',', array_map(static fn (array $g): string => (string) $db->escape((string) $g['gk']), $groups));
        $rows = $this->visible()->select(self::COLUMNS . ($detail ? ', p.description' : '') . ', ' . self::GK . ' AS gk', false)
            ->where(self::GK . " IN ({$keys})", null, false)->orderBy('p.id')->get()->getResultArray();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        $variants = [];
        $warranties = [];
        $catNames = [];
        if ($ids !== []) {
            foreach ($db->table('product_variants')->select('id, product_id, name, price_delta, attributes, stock_qty')->where('tenant_id', $this->dir->tenantId())
                ->whereIn('business_id', $this->dir->outletIds())->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $v) {
                $variants[(int) $v['product_id']][] = $v;
            }
            foreach ($db->table('product_warranties')->select('product_id, part, months')->whereIn('product_id', $ids)->orderBy('id')->get()->getResultArray() as $w) {
                $warranties[(int) $w['product_id']][] = $w;
            }
            $catIds = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['category_id'], $rows))));
            if ($catIds !== []) {
                foreach ($db->table('categories')->select('id, name')->where('tenant_id', $this->dir->tenantId())->whereIn('id', $catIds)->get()->getResultArray() as $c) {
                    $catNames[(int) $c['id']] = (string) $c['name'];
                }
            }
        }

        $order = array_flip($this->dir->outletIds());
        $outlets = [];
        $presenters = [];
        foreach ($this->dir->outlets() as $o) {
            $outlets[$o['id']] = $o;
            $presenters[$o['id']] = ProductPresenter::forBusiness($o);
        }
        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[(string) $r['gk']][] = $r;
        }

        $out = [];
        foreach ($groups as $g) {
            $members = $byGroup[(string) $g['gk']] ?? [];
            if ($members === []) {
                continue;
            }
            usort($members, static fn (array $a, array $b): int => [$order[(int) $a['business_id']] ?? 999, (int) $a['id']] <=> [$order[(int) $b['business_id']] ?? 999, (int) $b['id']]);
            $repRow = $members[0];
            foreach ($members as $m) {
                if ((int) $m['id'] === (int) $g['rep']) {
                    $repRow = $m;
                }
            }
            $offers = [];
            $base = null;
            $image = null;
            foreach ($members as $m) {
                $bid = (int) $m['business_id'];
                $p = $presenters[$bid]->present($m, $variants[(int) $m['id']] ?? [], $warranties[(int) $m['id']] ?? [], $detail);
                if ((int) $m['id'] === (int) $repRow['id']) {
                    $base = $p;
                }
                $image ??= $p['image'];
                $offers[] = [
                    'outlet_id' => $bid,
                    'outlet' => (string) $outlets[$bid]['name'],
                    'area' => $outlets[$bid]['area'],
                    'product_id' => (int) $m['id'],
                    'price' => $p['price'],
                    'compare_at' => $p['compare_at'],
                    'tax' => $p['tax'],
                    'in_stock' => $p['in_stock'],
                    'stock' => $p['stock'],
                    'variants' => $p['variants'],
                ];
            }
            $base ??= $presenters[(int) $repRow['business_id']]->present($repRow, [], [], $detail);
            $prices = array_column($offers, 'price');
            $item = [
                'id' => (int) $repRow['id'],
                'name' => $base['name'],
                'brand' => $base['brand'],
                'sku' => $repRow['sku'] !== null && $repRow['sku'] !== '' ? (string) $repRow['sku'] : null,
                'category' => $repRow['category_id'] !== null ? ($catNames[(int) $repRow['category_id']] ?? null) : null,
                'icon' => $base['icon'],
                'image' => $image,
                'badge' => $base['badge'],
                'featured' => (bool) array_filter($members, static fn (array $m): bool => (bool) $m['is_featured']),
                'unit' => $base['unit'],
                'prescription_required' => $base['prescription_required'],
                'generic' => $base['generic'],
                'warranty' => $base['warranty'],
                'price_min' => min($prices),
                'price_max' => max($prices),
                'in_stock' => (bool) array_filter($offers, static fn (array $o): bool => $o['in_stock']),
                'outlets_total' => count($offers),
                'outlets_in_stock' => count(array_filter($offers, static fn (array $o): bool => $o['in_stock'])),
                'offers' => $offers,
            ];
            if ($detail) {
                $item['description'] = $base['description'];
                $item['options'] = $base['options'];
            }
            $out[] = $item;
        }

        return $out;
    }

    private static function flag(mixed $v): bool
    {
        return in_array($v, ['1', 1, true, 'true', 'yes'], true);
    }
}
