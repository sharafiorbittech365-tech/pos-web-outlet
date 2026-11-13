<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
// Each route also needs its Setup › Purchasing switch (filter pursw:…), checked after the account's module.
$routes->group('purchases', ['namespace' => 'App\Modules\Purchases\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:purchases', 'can:inventory.manage']], static function ($routes): void {
    $routes->get('/', 'PurchasesController::index', ['filter' => 'pursw:any']);
    $routes->get('new', 'PurchasesController::create', ['filter' => 'pursw:purchase']);
    $routes->post('/', 'PurchasesController::store', ['filter' => 'pursw:purchase']);
    $routes->post('pay', 'PurchasesController::pay', ['filter' => 'pursw:payment']);
    $routes->post('suppliers', 'PurchasesController::storeSupplier', ['filter' => 'pursw:supplier']);
    $routes->get('suppliers/(:num)', 'PurchasesController::supplier/$1', ['filter' => 'pursw:supplier']);
    $routes->post('suppliers/(:num)', 'PurchasesController::updateSupplier/$1', ['filter' => 'pursw:supplier']);
    $routes->post('suppliers/(:num)/delete', 'PurchasesController::deleteSupplier/$1', ['filter' => 'pursw:supplier']);
    $routes->get('(:num)', 'PurchasesController::show/$1', ['filter' => 'pursw:purchase']);
});
