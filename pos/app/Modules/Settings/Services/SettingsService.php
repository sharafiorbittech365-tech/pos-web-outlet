<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Libraries\PosSession;
use App\Modules\BackOffice\Support\Icons;
use App\Modules\Orders\Services\PrintSettings;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Pricing\TaxPolicy;
use OmniPOS\Core\Services\BusinessTypeSetup;
use OmniPOS\Core\Services\OutletProvisioner;

/**
 * Business setup of the current outlet (columns of businesses + the settings JSON) and the
 * account's outlets (add one from a business-type template within the plan's outlet limit).
 */
final class SettingsService
{
    /** [key, label, help] — one list for Super Admin and POS (BusinessTypeSetup::FEATURES) */
    public const FEATURES = BusinessTypeSetup::FEATURES;
    public const PAYMENTS = ['cash' => 'Cash', 'card' => 'Card', 'mobile' => 'bKash / Nagad / Rocket', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'credit' => 'Credit (due book)'];
    public const PRINT_FORMATS = ['thermal' => 'POS receipt (80 mm)', 'a4' => 'A4 invoice', 'a5' => 'A5 invoice', 'letterhead' => 'Letterhead'];
    public const LABELS = BusinessTypeSetup::LABELS;

    /**
     * The switches of FEATURES this outlet may turn on (offered for its type, module on, not switched
     * off by Super Admin).
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function availableFeatures(): array
    {
        $allowed = service('posSession')->allowedFeatures();

        return array_values(array_filter(self::FEATURES, static fn (array $f): bool => in_array($f[0], $allowed, true)));
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function tid(): int
    {
        return service('tenant')->tenantId();
    }

    /** @return array<string, mixed> businesses row (settings decoded) of the current outlet, fresh from the database */
    public function business(): array
    {
        $b = $this->db()->table('businesses b')->select('b.*, bt.name AS type_name, bt.icon AS type_icon')->join('business_types bt', 'bt.code = b.business_type')
            ->where('b.id', service('tenant')->businessId())->where('b.tenant_id', $this->tid())->get()->getRowArray() ?? throw new NotFoundException('Outlet not found.');
        $b['settings'] = json_decode((string) ($b['settings'] ?? '{}'), true) ?: [];

        return $b;
    }

    /**
     * Save the business editor. Every tab posts together; unknown keys are ignored.
     *
     * @param array<string, mixed> $in
     */
    public function save(array $in): void
    {
        $b = $this->business();
        $s = $b['settings'];
        $errors = [];
        $str = static fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $num = static function (string $k, float $min, float $max, float $def) use ($in, &$errors): float {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v === '') {
                return $def;
            }
            if (! is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                $errors[$k] = 'Enter a number from ' . $min . ' to ' . $max . '.';

                return $def;
            }

            return (float) $v;
        };

        $name = $str('name', 160);
        if ($name === null) {
            $errors['name'] = 'Business name is required.';
        }
        $email = $str('email', 160);
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email or leave it empty.';
        }
        $lat = trim((string) ($in['lat'] ?? ''));
        $lng = trim((string) ($in['lng'] ?? ''));
        if (($lat !== '' || $lng !== '') && (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
            $errors['lat'] = 'Latitude −90…90 and longitude −180…180, or leave both empty.';
        }
        $symbol = $str('currency_symbol', 8) ?? '৳';
        $code = strtoupper((string) ($str('currency_code', 3) ?? 'BDT'));
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            $errors['currency_code'] = 'Three letters, e.g. BDT.';
        }
        $rates = TaxPolicy::parseRates((string) ($in['tax_rates'] ?? ''));
        if (trim((string) ($in['tax_rates'] ?? '')) !== '' && $rates === []) {
            $errors['tax_rates'] = 'Comma-separated rates from 0 to 100, e.g. 0, 5, 7.5, 15.';
        }
        $payments = array_values(array_intersect(array_keys(self::PAYMENTS), array_map('strval', (array) ($in['payments'] ?? []))));
        if ($payments === []) {
            $errors['payments'] = 'Enable at least one payment method.';
        }
        $row = [
            'name' => $name, 'phone' => $str('phone', 32), 'email' => $email, 'address' => $str('address', 255), 'city' => $str('city', 64), 'area' => $str('area', 64),
            'lat' => $lat !== '' ? round((float) $lat, 6) : null, 'lng' => $lng !== '' ? round((float) $lng, 6) : null, 'delivery_km' => ($dk = $num('delivery_km', 0, 500, 0)) > 0 ? $dk : null,
            'currency_code' => $code, 'currency_symbol' => $symbol, 'currency_decimals' => (int) $num('currency_decimals', 0, 3, 2),
            'tax_label' => $str('tax_label', 16) ?? 'VAT', 'tax_rate' => $num('tax_rate', 0, 100, 0), 'tax_inclusive' => ! empty($in['tax_inclusive']) ? 1 : 0,
            'tax_rates' => mb_substr(implode(', ', array_map(static fn (float $r): string => rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.'), $rates ?: [0.0, 5.0, 7.5, 10.0, 15.0])), 0, 80),
            'service_charge_pct' => $num('service_charge_pct', 0, 100, 0), 'round_to' => $num('round_to', 0, 1000, 0), 'low_stock_alert' => $num('low_stock_alert', 0, 1000000, 0),
            'stock_enabled' => ! empty($in['stock_enabled']) ? 1 : 0,
        ];
        $prefix = (string) ($in['invoice_prefix'] ?? '');
        if ($prefix !== '' && ! preg_match('/^[A-Za-z0-9\-\/#]{0,12}$/', $prefix)) {
            $errors['invoice_prefix'] = 'Letters, digits, - / # only (12 at most).';
        }
        $pad = (int) $num('invoice_pad', 0, 8, 0);
        $color = (string) ($in['theme_primary'] ?? '');
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }

        // settings JSON (merge — keys this form does not own are kept)
        // only switches this outlet may use can be turned on; the others are saved as off
        $features = (array) ($s['features'] ?? []);
        $allowed = array_column(self::availableFeatures(), 0);
        foreach (self::FEATURES as [$k]) {
            $features[$k] = in_array($k, $allowed, true) && ! empty($in['features'][$k]);
        }
        $features['orderTypes'] = self::list((string) ($in['order_types'] ?? ''), 40);
        if (is_numeric($in['min_minutes'] ?? null)) {
            $features['minMinutes'] = max(0, (int) $in['min_minutes']);
        }
        if (is_numeric($in['express_rate'] ?? null)) {
            $features['expressRate'] = max(0, min(500, (float) $in['express_rate']));
        }
        $s['features'] = $features;
        if (! empty($in['pos_present'])) {
            $s['pos'] = \App\Modules\Sales\Services\PosSettings::fromForm($in);
        }
        if (! empty($in['purchasing_present'])) {
            $s['purchasing'] = \App\Modules\Purchases\Services\PurchaseSettings::fromForm($in);
        }
        $labels = (array) ($s['labels'] ?? []);
        foreach (array_keys(self::LABELS) as $k) {
            if (! array_key_exists($k, (array) ($in['labels'] ?? []))) {
                continue;
            }
            // empty = use the business type's word (BusinessTypeSetup::terms())
            $v = trim((string) $in['labels'][$k]);
            if ($v !== '') {
                $labels[$k] = mb_substr($v, 0, 40);
            } else {
                unset($labels[$k]);
            }
        }
        $s['labels'] = $labels;
        $s['payments'] = $payments;
        $s['expenseCats'] = self::list((string) ($in['expense_cats'] ?? ''), 60) ?: ['Other'];
        $s['priceGroups'] = self::list((string) ($in['price_groups'] ?? ''), 32) ?: ['Retail'];
        $s['serviceStaff'] = self::list((string) ($in['service_staff'] ?? ''), 100);
        $s['staff'] = self::list((string) ($in['staff'] ?? ''), 100);
        $s['typeCharges'] = self::typeCharges((string) ($in['type_charges'] ?? ''));
        $s['branch'] = mb_substr(trim((string) ($in['branch'] ?? '')), 0, 80);
        $s['bin'] = mb_substr(trim((string) ($in['bin'] ?? '')), 0, 40);
        $s['terms'] = mb_substr(trim((string) ($in['terms'] ?? '')), 0, 2000);
        $s['printFormat'] = array_key_exists((string) ($in['print_format'] ?? ''), self::PRINT_FORMATS) ? (string) $in['print_format'] : ($s['printFormat'] ?? 'thermal');
        // paper size and copies per document (receipt, invoice, challan, kitchen ticket)
        $s['print'] = PrintSettings::fromForm($in, ['settings' => $s]);
        if (! empty($in['printer_present'])) {
            $s['printer'] = PrintSettings::printerFromForm($in, ['settings' => $s]);
        }
        $s['logoIcon'] = Icons::valid((string) ($in['logo_icon'] ?? ''), (string) ($s['logoIcon'] ?? 'store'));
        $s['invoice'] = ['prefix' => $prefix, 'num' => ['year' => ! empty($in['invoice_year']), 'pad' => $pad]] + (array) ($s['invoice'] ?? []);
        $s['theme'] = array_merge((array) ($s['theme'] ?? []), preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? ['primary' => strtoupper($color)] : []);
        $open = (string) ($in['hours_open'] ?? '');
        $close = (string) ($in['hours_close'] ?? '');
        if (preg_match('/^\d{2}:\d{2}$/', $open) && preg_match('/^\d{2}:\d{2}$/', $close) && $open < $close) {
            $s['hours'] = ['open' => $open, 'close' => $close, 'step' => max(10, min(240, (int) ($in['hours_step'] ?? 30)))];
        }
        $row['settings'] = json_encode($s, JSON_UNESCAPED_UNICODE);
        $row['updated_at'] = date('Y-m-d H:i:s');

        $this->db()->table('businesses')->where('id', $b['id'])->where('tenant_id', $this->tid())->update($row);
        PosSession::forgetBusiness((int) $b['id']);
        service('audit')->activity('Setup saved', (string) $name);
    }

    /** @return list<string> comma / newline separated list, trimmed, unique */
    private static function list(string $txt, int $max): array
    {
        $out = [];
        foreach (preg_split('/[,\n]+/', $txt) ?: [] as $p) {
            $p = mb_substr(trim($p), 0, $max);
            if ($p !== '' && ! in_array($p, $out, true)) {
                $out[] = $p;
            }
        }

        return array_slice($out, 0, 50);
    }

    /** "Take Away=20, Delivery=5%" → {"Take Away": {kind: fixed, value: 20}, …} */
    private static function typeCharges(string $txt): array|object
    {
        $out = [];
        foreach (self::list($txt, 80) as $p) {
            if (preg_match('/^(.+?)\s*=\s*([\d.]+)\s*(%?)$/', $p, $m)) {
                $out[trim($m[1])] = ['kind' => $m[3] !== '' ? 'percent' : 'fixed', 'value' => (float) $m[2]];
            }
        }

        return $out === [] ? (object) [] : $out;
    }

    public static function typeChargesText(array $tc): string
    {
        $parts = [];
        foreach ($tc as $k => $v) {
            $parts[] = $k . '=' . rtrim(rtrim(number_format((float) ($v['value'] ?? 0), 2, '.', ''), '0'), '.') . (($v['kind'] ?? '') === 'percent' ? '%' : '');
        }

        return implode(', ', $parts);
    }

    /* ------------------------------------------------------------------ outlets */

    public function outletsQuery(): BaseBuilder
    {
        return $this->db()->table('businesses b')
            ->select('b.id, b.name, b.business_type, b.area, b.city, b.is_active, b.created_at, b.currency_code, bt.name AS type_name, bt.icon AS type_icon,
                      (SELECT COUNT(*) FROM products p WHERE p.business_id = b.id AND p.deleted_at IS NULL) AS products,
                      (SELECT COUNT(*) FROM user_businesses ub WHERE ub.business_id = b.id) AS users')
            ->join('business_types bt', 'bt.code = b.business_type')
            ->where('b.tenant_id', $this->tid());
    }

    /** @return array{count: int, limit: int} */
    public function outletCounts(): array
    {
        return ['count' => $this->db()->table('businesses')->where('tenant_id', $this->tid())->where('is_active', 1)->countAllResults(), 'limit' => service('posSession')->access()->limitOutlets];
    }

    /** @return array<string, array{name: string, icon: string, blurb: string}> business types with a template */
    public function templates(): array
    {
        $out = [];
        $types = service('catalog')->businessTypes();
        foreach ((new OutletProvisioner())->templates()['templates'] as $id => $t) {
            if (isset($types[$id]) && (bool) $types[$id]['is_active']) {
                $out[$id] = ['name' => (string) $t['name'], 'icon' => (string) ($t['icon'] ?? 'store'), 'blurb' => (string) ($t['blurb'] ?? ''), 'bn' => (string) ($t['bn'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Add an outlet from a business-type template (within the plan's outlet limit). The owner(s)
     * get the owner role there; with $copyTeam the current outlet's staff keep their roles.
     *
     * Products: 'template' (starter products), 'copy' (none now; copied from copy_from right after, same business type) or 'empty'.
     *
     * @param array<string, mixed> $in name, type, area, city, phone, address, copy_team, products, copy_from
     */
    public function addOutlet(array $in): int
    {
        $errors = [];
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 160);
        if ($name === '') {
            $errors['name'] = 'Enter the outlet name.';
        }
        $type = (string) ($in['type'] ?? '');
        if (! array_key_exists($type, $this->templates())) {
            $errors['type'] = 'Choose a business type.';
        }
        $products = in_array($in['products'] ?? '', ['template', 'copy', 'empty'], true) ? (string) $in['products'] : 'template';
        if ($products === 'copy' && ! isset($errors['type'])) {
            $from = (int) ($in['copy_from'] ?? 0);
            $ok = array_filter(service('posSession')->outlets(), static fn (array $o): bool => (int) $o['id'] === $from && (string) $o['business_type'] === $type) !== [];
            if (! $ok) {
                $errors['copy_from'] = 'Choose one of your outlets of the same business type to copy products from.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the new outlet.', $errors);
        }
        $access = service('posSession')->access();
        if (! $access->withinOutlets($this->outletCounts()['count'])) {
            throw new BusinessRuleException('Your plan allows ' . $access->limitOutlets . ' outlet' . ($access->limitOutlets === 1 ? '' : 's') . '. Upgrade the plan to add another.');
        }
        $tid = $this->tid();
        $currentBid = service('tenant')->businessId();
        $copy = ! empty($in['copy_team']);
        $samples = $products === 'template';
        $bid = Tx::run(function (BaseConnection $db) use ($tid, $type, $name, $in, $currentBid, $copy, $samples): int {
            // plan limit again under a lock on the account (two concurrent requests cannot both pass)
            (new \OmniPOS\Core\Services\OutletPolicy())->assertCanAdd($tid);
            $ownerRole = (int) ($db->table('roles')->select('id')->where('tenant_id', $tid)->where('is_locked', 1)->orderBy('id')->limit(1)->get()->getRow('id') ?? 0);
            if ($ownerRole === 0) {
                throw new BusinessRuleException('The account has no owner role.');
            }
            $userRoles = [];
            foreach ($db->table('users')->select('id')->where('tenant_id', $tid)->where('is_owner', 1)->get()->getResultArray() as $o) {
                $userRoles[(int) $o['id']] = $ownerRole;
            }
            if ($copy) {
                foreach ($db->table('user_businesses ub')->select('ub.user_id, ub.role_id')->join('users u', 'u.id = ub.user_id')->where('ub.business_id', $currentBid)->where('u.tenant_id', $tid)->where('u.is_owner', 0)->get()->getResultArray() as $ub) {
                    $userRoles[(int) $ub['user_id']] = (int) $ub['role_id'];
                }
            }
            $me = service('tenant')->userId();
            if ($me !== null && ! isset($userRoles[$me])) {
                $userRoles[$me] = $ownerRole;
            }

            return (new OutletProvisioner())->create($tid, $type, [
                'name' => $name, 'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 64) ?: null, 'city' => mb_substr(trim((string) ($in['city'] ?? '')), 0, 64) ?: 'Dhaka',
                'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 32) ?: null, 'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 255) ?: null,
                'branch' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 80),
            ], $userRoles, $samples);
        });
        service('audit')->activity('Outlet added', $name . ' · ' . $type);
        service('usage')->refresh($tid);

        return $bid;
    }

    public function setOutletActive(int $id, bool $active): void
    {
        $db = $this->db();
        $b = $db->table('businesses')->select('id, name, is_active')->where('id', $id)->where('tenant_id', $this->tid())->get()->getRowArray() ?? throw new NotFoundException('Outlet not found.');
        if (! $active && $id === service('tenant')->businessId()) {
            throw new BusinessRuleException('Switch to another outlet before closing this one.');
        }
        // the multi-outlet website takes its logo, colours and currency from its home outlet
        if (! $active && $db->tableExists('multisites', false) && $db->table('multisites')->where('tenant_id', $this->tid())->where('home_business_id', $id)->countAllResults() > 0) {
            throw new BusinessRuleException('This outlet is the home of your multi-outlet website. Choose another home outlet in Website › Multi-outlet first.');
        }
        if ($active && ! (bool) $b['is_active'] && ! service('posSession')->access()->withinOutlets($this->outletCounts()['count'])) {
            throw new BusinessRuleException('Your plan outlet limit is reached. Upgrade to re-open this outlet.');
        }
        Tx::run(function (BaseConnection $db) use ($id, $active, $b): void {
            if ($active && ! (bool) $b['is_active']) {
                (new \OmniPOS\Core\Services\OutletPolicy())->assertCanAdd($this->tid());
            }
            $db->table('businesses')->where('id', $id)->where('tenant_id', $this->tid())->update(['is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        });
        PosSession::forgetBusiness($id);
        service('audit')->activity($active ? 'Outlet re-opened' : 'Outlet closed', (string) $b['name']);
        service('usage')->refresh($this->tid());
    }
}
