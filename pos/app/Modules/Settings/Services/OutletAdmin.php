<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Libraries\PosSession;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\RecommendedModules;

/**
 * Outlet management for owners (Setup › Outlets): edit any outlet's contact details, who can
 * open each outlet (user_businesses), and the recommended modules of a business type
 * (shown in the "add outlet" wizard and applied after the outlet is created).
 * Adding / closing outlets stays in SettingsService (plan limit via OmniPOS\Core\Services\OutletPolicy).
 */
final class OutletAdmin
{
    private function tid(): int
    {
        return service('tenant')->tenantId();
    }

    /** @return list<array<string, mixed>> every outlet of the account (open and closed) with its contact details */
    public function outlets(): array
    {
        return db_connect()->table('businesses')->select('id, name, business_type, phone, area, city, address, is_active')
            ->where('tenant_id', $this->tid())->orderBy('id')->get()->getResultArray();
    }

    /** @param array<string, mixed> $in name, phone, area, city, address */
    public function update(int $id, array $in): void
    {
        $db = db_connect();
        $b = $db->table('businesses')->select('id, name, phone, area, city, address')->where('id', $id)->where('tenant_id', $this->tid())->get()->getRowArray()
            ?? throw new NotFoundException('Outlet not found.');
        $row = [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 160),
            'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 32) ?: null,
            'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 64) ?: null,
            'city' => mb_substr(trim((string) ($in['city'] ?? '')), 0, 64) ?: null,
            'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 255) ?: null,
        ];
        if ($row['name'] === '') {
            throw new ValidationException('Enter the outlet name.', ['name' => 'Enter the outlet name.']);
        }
        if ($row['phone'] !== null && ! preg_match('/^[0-9+\-\s()]{3,32}$/', $row['phone'])) {
            throw new ValidationException('Check the phone number.', ['phone' => 'Digits, spaces, + and - only.']);
        }
        $changed = array_keys(array_filter($row, static fn ($v, $k): bool => (string) $v !== (string) ($b[$k] ?? ''), ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }
        $db->table('businesses')->where('id', $id)->where('tenant_id', $this->tid())->update($row + ['updated_at' => date('Y-m-d H:i:s')]);
        PosSession::forgetBusiness($id);
        service('audit')->activity('Outlet updated', $row['name'] . ' · ' . implode(', ', $changed));
    }

    /**
     * Team access per outlet: outlet id => people with their role there.
     *
     * @return array<int, list<array{name: string, role: string, owner: bool}>>
     */
    public function access(): array
    {
        $rows = db_connect()->table('user_businesses ub')->select('ub.business_id, u.name, u.is_owner, r.name AS role')
            ->join('users u', 'u.id = ub.user_id')->join('roles r', 'r.id = ub.role_id')->join('businesses b', 'b.id = ub.business_id')
            ->where('b.tenant_id', $this->tid())->where('u.tenant_id', $this->tid())->where('u.is_active', 1)
            ->orderBy('u.is_owner', 'DESC')->orderBy('u.name')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['business_id']][] = ['name' => (string) $r['name'], 'role' => (string) $r['role'], 'owner' => (bool) $r['is_owner']];
        }

        return $out;
    }

    /**
     * Recommended modules per business type for the wizard: type => [[code, name, on]].
     *
     * @param list<string> $types
     * @return array<string, list<array{code: string, name: string, on: bool}>>
     */
    public function recommendations(array $types): array
    {
        $catalog = service('catalog');
        $access = service('posSession')->access();
        $mods = $catalog->modules();
        $out = [];
        foreach ($types as $t) {
            $out[$t] = array_map(static fn (string $c): array => ['code' => $c, 'name' => (string) $mods[$c]['name'], 'on' => $access->has($c)], $catalog->recommended($t));
        }

        return $out;
    }

    /**
     * After a new outlet: switch on the type's recommended modules (add-ons on a trial) or list them.
     *
     * @return array{recommended: list<string>, added: list<string>, suggested: list<string>}
     */
    public function applyRecommended(string $type): array
    {
        $u = service('posSession')->user();
        $res = (new RecommendedModules(service('catalog'), service('moduleAccess')))->apply($this->tid(), $type, ['name' => (string) ($u['name'] ?? 'POS'), 'role' => (string) ($u['role'] ?? 'Owner')]);
        if ($res['added'] !== []) {
            service('audit')->activity('Recommended modules added', implode(', ', $res['added']));
        }

        return $res;
    }
}
