<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('sell', ['namespace' => 'App\Modules\Sales\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:pos', 'can:pos.sell']], static function ($routes): void {
    $routes->get('/', 'SellController::index');
    $routes->get('products', 'SellController::products');
    $routes->get('lookup', 'SellController::lookup');
    $routes->get('serials/(:num)', 'SellController::serials/$1');
    $routes->get('customers', 'SellController::customers');
    $routes->post('customer', 'SellController::addCustomer');
    $routes->post('quote', 'SellController::quote');
    $routes->post('checkout', 'SellController::checkout');
    $routes->post('open', 'SellController::open');
    $routes->post('pay/(:num)', 'SellController::pay/$1');
    $routes->post('hold', 'SellController::hold');
    $routes->get('parked', 'SellController::parked');
    $routes->post('resume/(:num)', 'SellController::resume/$1');
    $routes->post('held/(:num)/delete', 'SellController::deleteHeld/$1');
});
