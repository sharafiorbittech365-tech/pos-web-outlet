<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('inventory', ['namespace' => 'App\Modules\Inventory\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:inventory']], static function ($routes): void {
    $view = ['filter' => 'can:inventory.view'];
    $manage = ['filter' => 'can:inventory.view,inventory.manage'];

    $routes->get('/', 'InventoryController::index', $view);
    $routes->get('movements', 'InventoryController::movements', $view);
    $routes->get('expiry', 'InventoryController::expiry', $view);
    $routes->get('serials', 'InventoryController::serials', $view);
    $routes->get('(:num)', 'InventoryController::show/$1', $view);
    $routes->post('(:num)/adjust', 'InventoryController::adjust/$1', $manage);
    $routes->post('(:num)/serials', 'InventoryController::addSerials/$1', $manage);
    $routes->post('(:num)/serials/(:num)/remove', 'InventoryController::removeSerial/$1/$2', $manage);
    $routes->post('(:num)/batches', 'InventoryController::receiveBatch/$1', $manage);
    $routes->post('(:num)/batches/(:num)', 'InventoryController::adjustBatch/$1/$2', $manage);
});
