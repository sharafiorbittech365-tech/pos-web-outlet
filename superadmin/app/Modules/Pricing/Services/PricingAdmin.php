<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Super Admin › Pricing: every price, switch, discount rule, package and per-customer price lives in
 * the database and is edited here. After a change the engine cache is flushed and the module access of
 * the affected tenants is recomputed (all tenants for a shared price, one for a customer change).
 */
final class PricingAdmin
{
    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    private function money(mixed $v, string $field, bool $allowEmpty = false): ?float
    {
        $v = trim((string) $v);
        if ($v === '' && $allowEmpty) {
            return null;
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 10000000) {
            throw new ValidationException('Prices must be numbers of zero or more.', [$field => 'Enter a valid amount.']);
        }

        return round((float) $v, 2);
    }

    private function percent(mixed $v, string $field): float
    {
        $v = trim((string) $v);
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
            throw new ValidationException('A percentage is between 0 and 100.', [$field => 'Enter 0–100.']);
        }

        return round((float) $v, 2);
    }

    private function refreshAll(): void
    {
        service('pricing')->flush();
        $access = service('moduleAccess');
        foreach (array_column(db_connect()->table('subscriptions')->select('tenant_id')->get()->getResultArray(), 'tenant_id') as $tid) {
            $access->invalidate((int) $tid);
        }
    }

    /**
     * items[code] = ['price' => '900', 'enabled' => '1', 'in_base' => '0']
     *
     * @param array<string, array<string, string>> $items
     * @return int rows changed
     */
    public function saveItems(array $items): int
    {
        $known = service('pricing')->config()['items'];
        $changes = [];
        $now = date('Y-m-d H:i:s');
        Tx::run(function (BaseConnection $db) use ($items, $known, $now, &$changes): void {
            foreach ($items as $code => $in) {
                if (! isset($known[$code]) || ! is_array($in)) {
                    continue;
                }
                $price = (float) $this->money($in['price'] ?? '', 'items.' . $code);
                $enabled = ($in['enabled'] ?? '1') === '1' ? 1 : 0;
                $inBase = $known[$code]['kind'] === 'module' ? (($in['in_base'] ?? '0') === '1' ? 1 : 0) : 0;
                $label = isset($in['label']) ? trim((string) $in['label']) : (string) $known[$code]['label'];
                $label = $label !== '' ? mb_substr($label, 0, 120) : (string) $known[$code]['label'];
                $max = $known[$code]['max_outlets'];
                if ($known[$code]['kind'] === 'multisite' && isset($in['max_outlets']) && ctype_digit((string) $in['max_outlets']) && (int) $in['max_outlets'] > 0) {
                    $max = (int) $in['max_outlets'];
                }
                if ((float) $known[$code]['price'] !== $price || (int) $known[$code]['is_enabled'] !== $enabled || (int) $known[$code]['in_base'] !== $inBase
                    || (string) $known[$code]['max_outlets'] !== (string) $max || $label !== (string) $known[$code]['label']) {
                    $db->table('pricing_items')->where('code', $code)->update(['price' => $price, 'is_enabled' => $enabled, 'in_base' => $inBase, 'max_outlets' => $max, 'label' => $label, 'updated_at' => $now]);
                    $changes[] = $code . ' ' . number_format((float) $known[$code]['price']) . '→' . number_format($price) . ($enabled ? '' : ' (off)');
                }
            }
            service('audit')->platform('Pricing saved', $changes === [] ? 'no change' : implode(', ', array_slice($changes, 0, 12)), null, $this->actor);
        });
        $this->refreshAll();

        return count($changes);
    }

    /**
     * tiers[id] = [label, min, max, percent, enabled, delete]; $new = the same keys for a row to add.
     *
     * @param array<int|string, array<string, string>> $tiers
     * @param array<string, string>                    $new
     */
    public function saveTiers(array $tiers, array $new): void
    {
        $now = date('Y-m-d H:i:s');
        Tx::run(function (BaseConnection $db) use ($tiers, $new, $now): void {
            $rows = [];
            foreach ($tiers as $id => $in) {
                if (is_array($in)) {
                    $rows[(int) $id] = $in;
                }
            }
            if (trim((string) ($new['min'] ?? '')) !== '') {
                $rows[0] = $new;
            }
            foreach ($rows as $id => $in) {
                if (($in['delete'] ?? '0') === '1' && $id > 0) {
                    $db->table('pricing_discount_tiers')->where('id', $id)->delete();
                    continue;
                }
                $min = trim((string) ($in['min'] ?? ''));
                $max = trim((string) ($in['max'] ?? ''));
                if (! ctype_digit($min) || (int) $min < 1 || ($max !== '' && (! ctype_digit($max) || (int) $max < (int) $min))) {
                    throw new ValidationException('Check the outlet range.', ['tiers' => 'From must be 1 or more, and "to" at least "from" (or empty for no upper limit).']);
                }
                $label = trim((string) ($in['label'] ?? ''));
                $row = ['label' => mb_substr($label !== '' ? $label : ($max === '' ? $min . '+ outlets' : $min . '–' . $max . ' outlets'), 0, 80), 'min_outlets' => (int) $min,
                    'max_outlets' => $max === '' ? null : (int) $max, 'percent' => $this->percent($in['percent'] ?? '0', 'tiers'), 'is_enabled' => ($in['enabled'] ?? '1') === '1' ? 1 : 0, 'updated_at' => $now];
                if ($id > 0) {
                    $db->table('pricing_discount_tiers')->where('id', $id)->update($row);
                } else {
                    $db->table('pricing_discount_tiers')->insert($row);
                }
            }
            service('audit')->platform('Outlet discounts saved', count($rows) . ' rule(s)', null, $this->actor);
        });
        $this->refreshAll();
    }

    /**
     * @param array<string, string> $in    name, description, price, is_active
     * @param list<string>          $items website / multisite / module codes
     */
    public function savePackage(?int $id, array $in, array $items): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ValidationException('Name the package.', ['name' => 'Required, up to 80 characters.']);
        }
        $known = service('pricing')->config()['items'];
        $valid = array_values(array_filter(array_unique($items), static fn (string $c): bool => $c === 'website' || $c === 'multisite' || isset($known['module.' . $c])));
        $row = ['name' => $name, 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'price' => $this->money($in['price'] ?? '', 'price'),
            'items' => json_encode($valid), 'is_active' => ($in['is_active'] ?? '0') === '1' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        $db = db_connect();
        if ($id === null) {
            $code = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'package';
            $base = $code;
            for ($i = 2; $db->table('pricing_packages')->where('code', $code)->countAllResults() > 0; $i++) {
                $code = $base . '-' . $i;
            }
            $db->table('pricing_packages')->insert($row + ['code' => $code, 'created_at' => $row['updated_at']]);
            $id = (int) $db->insertID();
            service('audit')->platform('Package created', $name . ' · ' . number_format((float) $row['price']), null, $this->actor);
        } else {
            if ($db->table('pricing_packages')->where('id', $id)->countAllResults() === 0) {
                throw new NotFoundException('Package not found.');
            }
            $db->table('pricing_packages')->where('id', $id)->update($row);
            service('audit')->platform('Package changed', $name . ' · ' . number_format((float) $row['price']), null, $this->actor);
        }
        $this->refreshAll();

        return $id;
    }

    public function deletePackage(int $id): string
    {
        $db = db_connect();
        $p = $db->table('pricing_packages')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Package not found.');
        $used = $db->table('tenant_pricing')->where('package_id', $id)->countAllResults();
        if ($used > 0) {
            throw new BusinessRuleException($p['name'] . ' is assigned to ' . $used . ' customer(s) — unassign it first or hide it.');
        }
        $db->table('pricing_packages')->where('id', $id)->delete();
        service('audit')->platform('Package deleted', (string) $p['name'], null, $this->actor);
        service('pricing')->flush();

        return (string) $p['name'];
    }

    /**
     * Per-customer pricing: discount (percent / fixed), package, domain and custom item prices.
     *
     * @param array<string, string> $in        discount_type, discount_value, discount_note, package_id, domain_name, domain_cost
     * @param array<string, string> $overrides item code => price ('' = standard price)
     */
    public function saveCustomer(int $tenantId, array $in, array $overrides): void
    {
        $db = db_connect();
        if ($db->table('tenants')->where('id', $tenantId)->where('deleted_at', null)->countAllResults() === 0) {
            throw new NotFoundException('Customer not found.');
        }
        $type = in_array($in['discount_type'] ?? 'none', ['none', 'percent', 'fixed'], true) ? (string) $in['discount_type'] : 'none';
        $value = $type === 'none' ? 0.0 : ($type === 'percent' ? $this->percent($in['discount_value'] ?? '0', 'discount_value') : (float) $this->money($in['discount_value'] ?? '0', 'discount_value'));
        $pkgId = ctype_digit((string) ($in['package_id'] ?? '')) && (int) $in['package_id'] > 0 ? (int) $in['package_id'] : null;
        $pkg = null;
        if ($pkgId !== null) {
            $pkg = $db->table('pricing_packages')->where('id', $pkgId)->get()->getRowArray() ?? throw new ValidationException('Unknown package.', ['package_id' => 'Choose a package from the list.']);
        }
        $known = service('pricing')->config()['items'];
        $now = date('Y-m-d H:i:s');
        $domainCost = $this->money($in['domain_cost'] ?? '', 'domain_cost', true);
        Tx::run(function (BaseConnection $db) use ($tenantId, $in, $type, $value, $pkgId, $pkg, $overrides, $known, $now, $domainCost): void {
            $note = mb_substr(trim((string) ($in['discount_note'] ?? '')), 0, 160) ?: null;
            $domain = mb_substr(strtolower(trim((string) ($in['domain_name'] ?? ''))), 0, 190) ?: null;
            $db->query('INSERT INTO tenant_pricing (tenant_id, discount_type, discount_value, discount_note, package_id, domain_name, domain_cost, updated_at) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), discount_value = VALUES(discount_value), discount_note = VALUES(discount_note), package_id = VALUES(package_id),
                domain_name = VALUES(domain_name), domain_cost = VALUES(domain_cost), updated_at = VALUES(updated_at)', [$tenantId, $type, $value, $note, $pkgId, $domain, $domainCost, $now]);
            $db->table('tenant_price_overrides')->where('tenant_id', $tenantId)->delete();
            $custom = 0;
            foreach ($overrides as $code => $v) {
                $v = $this->money($v, 'prices', true);
                if ($v !== null && isset($known[$code])) {
                    $db->table('tenant_price_overrides')->insert(['tenant_id' => $tenantId, 'item_code' => $code, 'price' => $v, 'updated_at' => $now]);
                    $custom++;
                }
            }
            // the modules of an assigned package are switched on for the customer
            foreach ($pkg ? (array) json_decode((string) $pkg['items'], true) : [] as $m) {
                if ($db->table('modules')->where('code', $m)->countAllResults() > 0) {
                    $db->query('INSERT IGNORE INTO tenant_addons (tenant_id, module_code, added_at) VALUES (?,?,?)', [$tenantId, $m, $now]);
                }
            }
            service('audit')->platform('Customer pricing saved', ($type === 'none' ? 'no discount' : $type . ' ' . $value) . ($pkg ? ' · package ' . $pkg['name'] : '') . ' · ' . $custom . ' custom price(s)', $tenantId, $this->actor);
        });
        service('pricing')->flush();
        service('moduleAccess')->invalidate($tenantId);
    }
}
