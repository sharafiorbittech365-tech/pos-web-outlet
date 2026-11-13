<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('kitchen', ['namespace' => 'App\Modules\Kitchen\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:kitchen', 'can:kitchen.view']], static function ($routes): void {
    $routes->get('/', 'KitchenController::index');
    $routes->get('feed', 'KitchenController::feed');
    $routes->post('items/(:num)', 'KitchenController::toggle/$1');
    $routes->post('(:num)/status', 'KitchenController::status/$1');
});
