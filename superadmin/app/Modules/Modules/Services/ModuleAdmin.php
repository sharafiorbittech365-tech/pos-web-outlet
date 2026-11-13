<?php

declare(strict_types=1);

namespace App\Modules\Modules\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ModuleRules;

/**
 * The module catalogue (name, description, opt-in) and add-on prices per business type
 * (module_prices; NULL = not offered to that type). Flushes the catalogue cache after a change.
 */
final class ModuleAdmin
{
    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    /** @return array<string, int> module => tenants of the type that bought it as an add-on (not in plan) */
    public static function addonCounts(string $type): array
    {
        $catalog = service('catalog');
        $rows = db_connect()->table('tenant_addons a')->select('a.module_code, s.plan_id, COUNT(*) AS n')
            ->join('tenants t', 't.id = a.tenant_id')->join('subscriptions s', 's.tenant_id = t.id')
            ->where('t.business_type', $type)->where('t.deleted_at', null)->groupBy('a.module_code, s.plan_id')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $plans = $catalog->plans();
            if (isset($plans[(int) $r['plan_id']]) && ! $catalog->planIncludes($plans[(int) $r['plan_id']], (string) $r['module_code'])) {
                $out[$r['module_code']] = ($out[$r['module_code']] ?? 0) + (int) $r['n'];
            }
        }

        return $out;
    }

    /**
     * Edit a module: name, description, opt-in, base price and — when $rules is given — its
     * dependency & compatibility rules (needs, conflicts, requires_types) and its price for every
     * business type ('' = not offered). Rules are validated against the whole catalogue
     * (no unknown codes, no needs loop, no conflict with a module it depends on, core modules stay
     * rule-free) before anything is written.
     *
     * @param array{name: string, description?: string, is_opt_in?: string, base_price?: string} $in
     * @param array{needs: list<string>, conflicts: list<string>, types: list<string>, prices: array<string, string>}|null $rules
     */
    public function update(string $code, array $in, ?array $rules = null): void
    {
        $db = db_connect();
        $catalog = service('catalog');
        $m = $db->table('modules')->where('code', $code)->get()->getRowArray() ?? throw new NotFoundException('Module not found.');
        $row = ['name' => trim($in['name']), 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'is_opt_in' => ($in['is_opt_in'] ?? '0') === '1' && ! (bool) $m['is_core'] ? 1 : 0];
        if (array_key_exists('self_select', $m)) {
            $row['self_select'] = ($in['self_select'] ?? '1') === '1' ? 1 : 0;
        }
        if (isset($in['base_price']) && $in['base_price'] !== '') {
            $row['base_price'] = round((float) $in['base_price'], 2);
        }
        $priceRows = [];
        if ($rules !== null) {
            $clean = static fn (array $l): array => array_values(array_unique(array_filter(array_map('strval', $l), static fn (string $c): bool => $c !== '')));
            $needs = $clean($rules['needs']);
            $conflicts = $clean($rules['conflicts']);
            $types = $clean($rules['types']);
            $errors = ModuleRules::catalogueErrors($catalog->modules(), $code, $needs, $conflicts, $types, array_keys($catalog->businessTypes()));
            foreach ($rules['prices'] as $type => $v) {
                $v = trim((string) $v);
                if (! isset($catalog->businessTypes()[$type])) {
                    continue;
                }
                if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 1000000)) {
                    $errors['prices'] = 'Prices must be positive numbers (empty = not offered).';
                    continue;
                }
                $priceRows[] = [$code, (string) $type, $v === '' ? null : round((float) $v, 2)];
            }
            if ($errors !== []) {
                throw new ValidationException('Please check the module rules.', $errors);
            }
            $row += ['needs' => json_encode($needs), 'conflicts' => json_encode($conflicts), 'requires_types' => $types === [] ? null : json_encode($types)];
        }
        $changes = array_keys(array_filter($row, static fn ($v, $k): bool => (string) $v !== (string) ($m[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        Tx::run(function (BaseConnection $db) use ($code, $row, $priceRows, $catalog, &$changes): void {
            $db->table('modules')->where('code', $code)->update($row);
            if (isset($row['conflicts'])) {
                // conflicts are symmetric and edited from either side: the pair now lives on this module only
                foreach ($catalog->modules() as $oc => $om) {
                    if ($oc !== $code && in_array($code, (array) ($om['conflicts'] ?? []), true)) {
                        $db->table('modules')->where('code', $oc)->update(['conflicts' => json_encode(array_values(array_diff((array) $om['conflicts'], [$code])))]);
                    }
                }
            }
            $n = 0;
            foreach ($priceRows as [, $type, $price]) {
                $n += $catalog->modulePrice($code, $type) !== $price ? 1 : 0;
            }
            if ($priceRows !== []) {
                $db->query('INSERT INTO module_prices (module_code, business_type, monthly_price) VALUES ' . implode(',', array_fill(0, count($priceRows), '(?,?,?)'))
                    . ' ON DUPLICATE KEY UPDATE monthly_price = VALUES(monthly_price)', array_merge(...$priceRows));
            }
            if ($n > 0) {
                $changes[] = $n . ' price(s)';
            }
            service('audit')->platform('Module changed', $code . ' · ' . ($changes ? implode(', ', $changes) : 'no change'), null, $this->actor);
        });
        $catalog->flush();
        if ($changes !== [] && array_diff($changes, ['name', 'description', 'base_price']) !== []) {
            $this->invalidateAll();
        }
    }

    /**
     * Create or edit a priced feature of a module (module_features). The price for one business
     * type can override the flat price ('' = use the flat price; $notOffered = hide it for that type).
     *
     * @param array{code: string, module_code: string, name: string, description?: string, price: string, is_default?: string, self_select?: string, type?: string, type_price?: string, not_offered?: string} $in
     */
    public function saveFeature(array $in, bool $isNew): string
    {
        $catalog = service('catalog');
        $code = strtolower(trim($in['code']));
        $module = (string) $in['module_code'];
        $errors = [];
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z0-9_]{2,30}$/', $code) || strlen($code) > 48) {
            $errors['code'] = 'Use <module>.<name>, lowercase letters, digits and _ (e.g. invoicing.recurring).';
        }
        if (! isset($catalog->modules()[$module])) {
            $errors['module_code'] = 'Choose a module.';
        } elseif (! str_starts_with($code, $module . '.')) {
            $errors['code'] = 'The code must start with the module code: ' . $module . '.';
        }
        $price = trim((string) $in['price']);
        if (! is_numeric($price) || (float) $price < 0 || (float) $price > 1000000) {
            $errors['price'] = 'Enter a monthly price (0 = free).';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the feature.', $errors);
        }
        $db = db_connect();
        $old = $db->table('module_features')->where('code', $code)->get()->getRowArray();
        if ($isNew && $old !== null) {
            throw new ValidationException('That feature code exists.', ['code' => 'That code is taken.']);
        }
        if (! $isNew && $old === null) {
            throw new NotFoundException('Feature not found.');
        }
        $typePrices = json_decode((string) ($old['type_prices'] ?? '{}'), true) ?: [];
        $type = (string) ($in['type'] ?? '');
        if ($type !== '' && isset($catalog->businessTypes()[$type])) {
            $tp = trim((string) ($in['type_price'] ?? ''));
            if (($in['not_offered'] ?? '0') === '1') {
                $typePrices[$type] = null;
            } elseif ($tp === '') {
                unset($typePrices[$type]);
            } elseif (is_numeric($tp) && (float) $tp >= 0) {
                $typePrices[$type] = round((float) $tp, 2);
            } else {
                throw new ValidationException('Please check the feature.', ['type_price' => 'Enter a price or leave it empty.']);
            }
        }
        $now = date('Y-m-d H:i:s');
        $row = ['module_code' => $module, 'name' => mb_substr(trim($in['name']), 0, 80), 'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 255) ?: null,
            'price' => round((float) $price, 2), 'type_prices' => $typePrices === [] ? null : json_encode($typePrices), 'is_default' => ($in['is_default'] ?? '0') === '1' ? 1 : 0,
            'self_select' => ($in['self_select'] ?? '0') === '1' ? 1 : 0, 'updated_at' => $now];
        if ($isNew) {
            $db->table('module_features')->insert($row + ['code' => $code, 'is_active' => 1, 'sort_order' => count($catalog->features($module)), 'created_at' => $now]);
        } else {
            $db->table('module_features')->where('code', $code)->update($row);
        }
        service('audit')->platform($isNew ? 'Feature created' : 'Feature changed', $code . ' · ' . $row['name'] . ' · ' . number_format((float) $row['price']) . '/mo' . ($row['is_default'] ? ' · default' : ''), null, $this->actor);
        $catalog->flush();
        $this->invalidateAll();

        return $code;
    }

    public function deleteFeature(string $code): void
    {
        $db = db_connect();
        $f = $db->table('module_features')->where('code', $code)->get()->getRowArray() ?? throw new NotFoundException('Feature not found.');
        $n = $db->table('tenant_features')->where('feature_code', $code)->where('is_enabled', 1)->countAllResults();
        if ($n > 0 && ! (bool) $f['is_default']) {
            // customers pay for it: retire it instead of deleting (keeps their selection out of new bills)
            $db->table('module_features')->where('code', $code)->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->table('module_features')->where('code', $code)->delete();
        }
        service('audit')->platform('Feature removed', $code . ($n > 0 ? ' · retired (' . $n . ' customer(s))' : ''), null, $this->actor);
        service('catalog')->flush();
        $this->invalidateAll();
    }

    /**
     * Recommended modules for a business type: switched on for new accounts and outlets of the type
     * (see OmniPOS\Core\Services\RecommendedModules).
     *
     * @param list<string> $codes
     * @return list<string> the saved list
     */
    public function saveRecommended(string $type, array $codes): array
    {
        $catalog = service('catalog');
        if (! isset($catalog->businessTypes()[$type])) {
            throw new NotFoundException('Unknown business type.');
        }
        $modules = $catalog->modules();
        $list = array_values(array_unique(array_filter(array_map('strval', $codes), static fn (string $c): bool => isset($modules[$c]) && ! (bool) $modules[$c]['is_core'])));
        $pairs = ModuleRules::conflictPairs($modules, $list);
        if ($pairs !== []) {
            throw new ValidationException('Recommended modules must work together.', ['recommended' => $modules[$pairs[0][0]]['name'] . ' cannot run together with ' . $modules[$pairs[0][1]]['name'] . '.']);
        }
        // a recommended module brings the modules it needs
        foreach ($list as $c) {
            foreach (ModuleRules::needsClosure($modules, $c) as $n) {
                if (! in_array($n, $list, true) && ! (bool) ($modules[$n]['is_core'] ?? false)) {
                    $list[] = $n;
                }
            }
        }
        $order = array_flip(array_keys($modules));
        usort($list, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        db_connect()->table('business_types')->where('code', $type)->update(['recommended_modules' => json_encode($list)]);
        service('audit')->platform('Recommended modules', $catalog->typeName($type) . ' · ' . ($list ? implode(', ', $list) : 'none'), null, $this->actor);
        $catalog->flush();

        return $list;
    }

    /**
     * @param array<string, string> $prices module code => '' (not offered) or monthly price
     * @return int prices changed
     */
    public function savePrices(string $type, array $prices): int
    {
        $catalog = service('catalog');
        if (! isset($catalog->businessTypes()[$type])) {
            throw new NotFoundException('Unknown business type.');
        }
        $modules = $catalog->modules();
        $rows = [];
        foreach ($prices as $code => $v) {
            if (! isset($modules[$code])) {
                continue;
            }
            $v = trim((string) $v);
            if ($v !== '' && (! is_numeric($v) || (float) $v < 0 || (float) $v > 1000000)) {
                throw new ValidationException('Prices must be positive numbers.', ['prices' => 'Invalid price for ' . $modules[$code]['name'] . '.']);
            }
            $rows[] = [(string) $code, $type, $v === '' ? null : round((float) $v, 2)];
        }
        $n = Tx::run(function (BaseConnection $db) use ($rows, $catalog, $type): int {
            $n = 0;
            foreach ($rows as [$code, , $price]) {
                if ($catalog->modulePrice($code, $type) !== $price) {
                    $n++;
                }
            }
            if ($rows !== []) {
                $db->query('INSERT INTO module_prices (module_code, business_type, monthly_price) VALUES ' . implode(',', array_fill(0, count($rows), '(?,?,?)'))
                    . ' ON DUPLICATE KEY UPDATE monthly_price = VALUES(monthly_price)', array_merge(...$rows));
            }
            service('audit')->platform('Module prices saved', $catalog->typeName($type) . ' · ' . $n . ' price(s) changed', null, $this->actor);

            return $n;
        });
        service('catalog')->flush();
        if ($n > 0) {
            $this->invalidateAll($type);
        }

        return $n;
    }

    /** Module access depends on module prices and opt-in flags: recompute for the affected tenants. */
    private function invalidateAll(?string $type = null): void
    {
        $b = db_connect()->table('tenants')->select('id')->where('deleted_at', null);
        if ($type !== null) {
            $b->where('business_type', $type);
        }
        $access = service('moduleAccess');
        foreach (array_column($b->get()->getResultArray(), 'id') as $tid) {
            $access->invalidate((int) $tid);
        }
    }
}
