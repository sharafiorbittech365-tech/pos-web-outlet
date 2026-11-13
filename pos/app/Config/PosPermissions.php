<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Role permissions in the POS (same keys as the design build). Owner roles (is_locked) have all.
 */
class PosPermissions extends BaseConfig
{
    /** @var array<string, array<string, string>> group => [key => label] */
    public array $groups = [
        'Sales' => [
            'pos.sell' => 'Sell at POS', 'pos.discount' => 'Give discounts / change tax', 'pos.price' => 'Change item price',
            'pos.credit' => 'Credit sale / leave a due', 'pos.quotation' => 'Drafts & quotations', 'pos.return' => 'Sell return & refund',
            'pos.void' => 'Void / delete orders', 'pos.backdate' => 'Back-date a sale',
        ],
        'Orders & team' => ['orders.view' => 'View orders', 'orders.all' => "See everyone's orders", 'kitchen.view' => 'Kitchen / production board', 'tasks.manage' => 'Assign & manage tasks'],
        'Invoices' => [
            'invoices.view' => 'View invoices', 'invoices.manage' => 'Create, edit & issue invoices, take payments', 'invoices.edit_price' => 'Edit invoice prices, discounts, VAT & free lines',
            'invoices.void' => 'Void invoices', 'invoices.settings' => 'Invoice page & numbering', 'mushak.issue' => 'Issue Mushak-6.3 VAT invoices', 'mushak.settings' => 'Mushak-6.3 / BIN settings',
        ],
        'Customers' => ['customers.view' => 'View customers & dues', 'customers.manage' => 'Add / edit customers & credit limits', 'customers.receive' => 'Receive due payments'],
        'Stock' => ['inventory.view' => 'View inventory & products', 'inventory.manage' => 'Adjust stock', 'products.manage' => 'Add, edit & delete products'],
        'Money' => ['reports.view' => 'Reports, profit & business KPIs', 'expenses.manage' => 'Record expenses', 'register.view' => 'Register details', 'register.close' => 'Close register'],
        'Clinic' => ['clinic.appointments' => 'Appointments & patient registration', 'clinic.patients.view' => 'View medical records (visits & prescriptions)',
            'clinic.prescribe' => 'Write & finalize prescriptions', 'clinic.manage' => 'Doctors, schedules, tests & prescription templates'],
        'Admin' => ['users.manage' => 'Users, roles & activity log', 'settings.manage' => 'Business setup & templates', 'billing.manage' => 'Subscription, plan & billing'],
    ];

    /** @return list<string> */
    public function all(): array
    {
        return array_merge(...array_map('array_keys', array_values($this->groups)));
    }
}
