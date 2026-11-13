<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * POS sidebar (same order and groups as the design build). An item shows when the user has the
 * permission (null = everyone), the module is enabled (null = always) and the feature condition
 * holds (null = always; otherwise a key in business settings.features that must be truthy).
 * Labels may hold business words: "{customers}", "{catalog}" … (BusinessTypeSetup::LABELS, PosSession::term()).
 */
class PosNav extends BaseConfig
{
    /** @var list<array{path: string, label: string, icon: string, group: string, perm: ?string, module: ?string, feature: ?string}> */
    public array $items = [
        ['path' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'group' => 'Home', 'perm' => null, 'module' => null, 'feature' => null],
        ['path' => 'sell', 'label' => 'POS', 'icon' => 'pos', 'group' => 'Sales', 'perm' => 'pos.sell', 'module' => 'pos', 'feature' => null],
        ['path' => 'orders', 'label' => '{orders}', 'icon' => 'orders', 'group' => 'Sales', 'perm' => 'orders.view', 'module' => null, 'feature' => null],
        ['path' => 'invoices', 'label' => '{invoice}s', 'icon' => 'file-text', 'group' => 'Sales', 'perm' => 'invoices.view', 'module' => 'invoicing', 'feature' => null],
        ['path' => 'bookings', 'label' => 'Bookings', 'icon' => 'calendar', 'group' => 'Sales', 'perm' => 'orders.view', 'module' => 'bookings', 'feature' => 'bookingKind'],
        ['path' => 'kitchen', 'label' => '{kitchen}', 'icon' => 'kitchen', 'group' => 'Sales', 'perm' => 'kitchen.view', 'module' => 'kitchen', 'feature' => 'kitchen'],
        ['path' => 'floor', 'label' => '{floor}', 'icon' => 'floor', 'group' => 'Sales', 'perm' => 'pos.sell', 'module' => 'floor', 'feature' => 'seats'],
        ['path' => 'clinic/appointments', 'label' => 'Appointments', 'icon' => 'stethoscope', 'group' => 'Clinic', 'perm' => 'clinic.appointments', 'module' => 'clinic', 'feature' => 'clinic'],
        // perm "a|b": any of the listed permissions
        ['path' => 'clinic/prescriptions', 'label' => 'Prescriptions', 'icon' => 'rx', 'group' => 'Clinic', 'perm' => 'clinic.prescribe|clinic.patients.view', 'module' => 'clinic', 'feature' => 'clinic'],
        ['path' => 'clinic/patients', 'label' => 'Patients', 'icon' => 'users', 'group' => 'Clinic', 'perm' => 'clinic.appointments', 'module' => 'clinic', 'feature' => 'clinic'],
        ['path' => 'clinic/doctors', 'label' => 'Doctors', 'icon' => 'id-card', 'group' => 'Clinic', 'perm' => 'clinic.manage', 'module' => 'clinic', 'feature' => 'clinic'],
        ['path' => 'customers', 'label' => '{customers}', 'icon' => 'users', 'group' => 'Business', 'perm' => 'customers.view', 'module' => 'customers', 'feature' => null],
        ['path' => 'products', 'label' => '{catalog}', 'icon' => 'tag', 'group' => 'Business', 'perm' => 'inventory.view', 'module' => null, 'feature' => null],
        ['path' => 'inventory', 'label' => 'Inventory', 'icon' => 'box', 'group' => 'Business', 'perm' => 'inventory.view', 'module' => 'inventory', 'feature' => 'stockEnabled'],
        ['path' => 'pharmacy', 'label' => 'Pharmacy', 'icon' => 'pill', 'group' => 'Business', 'perm' => 'inventory.view', 'module' => 'pharmacy', 'feature' => null],
        ['path' => 'purchases', 'label' => 'Purchases', 'icon' => 'truck', 'group' => 'Business', 'perm' => 'inventory.manage', 'module' => 'purchases', 'feature' => 'purchasing'],
        ['path' => 'reports', 'label' => 'Reports', 'icon' => 'chart', 'group' => 'Business', 'perm' => 'reports.view', 'module' => 'reports', 'feature' => null],
        ['path' => 'expenses', 'label' => 'Expenses', 'icon' => 'wallet', 'group' => 'Business', 'perm' => 'expenses.manage', 'module' => 'expenses', 'feature' => null],
        ['path' => 'register', 'label' => 'Register', 'icon' => 'register', 'group' => 'Business', 'perm' => 'register.view', 'module' => 'register', 'feature' => null],
        ['path' => 'loyalty', 'label' => 'Loyalty', 'icon' => 'star', 'group' => 'Business', 'perm' => 'customers.view', 'module' => 'loyalty', 'feature' => null],
        ['path' => 'website', 'label' => 'Website', 'icon' => 'globe', 'group' => 'Online', 'perm' => 'settings.manage', 'module' => 'website', 'feature' => null],
        ['path' => 'tasks', 'label' => 'Tasks', 'icon' => 'checks', 'group' => 'Team', 'perm' => null, 'module' => 'tasks', 'feature' => null],
        ['path' => 'users', 'label' => 'Users & roles', 'icon' => 'shield', 'group' => 'Team', 'perm' => 'users.manage', 'module' => null, 'feature' => null],
        ['path' => 'settings', 'label' => 'Setup', 'icon' => 'setup', 'group' => 'Settings', 'perm' => 'settings.manage', 'module' => null, 'feature' => null],
        ['path' => 'subscription', 'label' => 'Subscription', 'icon' => 'star', 'group' => 'Settings', 'perm' => 'billing.manage', 'module' => null, 'feature' => null],
    ];
}
