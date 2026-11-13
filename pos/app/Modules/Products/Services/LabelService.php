<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;

/**
 * Product barcode / QR labels (Products › Print labels).
 *
 * Every label carries the code the till already looks up (TillCatalog::lookup): the product's
 * barcode, else its SKU; a variant's SKU. "Create missing codes" fills the gaps — products get an
 * in-store EAN-13 (prefix 2, outlet, product; unique by construction), variants a SKU derived from
 * their product's code — so a printed label always scans back to the right product.
 *
 * The label design (size, layout, fields, colours) is kept per outlet in settings.labelPrint.
 */
final class LabelService
{
    /** Ready-made label sizes: roll labels (one per page) and A4 sheets (cols × rows). */
    public const PRESETS = [
        'roll-38x25' => ['label' => 'Roll 38 × 25 mm', 'w' => 38, 'h' => 25, 'sheet' => false],
        'roll-50x25' => ['label' => 'Roll 50 × 25 mm', 'w' => 50, 'h' => 25, 'sheet' => false],
        'roll-50x30' => ['label' => 'Roll 50 × 30 mm', 'w' => 50, 'h' => 30, 'sheet' => false],
        'roll-40x30' => ['label' => 'Roll 40 × 30 mm', 'w' => 40, 'h' => 30, 'sheet' => false],
        'roll-60x40' => ['label' => 'Roll 60 × 40 mm', 'w' => 60, 'h' => 40, 'sheet' => false],
        'a4-2x7' => ['label' => 'A4 sheet · 2 × 7 (99.1 × 38.1 mm)', 'w' => 99.1, 'h' => 38.1, 'sheet' => true, 'cols' => 2],
        'a4-3x7' => ['label' => 'A4 sheet · 3 × 7 (63.5 × 38.1 mm)', 'w' => 63.5, 'h' => 38.1, 'sheet' => true, 'cols' => 3],
        'a4-3x8' => ['label' => 'A4 sheet · 3 × 8 (70 × 37 mm)', 'w' => 70, 'h' => 37, 'sheet' => true, 'cols' => 3],
        'a4-4x10' => ['label' => 'A4 sheet · 4 × 10 (52.5 × 29.7 mm)', 'w' => 52.5, 'h' => 29.7, 'sheet' => true, 'cols' => 4],
        'a4-4x11' => ['label' => 'A4 sheet · 4 × 11 (48.5 × 25.4 mm)', 'w' => 48.5, 'h' => 25.4, 'sheet' => true, 'cols' => 4],
        'a4-5x13' => ['label' => 'A4 sheet · 5 × 13 (38.1 × 21.2 mm)', 'w' => 38.1, 'h' => 21.2, 'sheet' => true, 'cols' => 5],
        'a4-5x16' => ['label' => 'A4 sheet · 5 × 16 (38 × 17 mm, small tags)', 'w' => 38, 'h' => 17, 'sheet' => true, 'cols' => 5],
        'sheet-custom' => ['label' => 'A4 sheet · custom size & columns', 'w' => 50, 'h' => 25, 'sheet' => true, 'cols' => 3],
        'custom' => ['label' => 'Custom size', 'w' => 50, 'h' => 30, 'sheet' => false],
    ];

    public const FIELDS = ['business' => 'Shop name', 'name' => 'Product name', 'variant' => 'Size / option', 'price' => 'Price', 'compare' => 'Old price (struck)', 'code' => 'Code as text', 'unit' => 'Unit'];

    public const CODES = ['barcode' => 'Barcode', 'qr' => 'QR code', 'both' => 'Barcode + QR', 'none' => 'No code'];

    public const DEFAULT = [
        'preset' => 'roll-50x30', 'w' => 50, 'h' => 30, 'cols' => 3, 'codeType' => 'barcode', 'fontSize' => 9,
        'fields' => ['business' => true, 'name' => true, 'variant' => true, 'price' => true, 'compare' => false, 'code' => true, 'unit' => false],
        'colors' => ['text' => '#111111', 'bg' => '#FFFFFF', 'border' => '#D4D4D8', 'price' => '#111111'], 'border' => false,
    ];

    public function __construct(private readonly int $businessId)
    {
    }

    public static function forCurrent(): self
    {
        return new self(service('posSession')->businessId());
    }

    /** @return array<string, mixed> the outlet's label design (defaults filled in) */
    public static function template(array $business): array
    {
        $t = array_replace_recursive(self::DEFAULT, (array) ($business['settings']['labelPrint'] ?? []));
        $t['preset'] = isset(self::PRESETS[$t['preset']]) ? $t['preset'] : self::DEFAULT['preset'];

        return $t;
    }

    /**
     * Validated design from the label page.
     *
     * @return array<string, mixed>
     */
    public static function templateFrom(array $in): array
    {
        $num = static fn ($v, float $min, float $max, float $def): float => is_numeric($v) ? max($min, min($max, round((float) $v, 1))) : $def;
        $hex = static fn ($v, string $def): string => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $def;
        $preset = isset(self::PRESETS[$in['preset'] ?? '']) ? (string) $in['preset'] : self::DEFAULT['preset'];
        $p = self::PRESETS[$preset];
        $custom = $preset === 'custom' || $preset === 'sheet-custom';
        $fields = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $fields[$f] = ! empty($in['fields'][$f]);
        }
        $c = (array) ($in['colors'] ?? []);

        return [
            'preset' => $preset,
            'w' => $custom ? $num($in['w'] ?? null, 15, 120, 50) : $p['w'],
            'h' => $custom ? $num($in['h'] ?? null, 10, 120, 30) : $p['h'],
            'cols' => $preset === 'sheet-custom' ? (int) $num($in['cols'] ?? null, 1, 8, 3) : (int) ($p['cols'] ?? 1),
            'codeType' => isset(self::CODES[$in['codeType'] ?? '']) ? (string) $in['codeType'] : 'barcode',
            'fontSize' => (int) $num($in['fontSize'] ?? null, 6, 16, 9),
            'fields' => $fields,
            'colors' => ['text' => $hex($c['text'] ?? null, '#111111'), 'bg' => $hex($c['bg'] ?? null, '#FFFFFF'), 'border' => $hex($c['border'] ?? null, '#D4D4D8'), 'price' => $hex($c['price'] ?? null, '#111111')],
            'border' => ! empty($in['border']),
        ];
    }

    /** Keep the design for the outlet (settings.labelPrint). */
    public function saveTemplate(array $template): void
    {
        $bid = $this->businessId;
        Tx::run(static function (BaseConnection $db) use ($bid, $template): void {
            $raw = $db->query('SELECT settings FROM businesses WHERE id = ? FOR UPDATE', [$bid])->getRow('settings');
            $s = json_decode((string) $raw, true) ?: [];
            $s['labelPrint'] = $template;
            $db->table('businesses')->where('id', $bid)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
        });
        \App\Libraries\PosSession::forgetBusiness($bid);
        service('audit')->activity('Label design saved', self::PRESETS[$template['preset']]['label'] . ' · ' . self::CODES[$template['codeType']]);
    }

    /**
     * Everything that can get a label: active products, and each variant separately.
     *
     * @return list<array{key: string, product_id: int, variant_id: ?int, name: string, variant: string, code: string, price: float, compare: ?float, unit: string, stock: ?float, category: string}>
     */
    public function items(): array
    {
        $db = db_connect();
        $products = $db->table('products p')
            ->select('p.id, p.name, p.sku, p.barcode, p.price, p.compare_at, p.unit, p.stock_type, p.stock_qty, c.name AS category')
            ->join('categories c', 'c.id = p.category_id', 'left')
            ->where('p.business_id', $this->businessId)->where('p.deleted_at', null)->where('p.is_active', 1)
            ->orderBy('p.name')->limit(3000)->get()->getResultArray();
        $variants = [];
        if ($products !== []) {
            foreach ($db->table('product_variants')->select('id, product_id, name, sku, price_delta, stock_qty')->where('business_id', $this->businessId)
                ->whereIn('product_id', array_column($products, 'id'))->orderBy('sort_order')->orderBy('id')->get()->getResultArray() as $v) {
                $variants[(int) $v['product_id']][] = $v;
            }
        }
        $out = [];
        foreach ($products as $p) {
            $pid = (int) $p['id'];
            $tracked = $p['stock_type'] !== 'none';
            $base = ['product_id' => $pid, 'name' => (string) $p['name'], 'price' => (float) $p['price'], 'compare' => $p['compare_at'] !== null ? (float) $p['compare_at'] : null,
                'unit' => (string) ($p['unit'] ?? ''), 'category' => (string) ($p['category'] ?? '')];
            if (isset($variants[$pid])) {
                foreach ($variants[$pid] as $v) {
                    $out[] = array_merge($base, ['key' => 'v' . $v['id'], 'variant_id' => (int) $v['id'], 'variant' => (string) $v['name'], 'code' => (string) ($v['sku'] ?? ''),
                        'price' => (float) $p['price'] + (float) $v['price_delta'], 'stock' => $tracked ? (float) $v['stock_qty'] : null]);
                }
                continue;
            }
            $out[] = $base + ['key' => 'p' . $pid, 'variant_id' => null, 'variant' => '', 'code' => (string) ($p['barcode'] ?: $p['sku'] ?: ''), 'stock' => $tracked ? (float) $p['stock_qty'] : null];
        }

        return $out;
    }

    /**
     * Give a scannable code to the chosen products / variants that have none.
     *
     * @param list<string> $keys item keys (p<id> / v<id>)
     * @return int codes created
     */
    public function createMissingCodes(array $keys): int
    {
        $pids = $vids = [];
        foreach ($keys as $k) {
            if (preg_match('/^([pv])(\d+)$/', (string) $k, $m)) {
                $m[1] === 'p' ? $pids[] = (int) $m[2] : $vids[] = (int) $m[2];
            }
        }
        $bid = $this->businessId;
        $n = Tx::run(static function (BaseConnection $db) use ($pids, $vids, $bid): int {
            $n = 0;
            $taken = static fn (string $code): bool => $db->table('products')->where('business_id', $bid)->groupStart()->where('barcode', $code)->orWhere('sku', $code)->groupEnd()->countAllResults() > 0
                || $db->table('product_variants')->where('business_id', $bid)->where('sku', $code)->countAllResults() > 0;
            if ($pids !== []) {
                foreach ($db->table('products')->select('id, sku, barcode')->where('business_id', $bid)->whereIn('id', $pids)->get()->getResultArray() as $p) {
                    if (trim((string) $p['barcode']) !== '' || trim((string) $p['sku']) !== '') {
                        continue;
                    }
                    $code = self::ean13($bid, (int) $p['id']);
                    if (! $taken($code)) {
                        $db->table('products')->where('id', $p['id'])->where('business_id', $bid)->update(['barcode' => $code]);
                        $n++;
                    }
                }
            }
            if ($vids !== []) {
                $rows = $db->table('product_variants v')->select('v.id, v.sku, p.id AS pid, p.sku AS psku, p.barcode AS pbar')->join('products p', 'p.id = v.product_id')
                    ->where('v.business_id', $bid)->whereIn('v.id', $vids)->get()->getResultArray();
                foreach ($rows as $v) {
                    if (trim((string) $v['sku']) !== '') {
                        continue;
                    }
                    $root = trim((string) ($v['psku'] ?: $v['pbar'])) ?: 'P' . $v['pid'];
                    $code = mb_substr($root, 0, 52) . '-V' . $v['id'];
                    if (! $taken($code)) {
                        $db->table('product_variants')->where('id', $v['id'])->where('business_id', $bid)->update(['sku' => $code]);
                        $n++;
                    }
                }
            }

            return $n;
        });
        if ($n > 0) {
            service('audit')->activity('Product codes created', $n . ' barcode / SKU for labels');
        }

        return $n;
    }

    /** In-store EAN-13: 2 + outlet (4 digits) + product (7 digits) + check digit. */
    public static function ean13(int $businessId, int $productId): string
    {
        $body = '2' . str_pad((string) ($businessId % 10000), 4, '0', STR_PAD_LEFT) . str_pad((string) ($productId % 10000000), 7, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($body) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return $body . (string) ((10 - $sum % 10) % 10);
    }
}
