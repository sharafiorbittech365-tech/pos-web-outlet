<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->get('floor', 'FloorController::index', ['namespace' => 'App\Modules\Floor\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:floor', 'can:pos.sell']]);

// Tables › Manage (add / edit / remove tables) and the table QR codes (Table QR ordering)
$routes->group('floor', ['namespace' => 'App\Modules\Floor\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:floor', 'can:settings.manage']], static function ($routes): void {
    $routes->get('manage', 'TablesController::index');
    $routes->post('tables', 'TablesController::store');
    $routes->post('tables/(:num)', 'TablesController::update/$1');
    $routes->post('tables/(:num)/delete', 'TablesController::delete/$1');
    $routes->post('tables/(:num)/qr', 'TablesController::newQr/$1', ['filter' => 'module:tableqr']);
    $routes->get('qr', 'TablesController::qr', ['filter' => 'module:tableqr']);
});
