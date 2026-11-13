<?php

declare(strict_types=1);

namespace App\Modules\Pharmacy\Services;

use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Pharmacy settings of an outlet, stored in businesses.settings.pharmacy:
 *
 *   nearDays     batches expiring within this many days are "near expiry" (alerts, till badge)   default 30
 *   expiredSale  block  expired batches are never sold (default)
 *                warn   the till shows the expiry warning, and a sale may take an expired batch
 *                       once every unexpired batch is used up (logged "expired" on the movement)
 *
 * Online orders (storefront, marketplace, API) never sell expired batches, whatever the setting.
 */
final class PharmacySettings
{
    public const DEFAULT_NEAR_DAYS = 30;

    public const EXPIRED = ['block' => 'Block — expired batches are never sold', 'warn' => 'Warn — the till warns; expired stock can be sold when nothing else is left'];

    /** @return array{nearDays: int, expiredSale: string} */
    public static function for(array $business): array
    {
        $s = (array) ($business['settings']['pharmacy'] ?? []);
        $days = (int) ($s['nearDays'] ?? self::DEFAULT_NEAR_DAYS);
        $exp = (string) ($s['expiredSale'] ?? 'block');

        return ['nearDays' => $days >= 1 && $days <= 365 ? $days : self::DEFAULT_NEAR_DAYS, 'expiredSale' => isset(self::EXPIRED[$exp]) ? $exp : 'block'];
    }

    /** @return array{nearDays: int, expiredSale: string} */
    public static function current(): array
    {
        return self::for(service('posSession')->business());
    }

    /**
     * Validate and store the settings of the current outlet.
     *
     * @param array<string, mixed> $in
     * @return array{nearDays: int, expiredSale: string}
     */
    public static function save(array $in): array
    {
        $days = trim((string) ($in['near_days'] ?? ''));
        $exp = (string) ($in['expired_sale'] ?? '');
        $errors = [];
        if (! ctype_digit($days) || (int) $days < 1 || (int) $days > 365) {
            $errors['near_days'] = 'Enter 1 to 365 days.';
        }
        if (! isset(self::EXPIRED[$exp])) {
            $errors['expired_sale'] = 'Choose block or warn.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the highlighted fields.', $errors);
        }
        $new = ['nearDays' => (int) $days, 'expiredSale' => $exp];
        $s = service('posSession');
        $db = db_connect();
        $row = $db->table('businesses')->select('settings')->where('id', $s->businessId())->where('tenant_id', $s->tenantId())->get()->getRowArray();
        $settings = json_decode((string) ($row['settings'] ?? '{}'), true) ?: [];
        $settings['pharmacy'] = $new;
        $db->table('businesses')->where('id', $s->businessId())->where('tenant_id', $s->tenantId())
            ->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
        \App\Libraries\PosSession::forgetBusiness($s->businessId());
        $s->reset();
        service('audit')->activity('Pharmacy settings saved', 'Near expiry ' . $new['nearDays'] . ' days · expired batches: ' . $new['expiredSale']);

        return $new;
    }

    /**
     * Apply the expired-batch policy to this request's stock service (the POS till only).
     */
    public static function applyToTill(array $business): void
    {
        if (service('posSession')->moduleOn('pharmacy') && self::for($business)['expiredSale'] === 'warn') {
            service('stock')->allowExpiredBatches(true);
        }
    }
}
