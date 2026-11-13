<?php

declare(strict_types=1);

namespace App\Modules\Audit\Services;

use CodeIgniter\Database\BaseBuilder;

/**
 * Read side of platform_audit (append-only; written through AuditService::platform()).
 */
final class AuditLog
{
    /** @return list<string> distinct actions for the filter */
    public static function actions(): array
    {
        return array_column(db_connect()->table('platform_audit')->select('action')->distinct()->orderBy('action')->get()->getResultArray(), 'action');
    }

    /** @return array{id: int, name: string}|null */
    public static function tenant(int $id): ?array
    {
        return $id > 0 ? db_connect()->table('tenants')->select('id, name')->where('id', $id)->get()->getRowArray() : null;
    }

    public static function listQuery(string $action, ?int $tenantId): BaseBuilder
    {
        $b = db_connect()->table('platform_audit a')
            ->select('a.id, a.created_at, a.actor_name, a.actor_role, a.action, a.detail, a.tenant_id, a.ip_address, t.name AS tenant_name')
            ->join('tenants t', 't.id = a.tenant_id', 'left');
        if ($action !== '') {
            $b->where('a.action', $action);
        }
        if ($tenantId !== null) {
            $b->where('a.tenant_id', $tenantId);
        }

        return $b;
    }
}
