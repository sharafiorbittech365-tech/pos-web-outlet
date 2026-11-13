<?php

declare(strict_types=1);

namespace App\Modules\Products\Services;

use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Rich product information kept beside the product: details (slug, short description, price range, sample price, MOQ,
 * status, origin, video, SEO), the supplier block, packaging & delivery, customization services, and dynamic
 * attributes (definitions are created on the fly, so a new kind of product never needs a schema change).
 * Two steps so a bad value stops the whole save: prepare() validates the form, persist() writes it.
 */
final class ProductExtras
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'boolean' => 'Yes / No', 'select' => 'Dropdown (one)', 'multiselect' => 'Multi-select', 'measure' => 'Measurement + unit'];
    public const STATUSES = ['active' => 'Active', 'draft' => 'Draft (hidden online)', 'inactive' => 'Inactive (hidden everywhere)'];
    public const CUSTOMIZATIONS = ['logo' => 'Customized logo', 'packaging' => 'Customized packaging', 'graphic' => 'Graphic customization'];
    public const SUPPLIER_TYPES = ['manufacturer' => 'Manufacturer', 'trading' => 'Trading company', 'wholesaler' => 'Wholesaler', 'distributor' => 'Distributor', 'retailer' => 'Retailer', 'other' => 'Other'];
    public const CURRENCIES = ['BDT', 'USD', 'CNY', 'INR', 'EUR', 'GBP', 'AED', 'SAR', 'MYR', 'SGD', 'JPY', 'PKR'];

    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    /** @return array{details: array<string, mixed>, supplier: array<string, mixed>, packaging: array<string, mixed>, customizations: array<string, array<string, mixed>>, attributes: list<array<string, mixed>>} */
    public function find(int $productId): array
    {
        $d = $this->db()->table('product_details')->where('product_id', $productId)->get()->getRowArray() ?? [];
        $json = static fn (mixed $v): array => is_array($a = json_decode((string) $v, true)) ? $a : [];
        $cust = [];
        foreach ($json($d['customizations'] ?? null) as $c) {
            if (is_array($c) && isset($c['type'])) {
                $cust[(string) $c['type']] = $c;
            }
        }
        $attrs = $this->db()->table('product_attributes a')->select('a.attribute_id, a.value_text, a.value_num, a.unit AS value_unit, d.name, d.code, d.type, d.unit, d.options')
            ->join('product_attribute_defs d', 'd.id = a.attribute_id')->where('a.product_id', $productId)->orderBy('a.sort_order')->orderBy('a.id')->get()->getResultArray();
        foreach ($attrs as &$a) {
            $a['options'] = $json($a['options']);
        }
        unset($a);

        return ['details' => $d, 'supplier' => $json($d['supplier'] ?? null), 'packaging' => $json($d['packaging'] ?? null), 'customizations' => $cust, 'attributes' => $attrs];
    }

    /** @return list<array{name: string, type: string, unit: ?string}> attribute names already defined for this outlet (datalist) */
    public function definitions(): array
    {
        return $this->db()->table('product_attribute_defs')->select('name, type, unit')->where('business_id', $this->bid())->orderBy('sort_order')->orderBy('name')->get()->getResultArray();
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: '')));

        return trim($s, '-');
    }

    /**
     * Validates and normalises the form (x_* fields). Throws ValidationException with a message per field.
     *
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public function prepare(array $in, ?int $productId, string $productName): array
    {
        $errors = [];
        $text = static fn (mixed $v, int $max): string => mb_substr(trim((string) $v), 0, $max);
        $num = static function (mixed $v, string $key, string $label) use (&$errors): ?float {
            $v = trim((string) $v);
            if ($v === '') {
                return null;
            }
            if (! is_numeric($v) || (float) $v < 0) {
                $errors[$key] = $label . ' must be a number of zero or more.';

                return null;
            }

            return round((float) $v, 3);
        };
        $min = $num($in['x_price_min'] ?? '', 'x_price_min', 'Minimum price');
        $max = $num($in['x_price_max'] ?? '', 'x_price_max', 'Maximum price');
        if ($min !== null && $max !== null && $min > $max) {
            $errors['x_price_max'] = 'Maximum price must not be lower than the minimum price.';
        }
        $currency = strtoupper($text($in['x_currency'] ?? '', 3));
        if ($currency !== '' && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['x_currency'] = 'Choose a currency.';
        }
        $moq = $num($in['x_moq'] ?? '', 'x_moq', 'Minimum order quantity');
        $status = (string) ($in['x_status'] ?? 'active');
        if (! isset(self::STATUSES[$status])) {
            $status = 'active';
        }
        $video = $text($in['x_video'] ?? '', 300);
        if ($video !== '' && (! filter_var($video, FILTER_VALIDATE_URL) || ! preg_match('#^https://#i', $video))) {
            $errors['x_video'] = 'Use a video link that starts with https://';
        }
        $slug = self::slugify($text($in['x_slug'] ?? '', 160));
        if ($slug === '') {
            $slug = self::slugify($productName);
        }
        $slug = $slug !== '' ? mb_substr($slug, 0, 150) : 'product';
        $base = $slug;
        for ($n = 2; $this->slugTaken($slug, $productId); $n++) {
            $slug = $base . '-' . $n;
        }

        $s = (array) ($in['x_sup'] ?? []);
        $rating = $num($s['rating'] ?? '', 'x_sup_rating', 'Store rating');
        if ($rating !== null && $rating > 5) {
            $errors['x_sup_rating'] = 'Store rating is between 0 and 5.';
        }
        $dispatch = $num($s['dispatch'] ?? '', 'x_sup_dispatch', 'On-time dispatch rate');
        if ($dispatch !== null && $dispatch > 100) {
            $errors['x_sup_dispatch'] = 'On-time dispatch rate is a percentage (0-100).';
        }
        $supplier = [
            'name' => $text($s['name'] ?? '', 120), 'company' => $text($s['company'] ?? '', 160), 'country' => $text($s['country'] ?? '', 60), 'location' => $text($s['location'] ?? '', 120),
            'type' => isset(self::SUPPLIER_TYPES[(string) ($s['type'] ?? '')]) ? (string) $s['type'] : '', 'verified' => ! empty($s['verified']),
            'years' => $num($s['years'] ?? '', 'x_sup_years', 'Years of experience'), 'response' => $text($s['response'] ?? '', 40),
            'dispatch' => $dispatch, 'rating' => $rating, 'reviews' => $num($s['reviews'] ?? '', 'x_sup_reviews', 'Review count'),
        ];
        $supplier = array_filter($supplier, static fn ($v): bool => $v !== '' && $v !== null && $v !== false);

        $k = (array) ($in['x_pkg'] ?? []);
        $packaging = [
            'unit' => $text($k['unit'] ?? '', 40), 'length' => $num($k['length'] ?? '', 'x_pkg_length', 'Package length'), 'width' => $num($k['width'] ?? '', 'x_pkg_width', 'Package width'),
            'height' => $num($k['height'] ?? '', 'x_pkg_height', 'Package height'), 'dim_unit' => in_array($k['dim_unit'] ?? '', ['mm', 'cm', 'in'], true) ? (string) $k['dim_unit'] : 'cm',
            'gross' => $num($k['gross'] ?? '', 'x_pkg_gross', 'Gross weight'), 'net' => $num($k['net'] ?? '', 'x_pkg_net', 'Net weight'),
            'weight_unit' => in_array($k['weight_unit'] ?? '', ['g', 'kg', 'lb'], true) ? (string) $k['weight_unit'] : 'kg',
            'type' => $text($k['type'] ?? '', 60), 'shipping' => $text($k['shipping'] ?? '', 600),
        ];
        $packaging = array_filter($packaging, static fn ($v): bool => $v !== '' && $v !== null);
        if (array_diff_key($packaging, ['dim_unit' => 1, 'weight_unit' => 1]) === []) {
            $packaging = [];
        }

        $cust = [];
        foreach (self::CUSTOMIZATIONS as $type => $label) {
            $c = (array) (((array) ($in['x_cust'] ?? []))[$type] ?? []);
            $row = ['type' => $type, 'enabled' => ! empty($c['enabled']), 'moq' => $num($c['moq'] ?? '', 'x_cust_' . $type . '_moq', $label . ' minimum order'),
                'cost' => $num($c['cost'] ?? '', 'x_cust_' . $type . '_cost', $label . ' extra cost'), 'note' => $text($c['note'] ?? '', 200)];
            if ($row['enabled'] || $row['moq'] !== null || $row['cost'] !== null || $row['note'] !== '') {
                $cust[] = $row;
            }
        }

        $attrs = $this->prepareAttributes((array) ($in['x_attr'] ?? []), $errors);
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }

        return [
            'details' => ['slug' => $slug, 'short_description' => $text($in['x_short'] ?? '', 500) ?: null, 'price_min' => $min, 'price_max' => $max, 'currency' => $currency ?: null,
                'sample_price' => $num($in['x_sample_price'] ?? '', 'x_sample_price', 'Sample price'), 'moq' => $moq !== null ? (int) $moq : null, 'status' => $status,
                'country_origin' => $text($in['x_origin'] ?? '', 60) ?: null, 'video_url' => $video ?: null, 'seo_title' => $text($in['x_seo_title'] ?? '', 160) ?: null,
                'seo_description' => $text($in['x_seo_desc'] ?? '', 300) ?: null, 'seo_keywords' => $text($in['x_seo_kw'] ?? '', 300) ?: null],
            'supplier' => $supplier, 'packaging' => $packaging, 'customizations' => $cust, 'attributes' => $attrs,
        ];
    }

    private function slugTaken(string $slug, ?int $productId): bool
    {
        $q = $this->db()->table('product_details')->where('business_id', $this->bid())->where('slug', $slug);
        if ($productId !== null) {
            $q->where('product_id <>', $productId);
        }

        return $q->countAllResults() > 0;
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param array<string, string>    $errors
     * @return list<array<string, mixed>>
     */
    private function prepareAttributes(array $rows, array &$errors): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            $name = mb_substr(trim((string) ($r['name'] ?? '')), 0, 80);
            $value = trim((string) ($r['value'] ?? ''));
            if ($name === '' && $value === '') {
                continue;
            }
            $key = 'x_attr_' . $i;
            $code = self::slugify($name) ?: 'attr-' . substr(md5($name), 0, 6);
            if ($name === '' || $value === '') {
                $errors[$key] = 'Give each attribute a name and a value (or clear the row).';
                continue;
            }
            if (isset($seen[$code])) {
                $errors[$key] = $name . ' is listed twice.';
                continue;
            }
            $seen[$code] = true;
            $def = $this->db()->table('product_attribute_defs')->where('business_id', $this->bid())->where('code', $code)->get()->getRowArray();
            $type = $def['type'] ?? (string) ($r['type'] ?? 'text');
            if (! isset(self::TYPES[$type])) {
                $type = 'text';
            }
            $unit = mb_substr(trim((string) ($r['unit'] ?? ($def['unit'] ?? ''))), 0, 16);
            $options = array_values(array_filter(array_map('trim', explode(',', (string) ($r['options'] ?? ''))), static fn (string $o): bool => $o !== ''));
            $valueNum = null;
            $shown = mb_substr($value, 0, 500);
            switch ($type) {
                case 'number':
                    if (! is_numeric($value)) {
                        $errors[$key] = $name . ' must be a number.';
                        continue 2;
                    }
                    $valueNum = (float) $value;
                    break;
                case 'boolean':
                    $yes = in_array(strtolower($value), ['1', 'yes', 'y', 'true', 'on', 'হ্যাঁ'], true);
                    $no = in_array(strtolower($value), ['0', 'no', 'n', 'false', 'off', 'না'], true);
                    if (! $yes && ! $no) {
                        $errors[$key] = $name . ' must be Yes or No.';
                        continue 2;
                    }
                    $shown = $yes ? 'Yes' : 'No';
                    $valueNum = $yes ? 1.0 : 0.0;
                    break;
                case 'select':
                    $options[] = $shown;
                    break;
                case 'multiselect':
                    $list = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $o): bool => $o !== '')));
                    $options = array_merge($options, $list);
                    $shown = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
                    break;
                case 'measure':
                    if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m) !== 1) {
                        $errors[$key] = $name . ' needs a number (a range like 20-50 is fine).';
                        continue 2;
                    }
                    $valueNum = (float) $m[0];
                    break;
            }
            $out[] = ['name' => $name, 'code' => $code, 'type' => $type, 'unit' => $unit, 'options' => array_values(array_unique($options)), 'text' => $shown, 'num' => $valueNum, 'sort' => count($out)];
        }

        return $out;
    }

    /** Writes a prepared form. Existing products without details keep working: the row is created on first save. */
    public function persist(int $productId, array $p): void
    {
        $db = $this->db();
        $tenant = service('tenant');
        $now = date('Y-m-d H:i:s');
        $row = $p['details'] + ['supplier' => $p['supplier'] !== [] ? json_encode($p['supplier'], JSON_UNESCAPED_UNICODE) : null, 'packaging' => $p['packaging'] !== [] ? json_encode($p['packaging'], JSON_UNESCAPED_UNICODE) : null,
            'customizations' => $p['customizations'] !== [] ? json_encode($p['customizations'], JSON_UNESCAPED_UNICODE) : null, 'updated_at' => $now];
        if ($db->table('product_details')->where('product_id', $productId)->countAllResults() > 0) {
            $db->table('product_details')->where('product_id', $productId)->update($row);
        } else {
            $db->table('product_details')->insert($row + ['product_id' => $productId, 'tenant_id' => $tenant->tenantId(), 'business_id' => $tenant->businessId()]);
        }
        // draft hides the product online, inactive hides it everywhere (active leaves the form's own switches alone)
        if ($p['details']['status'] === 'draft') {
            $db->table('products')->where('id', $productId)->where('business_id', $tenant->businessId())->update(['is_online' => 0]);
        } elseif ($p['details']['status'] === 'inactive') {
            $db->table('products')->where('id', $productId)->where('business_id', $tenant->businessId())->update(['is_online' => 0, 'is_active' => 0]);
        }

        $keep = [];
        foreach ($p['attributes'] as $a) {
            $def = $db->table('product_attribute_defs')->where('business_id', $tenant->businessId())->where('code', $a['code'])->get()->getRowArray();
            $opts = $a['options'];
            if ($def === null) {
                $db->table('product_attribute_defs')->insert(['tenant_id' => $tenant->tenantId(), 'business_id' => $tenant->businessId(), 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                    'unit' => $a['unit'] !== '' ? $a['unit'] : null, 'options' => $opts !== [] ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null, 'created_at' => $now]);
                $defId = (int) $db->insertID();
            } else {
                $defId = (int) $def['id'];
                if (in_array($def['type'], ['select', 'multiselect'], true)) {
                    $merged = array_values(array_unique(array_merge((array) (json_decode((string) $def['options'], true) ?: []), $opts)));
                    $db->table('product_attribute_defs')->where('id', $defId)->update(['options' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);
                }
            }
            $vals = ['value_text' => $a['text'], 'value_num' => $a['num'], 'unit' => $a['unit'] !== '' ? $a['unit'] : null, 'sort_order' => $a['sort']];
            if ($db->table('product_attributes')->where('product_id', $productId)->where('attribute_id', $defId)->countAllResults() > 0) {
                $db->table('product_attributes')->where('product_id', $productId)->where('attribute_id', $defId)->update($vals);
            } else {
                $db->table('product_attributes')->insert($vals + ['product_id' => $productId, 'attribute_id' => $defId]);
            }
            $keep[] = $defId;
        }
        $q = $db->table('product_attributes')->where('product_id', $productId);
        if ($keep !== []) {
            $q->whereNotIn('attribute_id', $keep);
        }
        $q->delete();
    }
}
