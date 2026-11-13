<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

/**
 * Audit trail for settings that must be traceable (invoice page & numbering, Mushak-6.3 / BIN):
 * who changed what and when, with the values before and after (setting_changes) and a line in
 * the activity log.
 */
final class SettingChanges
{
    public const AREAS = ['invoice_page' => 'Invoice page', 'mushak' => 'Mushak-6.3'];

    public static function record(string $area, array $before, array $after): void
    {
        if ($before == $after) {
            return;
        }
        $s = service('posSession');
        $u = $s->user();
        db_connect()->table('setting_changes')->insert([
            'tenant_id' => $s->tenantId(), 'business_id' => $s->businessId(), 'area' => $area,
            'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE), 'after_data' => json_encode($after, JSON_UNESCAPED_UNICODE),
            'user_id' => $s->userId() ?: null, 'user_name' => mb_substr((string) ($u['name'] ?? ''), 0, 100) ?: null,
            'ip_address' => service('request')->getIPAddress(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $keys = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $k) {
            if (($before[$k] ?? null) != ($after[$k] ?? null)) {
                $keys[] = $k;
            }
        }
        service('audit')->activity((self::AREAS[$area] ?? $area) . ' settings changed', mb_substr(implode(', ', $keys), 0, 400));
    }

    /** @return list<array<string, mixed>> latest changes of one area (newest first) */
    public static function latest(string $area, int $limit = 20): array
    {
        $rows = db_connect()->table('setting_changes')->select('id, before_data, after_data, user_name, created_at')
            ->where('business_id', service('posSession')->businessId())->where('area', $area)->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
        foreach ($rows as &$r) {
            $b = json_decode((string) $r['before_data'], true) ?: [];
            $a = json_decode((string) $r['after_data'], true) ?: [];
            $r['changes'] = [];
            foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $k) {
                if (($b[$k] ?? null) != ($a[$k] ?? null)) {
                    $r['changes'][] = [$k, $b[$k] ?? null, $a[$k] ?? null];
                }
            }
        }

        return $rows;
    }
}
