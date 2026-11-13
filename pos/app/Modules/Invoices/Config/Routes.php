<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */

// Invoices (module "invoicing", every business type) and the NBR Mushak-6.3 VAT invoice.
// Every route repeats the whole filter list (sign-in, POS server, module, permission).
$invBase = ['posauth', 'tenantserver', 'module:invoicing'];
$invF = static fn (string ...$perms): array => ['filter' => array_merge($invBase, array_map(static fn (string $p): string => 'can:' . $p, $perms))];

$routes->group('invoices', ['namespace' => 'App\Modules\Invoices\Controllers'], static function ($routes) use ($invF): void {
    $routes->get('/', 'InvoicesController::index', $invF('invoices.view'));
    $routes->get('new', 'InvoicesController::create', $invF('invoices.view', 'invoices.manage'));
    $routes->post('/', 'InvoicesController::store', $invF('invoices.view', 'invoices.manage'));
    $routes->get('quote', 'InvoicesController::quote', $invF('invoices.view', 'invoices.manage'));
    $routes->get('products', 'InvoicesController::products', $invF('invoices.view', 'invoices.manage'));
    $routes->get('catalog', 'InvoicesController::catalog', $invF('invoices.view', 'invoices.manage'));
    $routes->get('customers', 'InvoicesController::customers', $invF('invoices.view', 'invoices.manage'));
    $routes->get('adjust', 'InvoicesController::adjust', $invF('invoices.view', 'invoices.manage', 'invoices.edit_price'));
    $routes->get('image/(:alpha)', 'InvoiceSettingsController::image/$1', ['filter' => ['posauth', 'tenantserver']]);
    $routes->get('settings', 'InvoiceSettingsController::index', $invF('invoices.settings'));
    $routes->post('settings', 'InvoiceSettingsController::save', $invF('invoices.settings'));
    $routes->post('settings/image/(:alpha)', 'InvoiceSettingsController::upload/$1', $invF('invoices.settings'));
    $routes->post('settings/image/(:alpha)/remove', 'InvoiceSettingsController::removeImage/$1', $invF('invoices.settings'));
    $routes->get('(:num)', 'InvoicesController::show/$1', $invF('invoices.view'));
    $routes->get('(:num)/edit', 'InvoicesController::edit/$1', $invF('invoices.view', 'invoices.manage'));
    $routes->get('(:num)/print', 'InvoicesController::print/$1', $invF('invoices.view'));
    $routes->get('(:num)/pdf', 'InvoicesController::pdf/$1', $invF('invoices.view'));
    $routes->post('(:num)/email', 'InvoicesController::email/$1', $invF('invoices.view'));
    $routes->post('(:num)', 'InvoicesController::update/$1', $invF('invoices.view', 'invoices.manage'));
    $routes->post('(:num)/issue', 'InvoicesController::issue/$1', $invF('invoices.view', 'invoices.manage'));
    $routes->post('(:num)/pay', 'InvoicesController::pay/$1', $invF('invoices.view', 'invoices.manage'));
    $routes->post('(:num)/void', 'InvoicesController::void/$1', $invF('invoices.view', 'invoices.void'));
});

// Mushak-6.3 is a module feature (invoicing.mushak, included by default): Super Admin / the owner can switch it off
$mushF = static fn (string ...$perms): array => ['filter' => array_merge($invF(...$perms)['filter'], ['feature:invoicing.mushak'])];

$routes->group('mushak', ['namespace' => 'App\Modules\Invoices\Controllers'], static function ($routes) use ($mushF): void {
    $routes->get('/', 'MushakController::index', $mushF('mushak.issue'));
    $routes->get('settings', 'MushakController::settings', $mushF('mushak.settings'));
    $routes->post('settings', 'MushakController::saveSettings', $mushF('mushak.settings'));
    $routes->get('order/(:num)', 'MushakController::order/$1', $mushF('mushak.issue'));
    $routes->post('order/(:num)', 'MushakController::issue/$1', $mushF('mushak.issue'));
    // printing an issued Mushak-6.3: invoice or order viewers (checked in the controller)
    $routes->get('(:num)/print', 'MushakController::print/$1', $mushF());
});
