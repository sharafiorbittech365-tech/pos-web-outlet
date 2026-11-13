<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Libraries\PosSession;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;

/**
 * Writes one key of businesses.settings (row locked, other keys untouched — Setup and other
 * modules keep their own keys) and clears the cached outlet row.
 */
final class SettingsStore
{
    /** @return array<string, mixed> the stored settings after the change */
    public static function put(int $tenantId, int $businessId, string $key, array $value): array
    {
        $s = Tx::run(static function (BaseConnection $db) use ($tenantId, $businessId, $key, $value): array {
            $row = $db->query('SELECT settings FROM businesses WHERE id = ? AND tenant_id = ? FOR UPDATE', [$businessId, $tenantId])->getRow('settings');
            $s = json_decode((string) ($row ?? '{}'), true) ?: [];
            $s[$key] = $value;
            $db->table('businesses')->where('id', $businessId)->where('tenant_id', $tenantId)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);

            return $s;
        });
        PosSession::forgetBusiness($businessId);
        service('posSession')->reset();

        return $s;
    }
}
