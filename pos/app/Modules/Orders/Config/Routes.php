<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('orders', ['namespace' => 'App\Modules\Orders\Controllers', 'filter' => ['posauth', 'tenantserver', 'can:orders.view']], static function ($routes): void {
    $routes->get('/', 'OrdersController::index');
    $routes->get('(:num)', 'OrdersController::show/$1');
    $routes->get('(:num)/print', 'OrdersController::print/$1');
    $routes->post('(:num)/pay', 'OrdersController::pay/$1');
    $routes->post('(:num)/return', 'OrdersController::returnItems/$1', ['filter' => ['posauth', 'tenantserver', 'can:orders.view', 'can:pos.return']]);
    $routes->post('(:num)/ship', 'OrdersController::ship/$1');
});
