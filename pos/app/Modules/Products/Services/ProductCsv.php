<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use App\Modules\BackOffice\Support\Icons;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Models\Generated\CategoriesModel;
use OmniPOS\Core\Models\Generated\ProductsModel;

/**
 * Products CSV (plan feature "csv"): export the catalogue, import / update from a file.
 * Columns (first row = headers): name, sku, barcode, category, brand, price, cost, unit, stock, featured, icon, tax_mode, tax_rate.
 * Only name and price are required. A row whose sku (else barcode, else name) matches updates that product.
 */
final class ProductCsv
{
    public const COLUMNS = ['name', 'sku', 'barcode', 'category', 'brand', 'price', 'cost', 'unit', 'stock', 'featured', 'icon', 'tax_mode', 'tax_rate'];
    private const MAX_ROWS = 5000;

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    public function export(): string
    {
        $db = db_connect();
        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, ',', '"', '\\');
        $last = 0;
        do {
            $rows = $db->table('products p')->select('p.id, p.name, p.sku, p.barcode, c.name AS category, p.brand, p.price, p.cost, p.unit, p.stock_type, p.stock_qty, p.is_featured, p.icon, p.tax_mode, p.tax_rate')
                ->join('categories c', 'c.id = p.category_id', 'left')
                ->where('p.business_id', $this->bid())->where('p.deleted_at', null)->where('p.id >', $last)->orderBy('p.id')->limit(1000)->get()->getResultArray();
            foreach ($rows as $r) {
                fputcsv($out, [$r['name'], $r['sku'], $r['barcode'], $r['category'], $r['brand'], $r['price'], $r['cost'], $r['unit'],
                    $r['stock_type'] === 'none' ? '' : rtrim(rtrim((string) $r['stock_qty'], '0'), '.'), $r['is_featured'] ? 'yes' : '', $r['icon'], $r['tax_mode'], $r['tax_rate']], ',', '"', '\\');
                $last = (int) $r['id'];
            }
        } while (count($rows) === 1000);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return array{created: int, updated: int, skipped: list<string>}
     */
    public function import(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new BusinessRuleException('The file could not be read.');
        }
        $head = fgetcsv($fh, 0, ',', '"', '\\');
        if (! is_array($head)) {
            throw new BusinessRuleException('The file has no header row.');
        }
        $head = array_map(static fn ($h): string => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h))), $head);
        if (! in_array('name', $head, true) || ! in_array('price', $head, true)) {
            throw new BusinessRuleException('The CSV needs at least the “name” and “price” columns.');
        }
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $rows[] = array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
            if (count($rows) > self::MAX_ROWS) {
                throw new BusinessRuleException('At most ' . self::MAX_ROWS . ' rows per file.');
            }
        }
        fclose($fh);
        if ($rows === []) {
            throw new BusinessRuleException('The file has no product rows.');
        }

        $result = Tx::run(function (BaseConnection $db) use ($rows): array {
            $bid = $this->bid();
            $model = new ProductsModel();
            $cats = [];
            foreach ($db->table('categories')->select('id, name')->where('business_id', $bid)->get()->getResultArray() as $c) {
                $cats[mb_strtolower($c['name'])] = (int) $c['id'];
            }
            $created = $updated = 0;
            $skipped = [];
            $stock = service('stock');
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $name = mb_substr(trim((string) ($r['name'] ?? '')), 0, 200);
                $price = trim((string) ($r['price'] ?? ''));
                if ($name === '' || ! is_numeric($price) || (float) $price < 0) {
                    $skipped[] = 'Row ' . $line . ': missing name or price';
                    continue;
                }
                $sku = mb_substr(trim((string) ($r['sku'] ?? '')), 0, 64);
                $barcode = mb_substr(trim((string) ($r['barcode'] ?? '')), 0, 64);
                $q = $db->table('products')->select('id, stock_type, stock_qty')->where('business_id', $bid)->where('deleted_at', null);
                if ($sku !== '') {
                    $q->where('sku', $sku);
                } elseif ($barcode !== '') {
                    $q->where('barcode', $barcode);
                } else {
                    $q->where('name', $name);
                }
                $found = $q->limit(1)->get()->getRowArray();
                $row = ['name' => $name, 'price' => round((float) $price, 2)];
                if ($sku !== '') {
                    $row['sku'] = $sku;
                }
                if ($barcode !== '') {
                    $row['barcode'] = $barcode;
                }
                if (is_numeric($r['cost'] ?? null)) {
                    $row['cost'] = round(max(0, (float) $r['cost']), 2);
                }
                foreach (['brand' => 80, 'unit' => 16] as $k => $max) {
                    if (trim((string) ($r[$k] ?? '')) !== '') {
                        $row[$k] = mb_substr(trim((string) $r[$k]), 0, $max);
                    }
                }
                if (trim((string) ($r['icon'] ?? '')) !== '') {
                    $row['icon'] = Icons::valid(trim((string) $r['icon']));
                }
                if (trim((string) ($r['featured'] ?? '')) !== '') {
                    $row['is_featured'] = preg_match('/^(1|y|yes|true)$/i', trim((string) $r['featured'])) ? 1 : 0;
                }
                if (in_array($r['tax_mode'] ?? '', ProductService::TAX_MODES, true)) {
                    $row['tax_mode'] = $r['tax_mode'];
                }
                if (isset($r['tax_rate']) && is_numeric($r['tax_rate'])) {
                    $row['tax_rate'] = (float) $r['tax_rate'];
                }
                $cname = mb_substr(trim((string) ($r['category'] ?? '')), 0, 100);
                if ($cname !== '') {
                    $key = mb_strtolower($cname);
                    if (! isset($cats[$key])) {
                        $cats[$key] = (int) (new CategoriesModel())->insert(['name' => $cname, 'icon' => 'tag', 'sort_order' => count($cats)]);
                    }
                    $row['category_id'] = $cats[$key];
                }
                $qtyIn = trim((string) ($r['stock'] ?? ''));
                if ($found !== null) {
                    $model->update((int) $found['id'], $row);
                    $pid = (int) $found['id'];
                    $type = $found['stock_type'];
                    $current = (float) $found['stock_qty'];
                    $updated++;
                } else {
                    $type = $qtyIn !== '' ? 'simple' : ((bool) ($this->business()['stock_enabled'] ?? true) ? 'simple' : 'none');
                    $pid = (int) $model->insert($row + ['stock_type' => $type, 'stock_qty' => 0, 'icon' => $row['icon'] ?? 'package', 'is_online' => 1, 'is_active' => 1]);
                    $current = 0.0;
                    $created++;
                }
                if ($qtyIn !== '' && is_numeric($qtyIn) && $type === 'simple') {
                    $delta = round(max(0, (float) $qtyIn) - $current, 3);
                    if (abs($delta) > 1e-9) {
                        $stock->adjust($pid, $delta, $found === null ? 'opening' : 'adjust', 'CSV import');
                    }
                }
            }

            return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
        });
        service('audit')->activity('Products imported', $result['created'] . ' created, ' . $result['updated'] . ' updated, ' . count($result['skipped']) . ' skipped');
        service('usage')->touch(service('tenant')->tenantId());

        return $result;
    }

    /** @return array<string, mixed> */
    private function business(): array
    {
        return service('posSession')->business();
    }
}
