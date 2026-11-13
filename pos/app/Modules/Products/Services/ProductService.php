<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\BackOffice\Support\Icons;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\ProductsModel;
use OmniPOS\Core\Pricing\TaxPolicy;
use OmniPOS\Core\Tenancy\TenantContext;

/**
 * Catalogue of the current outlet: list query, one product with its variants / serials / batches /
 * warranties, create & update (opening stock through StockService), soft delete.
 */
final class ProductService
{
    public const STOCK_TYPES = [
        'none' => ['Not tracked', 'Services, made-to-order'],
        'simple' => ['Simple count', 'One number on hand'],
        'variant' => ['Options (size / colour)', 'Stock per option'],
        'serial' => ['IMEI / serial', 'One unit per number'],
        'batch' => ['Batch & expiry', 'First-expiry-first-out'],
    ];
    public const TAX_MODES = ['default', 'inclusive', 'exclusive', 'exempt'];

    public function __construct(private readonly TenantContext $ctx, private readonly array $business)
    {
    }

    public static function make(): self
    {
        return new self(service('tenant'), service('posSession')->business());
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return $this->ctx->businessId();
    }

    public function taxPolicy(): TaxPolicy
    {
        return TaxPolicy::fromBusiness($this->business);
    }

    /** "VAT 5% incl." for a product's rule (default rule spelled out). */
    public function taxLabel(?string $mode, mixed $rate): string
    {
        $rule = $this->taxPolicy()->ruleFor($mode, $rate === null || $rate === '' ? null : (float) $rate);
        $label = (string) ($this->business['tax_label'] ?? 'VAT');

        return ($mode === null || $mode === 'default' ? 'Default · ' : '') . $label . ' ' . $rule->label();
    }

    /**
     * List query for the products table.
     *
     * @param array{cat?: int, type?: string, stock?: string} $f
     */
    public function listQuery(array $f): BaseBuilder
    {
        $low = (float) ($this->business['low_stock_alert'] ?? 0);
        $b = $this->db()->table('products p')
            ->select('p.id, p.name, p.sku, p.barcode, p.brand, p.generic_name, p.unit, p.icon, p.price, p.cost, p.compare_at, p.tax_mode, p.tax_rate, p.stock_type, p.stock_qty,
                      p.low_stock_at, p.is_featured, p.is_online, p.is_active, c.name AS category, (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) AS variants')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.business_id', $this->bid())->where('p.tenant_id', $this->ctx->tenantId())->where('p.deleted_at', null);
        if (! empty($f['cat'])) {
            $b->where('p.category_id', (int) $f['cat']);
        }
        $type = (string) ($f['type'] ?? '');
        if ($type === 'hidden') {
            $b->where('p.is_active', 0);
        } elseif (array_key_exists($type, self::STOCK_TYPES)) {
            $b->where('p.stock_type', $type);
        }
        $stock = (string) ($f['stock'] ?? '');
        if ($stock === 'out') {
            $b->where('p.stock_type <>', 'none')->where('p.stock_qty <=', 0);
        } elseif ($stock === 'low') {
            $b->where('p.stock_type <>', 'none')->where('p.stock_qty >', 0)->where('p.stock_qty <= COALESCE(p.low_stock_at, ' . $this->db()->escape($low) . ')', null, false);
        }

        return $b;
    }

    /** @return array{products: int, categories: int, out: int, low: int, margin: int} */
    public function stats(): array
    {
        $db = $this->db();
        $low = (float) ($this->business['low_stock_alert'] ?? 0);
        $r = $db->query(
            "SELECT COUNT(*) AS n,
                    SUM(stock_type <> 'none' AND stock_qty <= 0) AS out_n,
                    SUM(stock_type <> 'none' AND stock_qty > 0 AND stock_qty <= COALESCE(low_stock_at, ?)) AS low_n,
                    AVG(CASE WHEN price > 0 AND cost > 0 THEN (price - cost) / price * 100 END) AS margin
               FROM products WHERE business_id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$low, $this->bid(), $this->ctx->tenantId()]
        )->getRowArray() ?? [];

        return [
            'products' => (int) ($r['n'] ?? 0), 'out' => (int) ($r['out_n'] ?? 0), 'low' => (int) ($r['low_n'] ?? 0), 'margin' => (int) round((float) ($r['margin'] ?? 0)),
            'categories' => $db->table('categories')->where('business_id', $this->bid())->countAllResults(),
        ];
    }

    /** @return list<array{id: int, name: string, icon: string, n: int}> categories with product counts */
    public function categories(): array
    {
        $rows = $this->db()->table('categories c')->select('c.id, c.parent_id, c.name, c.icon, c.sort_order, COUNT(p.id) AS n')
            ->join('products p', 'p.category_id = c.id AND p.deleted_at IS NULL', 'left')
            ->where('c.business_id', $this->bid())->groupBy('c.id')->orderBy('c.sort_order')->orderBy('c.name')->get()->getResultArray();

        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $out = [];
        $push = static function (array $r, string $name) use (&$out): void {
            $out[] = ['id' => (int) $r['id'], 'name' => $name, 'icon' => $r['icon'], 'n' => (int) $r['n'], 'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null];
        };
        foreach ($rows as $r) {
            if ($r['parent_id'] === null || ! isset($byId[(int) $r['parent_id']])) {
                $push($r, (string) $r['name']);
                foreach ($rows as $k) {
                    if ($k['parent_id'] !== null && (int) $k['parent_id'] === (int) $r['id']) {
                        $push($k, $r['name'] . ' › ' . $k['name']);
                    }
                }
            }
        }

        return $out;
    }

    /** @return list<string> brands already used (datalist) */
    public function brands(): array
    {
        return array_column($this->db()->table('products')->distinct()->select('brand')->where('business_id', $this->bid())->where('brand IS NOT NULL', null, false)
            ->where('deleted_at', null)->orderBy('brand')->limit(200)->get()->getResultArray(), 'brand');
    }

    /** @return array<string, mixed> product with variants, serials (in stock), batches, warranties */
    public function find(int $id): array
    {
        $db = $this->db();
        $p = (new ProductsModel())->find($id);
        if (! is_array($p)) {
            throw new NotFoundException('Product not found.');
        }
        $p['extra'] = json_decode((string) ($p['extra'] ?? '{}'), true) ?: [];
        $p['variants'] = $db->table('product_variants')->where('product_id', $id)->where('business_id', $this->bid())->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        foreach ($p['variants'] as &$v) {
            $v['attributes'] = json_decode((string) ($v['attributes'] ?? '{}'), true) ?: [];
        }
        unset($v);
        $p['serials'] = $db->table('product_serials')->select('id, serial, status')->where('product_id', $id)->where('business_id', $this->bid())->whereIn('status', ['in_stock', 'returned'])->orderBy('id')->limit(500)->get()->getResultArray();
        $p['batches'] = $db->table('product_batches')->select('id, batch_no, expires_on, qty')->where('product_id', $id)->where('business_id', $this->bid())->orderBy('expires_on IS NULL', 'ASC', false)->orderBy('expires_on')->get()->getResultArray();
        $p['warranties'] = $db->table('product_warranties')->select('id, part, months')->where('product_id', $id)->orderBy('id')->get()->getResultArray();

        return $p;
    }

    /**
     * Create or update a product. $in is the raw POST (validated here); returns the product id.
     *
     * @param array<string, mixed> $in
     */
    public function save(?int $id, array $in): int
    {
        $data = $this->clean($in, $id);
        $link = $this->links($in);
        $rows = $this->rows($in, $data['stock_type'], $data['sku'] ?? null, $id);
        $stock = service('stock');

        $pid = Tx::run(function (BaseConnection $db) use ($id, $data, $rows, $stock, $link): int {
            $model = new ProductsModel();
            $before = null;
            if ($id !== null) {
                $before = $model->find($id) ?? throw new NotFoundException('Product not found.');
                if ($before['stock_type'] !== $data['stock_type'] && ((float) $before['stock_qty'] > 0)) {
                    throw new ValidationException('Stock tracking cannot change while the product has stock.', ['stock_type' => 'Bring stock to zero (Inventory) before changing how it is tracked.']);
                }
                $model->update($id, $data);
                $pid = $id;
            } else {
                $pid = (int) $model->insert($data + ['stock_qty' => 0]);
            }
            if ($link !== []) {
                // model / medicine link (columns added after the generated model; written directly)
                $db->table('products')->where('id', $pid)->where('business_id', $this->bid())->update($link);
            }

            $this->saveVariants($db, $pid, $rows['variants'], $data['stock_type'], $id === null);
            $this->saveWarranties($db, $pid, $rows['warranties']);

            // stock (opening on create; new serials / batches also on edit)
            if ($id === null && $data['stock_type'] === 'simple' && $rows['opening'] > 0) {
                $stock->adjust($pid, $rows['opening'], 'opening', 'Opening stock');
            }
            if ($data['stock_type'] === 'serial' && $rows['serials'] !== []) {
                $stock->receiveSerials($pid, $rows['serials'], $id === null ? 'opening' : 'adjust', $id === null ? 'Opening stock' : 'Added in product form');
            }
            if ($data['stock_type'] === 'batch') {
                foreach ($rows['batches'] as $b) {
                    $stock->receiveBatch($pid, $b['no'], $b['exp'], $b['qty'], $id === null ? 'opening' : 'adjust', $id === null ? 'Opening stock' : 'Added in product form');
                }
            }

            return $pid;
        });
        service('audit')->activity($id === null ? 'Product added' : 'Product updated', $data['name'] . ' · ' . number_format((float) $data['price'], 2));
        if ($id === null) {
            service('usage')->touch($this->ctx->tenantId());
        }

        return $pid;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function clean(array $in, ?int $id): array
    {
        $errors = [];
        $s = static fn (string $k, int $max = 255): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $num = static function (string $k) use ($in, &$errors): ?float {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                return null;
            }
            if (! is_numeric($v) || (float) $v < 0) {
                $errors[$k] = 'Enter a number of zero or more.';

                return null;
            }

            return round((float) $v, 3);
        };
        $name = $s('name', 200);
        if ($name === null) {
            $errors['name'] = 'Product name is required.';
        }
        $price = $num('price');
        if ($price === null && ! isset($errors['price'])) {
            $errors['price'] = 'Enter the selling price.';
        }
        $cat = (int) ($in['category_id'] ?? 0);
        if ($cat > 0 && $this->db()->table('categories')->where('id', $cat)->where('business_id', $this->bid())->countAllResults() === 0) {
            $errors['category_id'] = 'Choose a category of this outlet.';
        }
        $mode = (string) ($in['tax_mode'] ?? 'default');
        if (! in_array($mode, self::TAX_MODES, true)) {
            $errors['tax_mode'] = 'Choose how VAT is charged.';
        }
        $rateIn = trim((string) ($in['tax_rate'] ?? ''));
        $rate = null;
        if ($rateIn !== '' && $mode !== 'exempt') {
            $rate = (float) $rateIn;
            if (! in_array($rate, $this->taxPolicy()->rates(), false)) {
                $errors['tax_rate'] = 'Choose one of the outlet\'s tax rates (Setup).';
            }
        }
        $type = (string) ($in['stock_type'] ?? 'simple');
        if (! array_key_exists($type, self::STOCK_TYPES)) {
            $errors['stock_type'] = 'Choose how stock is tracked.';
            $type = 'simple';
        }
        // a new product with a size x colour grid keeps stock per option
        if ($id === null && $type === 'simple' && self::hasMatrix($in)) {
            $type = 'variant';
        }
        $sku = $s('sku', 64);
        $barcode = $s('barcode', 64);
        foreach (['sku' => $sku, 'barcode' => $barcode] as $col => $val) {
            if ($val === null) {
                continue;
            }
            $q = $this->db()->table('products')->where('business_id', $this->bid())->where('deleted_at', null)->where($col, $val);
            if ($id !== null) {
                $q->where('id <>', $id);
            }
            if ($q->countAllResults() > 0) {
                $errors[$col] = strtoupper($col) . ' ' . $val . ' is already used by another product.';
            }
        }
        $duration = $num('duration_min');
        $warranty = $num('warranty_months');
        $compare = $num('compare_at');
        $low = $num('low_stock_at');
        $cost = $num('cost');
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }
        $extra = [];
        foreach ((array) ($in['prices'] ?? []) as $g => $v) {
            if (is_string($g) && is_numeric($v) && (float) $v >= 0) {
                $extra['prices'][mb_substr(mb_strtolower($g), 0, 32)] = round((float) $v, 2);
            }
        }

        return [
            'category_id' => $cat > 0 ? $cat : null, 'sku' => $sku, 'barcode' => $barcode, 'name' => $name, 'brand' => $s('brand', 80), 'generic_name' => $s('generic_name', 160),
            'unit' => $s('unit', 16), 'icon' => Icons::valid($s('icon', 40)), 'description' => $s('description', 5000), 'badge' => $s('badge', 24),
            'price' => round((float) $price, 2), 'cost' => round((float) ($cost ?? 0), 2), 'compare_at' => $compare !== null && $compare > 0 ? round($compare, 2) : null,
            'tax_mode' => $mode, 'tax_rate' => $rate, 'stock_type' => $type, 'low_stock_at' => $low,
            'duration_min' => $duration !== null && $duration > 0 ? min(1440, (int) $duration) : null, 'warranty_months' => $warranty !== null && $warranty > 0 ? min(1200, (int) $warranty) : null,
            'is_rx' => ! empty($in['is_rx']) ? 1 : 0, 'is_featured' => ! empty($in['is_featured']) ? 1 : 0, 'is_online' => ! empty($in['is_online']) ? 1 : 0,
            'is_active' => empty($in['hidden']) ? 1 : 0, 'extra' => $extra !== [] ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * Repeatable rows of the form: variants, warranties, opening stock, serials, batches.
     *
     * @param array<string, mixed> $in
     * @return array{variants: list<array<string, mixed>>, warranties: list<array{part: string, months: int}>, opening: float, serials: list<string>, batches: list<array{no: string, exp: ?string, qty: float}>}
     */
    private function rows(array $in, string $type, ?string $sku = null, ?int $id = null): array
    {
        $variants = [];
        foreach ((array) ($in['variants'] ?? []) as $v) {
            if (! is_array($v) || trim((string) ($v['name'] ?? '')) === '') {
                continue;
            }
            $attrs = [];
            foreach (preg_split('/\s*,\s*/', trim((string) ($v['attrs'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
                [$k, $val] = array_pad(preg_split('/\s*[:=]\s*/', $pair, 2) ?: [], 2, '');
                if (trim($k) !== '' && trim((string) $val) !== '') {
                    $attrs[mb_substr(trim($k), 0, 32)] = mb_substr(trim((string) $val), 0, 48);
                }
            }
            $mult = (float) ($v['mult'] ?? 1);
            if ($mult > 1) {
                $attrs['mult'] = round($mult, 3);
            }
            $variants[] = [
                'id' => (int) ($v['id'] ?? 0), 'name' => mb_substr(trim((string) $v['name']), 0, 120), 'sku' => ($sku = trim((string) ($v['sku'] ?? ''))) !== '' ? mb_substr($sku, 0, 64) : null,
                'price_delta' => round((float) ($v['price_delta'] ?? 0), 2), 'attributes' => $attrs, 'stock' => max(0.0, (float) ($v['stock'] ?? 0)),
            ];
        }
        if (self::hasMatrix($in)) {
            // size x colour generator (no-JS path; the browser normally adds the rows itself)
            $gen = (new MatrixGenerator($this->bid()))->build((array) $in['matrix'], (string) $sku, array_column($variants, 'name'), $id);
            $variants = array_merge($variants, $gen);
        }
        if ($type === 'variant' && $variants === []) {
            throw new ValidationException('Add the options (e.g. sizes) for option stock.', ['variants' => 'Add at least one option with its stock.']);
        }
        $warranties = [];
        foreach ((array) ($in['warranties'] ?? []) as $w) {
            $part = is_array($w) ? trim((string) ($w['part'] ?? '')) : '';
            $months = is_array($w) ? (int) ($w['months'] ?? 0) : 0;
            if ($part !== '' && $months > 0) {
                $warranties[] = ['part' => mb_substr($part, 0, 80), 'months' => min(1200, $months)];
            }
        }
        $serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($in['serials'] ?? '')) ?: []))));
        $batches = [];
        foreach ((array) ($in['batches'] ?? []) as $b) {
            if (! is_array($b) || trim((string) ($b['no'] ?? '')) === '' || (float) ($b['qty'] ?? 0) <= 0) {
                continue;
            }
            $exp = trim((string) ($b['exp'] ?? ''));
            $batches[] = ['no' => trim((string) $b['no']), 'exp' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null, 'qty' => (float) $b['qty']];
        }

        return ['variants' => $variants, 'warranties' => $warranties, 'opening' => max(0.0, (float) ($in['opening_stock'] ?? 0)), 'serials' => $serials, 'batches' => $batches];
    }

    /** @param array<string, mixed> $in */
    public static function hasMatrix(array $in): bool
    {
        $m = $in['matrix'] ?? null;

        // sizes are required (colours optional); picking a scale in the form fills the sizes field
        return is_array($m) && trim((string) ($m['sizes'] ?? '')) !== '';
    }

    /**
     * Model and medicine-database link from the form (only the keys that were posted).
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private function links(array $in): array
    {
        $out = [];
        if (array_key_exists('model', $in)) {
            $m = trim((string) $in['model']);
            $out['model'] = $m === '' ? null : mb_substr($m, 0, 80);
        }
        if (array_key_exists('medicine_id', $in) && service('posSession')->moduleOn('pharmacy')) {
            $mid = (int) $in['medicine_id'];
            if ($mid > 0 && $this->db()->table('medicines')->where('id', $mid)->where('is_active', 1)->countAllResults() === 0) {
                throw new ValidationException('Please check the highlighted fields.', ['medicine_id' => 'Choose a medicine from the database.']);
            }
            $out['medicine_id'] = $mid > 0 ? $mid : null;
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $rows */
    private function saveVariants(BaseConnection $db, int $pid, array $rows, string $type, bool $isNew): void
    {
        $bid = $this->bid();
        $existing = array_column($db->table('product_variants')->select('id, name, stock_qty')->where('product_id', $pid)->where('business_id', $bid)->get()->getResultArray(), null, 'id');
        $keep = [];
        foreach ($rows as $i => $v) {
            $row = ['name' => $v['name'], 'sku' => $v['sku'], 'price_delta' => $v['price_delta'], 'attributes' => json_encode($v['attributes'], JSON_UNESCAPED_UNICODE), 'sort_order' => $i];
            if ($v['id'] > 0 && isset($existing[$v['id']])) {
                $db->table('product_variants')->where('id', $v['id'])->where('business_id', $bid)->update($row);
                $keep[] = $v['id'];
                continue;
            }
            $db->table('product_variants')->insert($row + ['tenant_id' => $this->ctx->tenantId(), 'business_id' => $bid, 'product_id' => $pid, 'stock_qty' => $type === 'variant' ? 0 : null]);
            $vid = (int) $db->insertID();
            $keep[] = $vid;
            if ($type === 'variant' && $v['stock'] > 0) {
                service('stock')->adjust($pid, $v['stock'], $isNew ? 'opening' : 'adjust', ($isNew ? 'Opening stock · ' : 'New option · ') . $v['name'], $vid);
            }
        }
        foreach ($existing as $vid => $e) {
            if (in_array((int) $vid, $keep, true)) {
                continue;
            }
            if ((float) ($e['stock_qty'] ?? 0) > 0) {
                throw new BusinessRuleException('Option “' . $e['name'] . '” still has stock — adjust it to zero in Inventory before removing it.');
            }
            $db->table('product_variants')->where('id', $vid)->where('business_id', $bid)->delete();
        }
        if ($type === 'variant') {
            // options created before stock was per option start at zero
            $db->table('product_variants')->set('stock_qty', 0)->where('product_id', $pid)->where('business_id', $bid)->where('stock_qty', null)->update();
        }
    }

    /** @param list<array{part: string, months: int}> $rows */
    private function saveWarranties(BaseConnection $db, int $pid, array $rows): void
    {
        $db->table('product_warranties')->where('product_id', $pid)->delete();
        if ($rows !== []) {
            $db->table('product_warranties')->insertBatch(array_map(static fn (array $w): array => $w + ['product_id' => $pid], $rows));
        }
    }

    public function delete(int $id): void
    {
        $model = new ProductsModel();
        $p = $model->find($id) ?? throw new NotFoundException('Product not found.');
        $model->delete($id);
        service('audit')->activity('Product deleted', (string) $p['name']);
    }

    /** @param list<int> $ids */
    public function bulk(array $ids, string $action, int $categoryId = 0): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $model = new ProductsModel();
        $set = match ($action) {
            'feature' => ['is_featured' => 1], 'unfeature' => ['is_featured' => 0],
            'hide' => ['is_active' => 0], 'show' => ['is_active' => 1],
            'online' => ['is_online' => 1], 'offline' => ['is_online' => 0],
            'category' => ['category_id' => $categoryId > 0 ? $categoryId : null],
            'delete' => null,
            default => throw new BusinessRuleException('Unknown action.'),
        };
        if ($action === 'category' && $categoryId > 0 && $this->db()->table('categories')->where('id', $categoryId)->where('business_id', $this->bid())->countAllResults() === 0) {
            throw new BusinessRuleException('Choose a category of this outlet.');
        }
        if ($set === null) {
            $model->delete($ids);
        } else {
            $model->update($ids, $set);
        }
        service('audit')->activity('Products · ' . $action, count($ids) . ' products');

        return count($ids);
    }
}
