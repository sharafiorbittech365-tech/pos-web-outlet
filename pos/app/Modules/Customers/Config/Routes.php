<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$base = ['posauth', 'tenantserver', 'module:customers', 'can:customers.view'];
$routes->group('customers', ['namespace' => 'App\Modules\Customers\Controllers', 'filter' => $base], static function ($routes) use ($base): void {
    $routes->get('/', 'CustomersController::index');
    $routes->get('(:num)', 'CustomersController::show/$1');
    $routes->get('(:num)/statement', 'CustomersController::statement/$1');
    // a route's own 'filter' replaces the group's, so the full list is repeated
    $routes->post('/', 'CustomersController::create', ['filter' => array_merge($base, ['can:customers.manage'])]);
    $routes->post('(:num)', 'CustomersController::update/$1', ['filter' => array_merge($base, ['can:customers.manage'])]);
    $routes->post('(:num)/opening', 'CustomersController::opening/$1', ['filter' => array_merge($base, ['can:customers.manage'])]);
    $routes->post('(:num)/collect', 'CustomersController::collect/$1', ['filter' => array_merge($base, ['can:customers.receive'])]);
});
