<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
// Pharmacy module: expiry & low-stock alerts, expired-batch policy, shared medicine database
$routes->group('pharmacy', ['namespace' => 'App\Modules\Pharmacy\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:pharmacy']], static function ($routes): void {
    $view = ['filter' => 'can:inventory.view'];

    $routes->get('/', 'PharmacyController::index', $view);
    $routes->get('medicines', 'PharmacyController::medicines', $view);
    $routes->get('medicines/search', 'PharmacyController::search', $view);
    $routes->post('batches/(:num)/write-off', 'PharmacyController::writeOff/$1', ['filter' => 'can:inventory.view,inventory.manage']);
    $routes->post('settings', 'PharmacyController::saveSettings', ['filter' => 'can:settings.manage']);
});
