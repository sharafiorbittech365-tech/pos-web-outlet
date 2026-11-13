<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('bookings', ['namespace' => 'App\Modules\Bookings\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:bookings', 'can:orders.view']], static function ($routes): void {
    $routes->get('/', 'BookingsController::index');
    $routes->get('slots', 'BookingsController::slots');
    $routes->post('/', 'BookingsController::store', ['filter' => 'can:pos.sell']);
    $routes->post('(:num)/status', 'BookingsController::status/$1', ['filter' => 'can:pos.sell']);
});
