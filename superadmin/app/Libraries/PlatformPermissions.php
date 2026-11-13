<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Platform staff permissions with their labels (the permission matrix in Staff & roles).
 * No permission gives access to tenant workspace data — that is a platform rule, not a role setting.
 */
final class PlatformPermissions
{
    public const GROUPS = [
        'Overview' => ['overview.view' => 'Platform overview & revenue'],
        'Tenants' => [
            'tenants.view' => 'See tenants & usage counts',
            'tenants.create' => 'Create tenants',
            'tenants.manage' => 'Change plan, limits, modules, suspend / reactivate',
            'tenants.note' => 'Add support notes',
            'tenants.delete' => 'Delete tenants & their data',
        ],
        'POS servers' => ['servers.manage' => 'Manage POS servers & move tenants between them'],
        'Plans & billing' => [
            'plans.manage' => 'Create & edit plans, module prices',
            'billing.view' => 'See subscriptions, invoices & payments',
            'billing.manage' => 'Run billing, record payments, void invoices',
        ],
        'Platform' => [
            'announcements.manage' => 'Publish announcements',
            'admins.manage' => 'Platform staff & roles',
            'settings.manage' => 'Platform settings & system',
            'audit.view' => 'Platform audit log',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::GROUPS as $perms) {
            array_push($out, ...array_keys($perms));
        }

        return $out;
    }
}
