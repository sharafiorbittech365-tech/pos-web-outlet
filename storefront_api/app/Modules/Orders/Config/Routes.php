<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Orders\Controllers\OrdersController;

$routes->group('v1', ['filter' => ['apicors', 'apikey', 'ratelimit']], static function ($routes): void {
    $routes->post('orders', [OrdersController::class, 'create'], ['filter' => 'scope:orders.create']);
    $routes->get('orders', [OrdersController::class, 'index'], ['filter' => 'scope:orders.read']);
    $routes->get('tables/(:segment)', [OrdersController::class, 'table'], ['filter' => 'scope:orders.create']);
    // numeric id -> orders:read, reference -> orders:create (checked in the controller)
    $routes->get('orders/(:segment)', [OrdersController::class, 'show']);
});
