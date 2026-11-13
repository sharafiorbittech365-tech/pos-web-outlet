<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('servers', ['namespace' => 'App\Modules\Servers\Controllers', 'filter' => ['adminauth', 'can:servers.manage']], static function ($routes): void {
    $routes->get('/', 'ServersController::index');
    $routes->get('new', 'ServersController::new');
    $routes->post('/', 'ServersController::create');
    $routes->get('(:num)', 'ServersController::show/$1');
    $routes->post('(:num)', 'ServersController::update/$1');
    $routes->post('(:num)/drain', 'ServersController::drain/$1');
    $routes->post('(:num)/delete', 'ServersController::delete/$1');
});
