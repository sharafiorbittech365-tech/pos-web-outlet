<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Libraries\ApiSite;
use App\Libraries\ProductSearch;
use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiEnvelope;
use OmniPOS\Core\Models\Generated\CategoriesModel;
use OmniPOS\Core\Models\Generated\ProductsModel;
use OmniPOS\Core\Models\Generated\ProductVariantsModel;

/**
 * Online catalogue of the current site's outlet. Only products that are active, shown online and
 * not deleted are visible. Lists run one count + one page query, then load the variants and
 * warranties of that page's products in two batched queries.
 */
final class CatalogService
{
    private const COLUMNS = 'p.id, p.category_id, p.sku, p.name, p.brand, p.generic_name, p.unit, p.icon, p.image_path, p.gallery, p.price, p.compare_at,'
        . ' p.tax_mode, p.tax_rate, p.stock_type, p.stock_qty, p.duration_min, p.warranty_months, p.badge, p.is_rx, p.is_featured, p.created_at';

    public function __construct(private readonly ApiSite $site)
    {
    }

    private function presenter(): ProductPresenter
    {
        return ProductPresenter::forBusiness($this->site->business());
    }

    /** Products visible online (tenant + outlet scope from the model). */
    private function visible(): BaseBuilder
    {
        return model(ProductsModel::class)->scoped('p')
            ->where('p.is_active', 1)->where('p.is_online', 1)->where('p.deleted_at', null);
    }

    /** @return list<array{id: int, name: string, icon: string, products: int}> */
    public function categories(): array
    {
        $rows = model(CategoriesModel::class)->scoped('c')
            ->select('c.id, c.name, c.icon, COUNT(p.id) AS products')
            ->join('products p', 'p.category_id = c.id AND p.business_id = c.business_id AND p.is_active = 1 AND p.is_online = 1 AND p.deleted_at IS NULL', 'left')
            ->groupBy('c.id, c.name, c.icon, c.sort_order')
            ->orderBy('c.sort_order')->orderBy('c.name')
            ->get()->getResultArray();

        return array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'icon' => (string) $c['icon'], 'products' => (int) $c['products']], $rows);
    }

    /**
     * @param array<string, mixed> $f q, category, featured, in_stock, sort
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function products(array $f, int $page, int $perPage): array
    {
        $b = $this->visible();
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            ProductSearch::apply($b, $q);
        }
        if (isset($f['category']) && $f['category'] !== '') {
            if (! ctype_digit((string) $f['category'])) {
                throw new ValidationException('Invalid category.', ['category' => 'Use a category id.']);
            }
            $b->where('p.category_id', (int) $f['category']);
        }
        if (self::flag($f['featured'] ?? null)) {
            $b->where('p.is_featured', 1);
        }
        if (self::flag($f['in_stock'] ?? null) && (bool) $this->site->business()['stock_enabled']) {
            $b->groupStart()->where('p.stock_type', 'none')->orWhere('p.stock_qty >', 0)->groupEnd();
        }

        $total = (int) $b->countAllResults(false);
        $biz = $this->site->business();
        $sort = (string) ($f['sort'] ?? '');
        match ($sort) {
            'name' => $b->orderBy('p.name', 'ASC'),
            'price', '-price' => $b->orderBy(ProductSearch::grossPriceSqlFor((bool) $biz['tax_inclusive'], (float) $biz['tax_rate']), $sort === 'price' ? 'ASC' : 'DESC', false),
            'newest' => $b->orderBy('p.created_at', 'DESC'),
            '' => $b->orderBy('p.is_featured', 'DESC')->orderBy('p.name', 'ASC'),
            default => throw new ValidationException('Unknown sort.', ['sort' => 'Use name, price, -price or newest.']),
        };
        $rows = $b->select(self::COLUMNS)->orderBy('p.id', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['data' => $this->presentAll($rows), 'meta' => ApiEnvelope::pageMeta($total, $page, $perPage)];
    }

    /** @return array<string, mixed> */
    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} approved reviews of a visible product */
    public function reviews(int $id, int $page, int $perPage): array
    {
        $this->visible()->select('p.id')->where('p.id', $id)->get()->getRowArray() ?? throw new NotFoundException('No such product in this shop.');
        $total = (int) db_connect()->table('product_reviews')->where('product_id', $id)->where('status', 'approved')->countAllResults();

        return ['data' => ProductExtrasPresenter::reviews($id, $perPage, ($page - 1) * $perPage), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / max(1, $perPage)))]];
    }

    /**
     * A customer's review (waits for approval in POS › Products › Reviews). Up to 3 pictures as data URLs, each compressed.
     *
     * @param array<string, mixed> $in author, rating, title, body, images[]
     * @return array{id: int, status: string}
     */
    public function addReview(int $id, array $in): array
    {
        $this->visible()->select('p.id')->where('p.id', $id)->get()->getRowArray() ?? throw new NotFoundException('No such product in this shop.');
        $author = mb_substr(trim((string) ($in['author'] ?? '')), 0, 80);
        $rating = (int) ($in['rating'] ?? 0);
        $errors = [];
        if ($author === '') {
            $errors['author'] = 'Enter your name.';
        }
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choose 1 to 5 stars.';
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        $body = mb_substr(trim((string) ($in['body'] ?? '')), 0, 3000);
        if ($title === '' && $body === '') {
            $errors['body'] = 'Write a few words about the product.';
        }
        $raw = array_slice(array_values(array_filter((array) ($in['images'] ?? []), 'is_string')), 0, 3);
        $b = $this->site->business();
        $paths = [];
        if ($errors === []) {
            foreach ($raw as $uri) {
                if (preg_match('#^data:image/(?:png|jpe?g|webp|gif);base64,([A-Za-z0-9+/=]+)$#', $uri, $m) !== 1 || strlen($m[1]) > 7_000_000) {
                    $errors['images'] = 'Pictures must be PNG, JPG, WebP or GIF up to 5 MB each.';
                    break;
                }
                try {
                    $paths[] = \OmniPOS\Core\Services\ImageCompressor::store((string) base64_decode($m[1], true), (int) $b['tenant_id'], (int) $b['id'], 'review', 1000);
                } catch (ValidationException) {
                    $errors['images'] = 'One of the pictures is not a usable image.';
                    break;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Please check your review.', $errors);
        }
        $db = db_connect();
        $db->table('product_reviews')->insert([
            'tenant_id' => (int) $b['tenant_id'], 'business_id' => (int) $b['id'], 'product_id' => $id, 'author' => $author, 'rating' => $rating, 'title' => $title !== '' ? $title : null,
            'body' => $body !== '' ? $body : null, 'images' => $paths !== [] ? json_encode($paths, JSON_UNESCAPED_SLASHES) : null, 'verified_purchase' => 0, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['id' => (int) $db->insertID(), 'status' => 'pending'];
    }

    public function product(int $id): array
    {
        $row = $this->visible()->select(self::COLUMNS . ', p.description')->where('p.id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new NotFoundException('No such product in this shop.');
        }

        return $this->presentAll([$row], true)[0];
    }

    /**
     * Stock levels. With product_ids (max 100): those products; without: one page of all products.
     *
     * @param list<int> $ids
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function inventory(array $ids, int $page, int $perPage): array
    {
        $b = $this->visible();
        if ($ids !== []) {
            if (count($ids) > 100) {
                throw new ValidationException('Too many products.', ['product_ids' => 'At most 100 ids per call.']);
            }
            $b->whereIn('p.id', $ids);
            $page = 1;
            $perPage = max(1, count($ids));
            $total = null;
        } else {
            $total = (int) $b->countAllResults(false);
        }
        $rows = $b->select('p.id, p.stock_type, p.stock_qty')->orderBy('p.id')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        $presenter = $this->presenter();
        $variants = $this->variantsFor(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $tracked = $presenter->isTracked($r);
            $out[] = [
                'product_id' => (int) $r['id'],
                'tracked' => $tracked,
                'available' => $tracked ? ProductPresenter::num((float) $r['stock_qty']) : null,
                'variants' => array_map(static fn (array $v): array => [
                    'id' => (int) $v['id'],
                    'available' => $tracked && $r['stock_type'] === 'variant' && $v['stock_qty'] !== null ? ProductPresenter::num((float) $v['stock_qty']) : null,
                ], $variants[(int) $r['id']] ?? []),
            ];
        }

        return ['data' => $out, 'meta' => ApiEnvelope::pageMeta($total ?? count($out), $page, $perPage)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentAll(array $rows, bool $detail = false): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $variants = $this->variantsFor($ids);
        $warranties = [];
        if ($ids !== []) {
            foreach (db_connect()->table('product_warranties')->select('product_id, part, months')->whereIn('product_id', $ids)->orderBy('id')->get()->getResultArray() as $w) {
                $warranties[(int) $w['product_id']][] = $w;
            }
        }
        $presenter = $this->presenter();

        $extras = ProductExtrasPresenter::forProducts($ids, $detail && count($ids) === 1);

        return array_map(static fn (array $r): array => $presenter->present($r, $variants[(int) $r['id']] ?? [], $warranties[(int) $r['id']] ?? [], $detail) + ($extras[(int) $r['id']] ?? []), $rows);
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>> product id => variants
     */
    private function variantsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = model(ProductVariantsModel::class)->scoped('v')->select('v.id, v.product_id, v.name, v.sku, v.price_delta, v.attributes, v.stock_qty, v.image_path')
            ->whereIn('v.product_id', $ids)->orderBy('v.product_id')->orderBy('v.sort_order')->orderBy('v.id')->get()->getResultArray();
        foreach ($rows as $v) {
            $out[(int) $v['product_id']][] = $v;
        }

        return $out;
    }

    private static function flag(mixed $v): bool
    {
        return in_array($v, ['1', 1, true, 'true', 'yes'], true);
    }
}
