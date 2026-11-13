<?php

declare(strict_types=1);

namespace App\Libraries;

use OmniPOS\Core\Services\BusinessTypeSetup;
use OmniPOS\Core\Services\TenantAccess;

/**
 * The signed-in workspace on this POS server: tenant, current outlet (business) and the user at
 * the till, with their role and permissions. Registered as service('posSession').
 *
 * Session keys (set only by the Auth module):
 *   pos.tenant_id     account signed in on this device (owner/manager email + password)
 *   pos.business_id   outlet currently open
 *   pos.user_id       person using the till (owner after sign-in; staff after "switch user" + PIN)
 *
 * Everything else (user row, role, outlet settings) is re-read per request and cached briefly,
 * so a disabled user or a changed role takes effect within a minute.
 */
final class PosSession
{
    private ?array $user = null;
    private ?array $business = null;

    public function tenantId(): int
    {
        return (int) (session('pos.tenant_id') ?? 0);
    }

    public function businessId(): int
    {
        return (int) (session('pos.business_id') ?? 0);
    }

    public function userId(): int
    {
        return (int) (session('pos.user_id') ?? 0);
    }

    public function signedIn(): bool
    {
        return $this->tenantId() > 0 && $this->businessId() > 0 && $this->user() !== null;
    }

    /**
     * The till user with role profile: id, name, email, is_owner, role_id, role, role_code, color, icon,
     * home, max_discount, locked, perms (list).
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user ?: null;
        }
        $uid = $this->userId();
        $bid = $this->businessId();
        if ($uid === 0 || $bid === 0) {
            return null;
        }
        $key = "omnipos_posuser_{$uid}_{$bid}";
        $row = cache($key);
        if ($row === null) {
            $r = db_connect()->table('users u')
                ->select('u.id, u.tenant_id, u.name, u.email, u.is_owner, u.is_active, r.id AS role_id, r.name AS role, r.code AS role_code, r.color, r.icon, r.home, r.max_discount, r.is_locked, r.permissions')
                ->join('user_businesses ub', 'ub.user_id = u.id')
                ->join('roles r', 'r.id = ub.role_id')
                ->where('u.id', $uid)->where('ub.business_id', $bid)->where('u.tenant_id', $this->tenantId())
                ->get()->getRowArray();
            $row = $r && (bool) $r['is_active'] ? array_merge($r, [
                'is_owner' => (bool) $r['is_owner'], 'locked' => (bool) $r['is_locked'], 'max_discount' => (float) $r['max_discount'],
                'perms' => json_decode((string) $r['permissions'], true) ?: [],
            ]) : false;
            cache()->save($key, $row, 60);
        }
        $this->user = $row;

        return $row ?: null;
    }

    /** @return array<string, mixed> current outlet row with settings decoded (features, labels, payments …) */
    public function business(): array
    {
        if ($this->business !== null) {
            return $this->business;
        }
        $bid = $this->businessId();
        $key = 'omnipos_posbiz_' . $bid;
        $row = cache($key);
        if (! is_array($row)) {
            $row = db_connect()->table('businesses b')->select('b.*, bt.name AS type_name, bt.icon AS type_icon, bt.store_kind, bt.booking_kind')
                ->join('business_types bt', 'bt.code = b.business_type')
                ->where('b.id', $bid)->where('b.tenant_id', $this->tenantId())->get()->getRowArray() ?? [];
            if ($row !== []) {
                $row['settings'] = json_decode((string) ($row['settings'] ?? '{}'), true) ?: [];
                cache()->save($key, $row, 60);
            }
        }
        if ($row !== [] && $this->tenantId() > 0) {
            // switches the type does not offer, whose module is off, or that Super Admin turned off read as off
            // everywhere (till, nav, pages); the outlet's own choice stays stored (storedFeatures())
            $row['stored_features'] = (array) ($row['settings']['features'] ?? []);
            $row['settings']['features'] = BusinessTypeSetup::gate($row['stored_features'], $this->allowedFeatures((string) $row['business_type']));
            $row['terms'] = BusinessTypeSetup::terms((string) $row['business_type'], (array) ($row['settings']['labels'] ?? []));
        }

        return $this->business = $row;
    }

    /** @return list<string> POS switches this outlet may use (BusinessTypeSetup::allowedFeatures) */
    public function allowedFeatures(?string $type = null): array
    {
        return BusinessTypeSetup::allowedFeatures($type ?? (string) ($this->business()['business_type'] ?? ''), $this->access());
    }

    /** A business word of this outlet (BusinessTypeSetup::LABELS key), e.g. term('customers') = "Patients". */
    public function term(string $key): string
    {
        return (string) ($this->business()['terms'][$key] ?? BusinessTypeSetup::LABEL_DEFAULTS[$key] ?? $key);
    }

    /** Forget the rows loaded in this request (after the session's tenant, outlet or user changed). */
    public function reset(): void
    {
        $this->user = null;
        $this->business = null;
    }

    /** Clear cached outlet / user rows after editing them. */
    public static function forgetBusiness(int $businessId): void
    {
        cache()->delete('omnipos_posbiz_' . $businessId);
    }

    public static function forgetUser(int $userId, int $businessId): void
    {
        cache()->delete("omnipos_posuser_{$userId}_{$businessId}");
    }

    /** Feature switch from the business type template (kitchen, seats, serials, batches, decimalQty …). */
    public function feature(string $key): mixed
    {
        return $this->business()['settings']['features'][$key] ?? null;
    }

    /** @deprecated use term(): one resolution for every page (outlet, type, defaults) */
    public function label(string $key, string $default = ''): string
    {
        return $this->term($key) ?: $default;
    }

    /** ['symbol' => '৳', 'decimals' => 2, 'position' => 'before'] for money() */
    public function currency(): array
    {
        $b = $this->business();

        return ['symbol' => $b['currency_symbol'] ?? '৳', 'decimals' => (int) ($b['currency_decimals'] ?? 2), 'position' => 'before'];
    }

    public function can(string $perm): bool
    {
        $u = $this->user();

        return $u !== null && ($u['locked'] || in_array($perm, $u['perms'], true));
    }

    /** Plan, status and enabled modules of the signed-in account. */
    public function access(): TenantAccess
    {
        return service('moduleAccess')->forTenant($this->tenantId());
    }

    public function moduleOn(string $module): bool
    {
        return $this->access()->has($module);
    }

    /**
     * Outlets the current user can open (outlet switcher).
     *
     * @return list<array<string, mixed>>
     */
    public function outlets(): array
    {
        return db_connect()->table('businesses b')->select('b.id, b.name, b.business_type, bt.name AS type_name, bt.icon AS type_icon, b.area')
            ->join('business_types bt', 'bt.code = b.business_type')
            ->join('user_businesses ub', 'ub.business_id = b.id')
            ->where('ub.user_id', $this->userId())->where('b.tenant_id', $this->tenantId())->where('b.is_active', 1)
            ->orderBy('b.id')->get()->getResultArray();
    }

    /** Set the tenant context for this request (called by the PosAuth filter). */
    public function bindContext(): void
    {
        $u = $this->user();
        service('tenant')->set($this->tenantId(), $this->businessId(), $u ? (int) $u['id'] : null, $u['name'] ?? 'POS');
    }
}
