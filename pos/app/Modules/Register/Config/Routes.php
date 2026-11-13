<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$base = ['posauth', 'tenantserver', 'module:register', 'can:register.view'];
$routes->group('register', ['namespace' => 'App\Modules\Register\Controllers', 'filter' => $base], static function ($routes) use ($base): void {
    $routes->get('/', 'RegisterController::index');
    $routes->get('(:num)', 'RegisterController::show/$1');
    $routes->post('open', 'RegisterController::open');
    $routes->post('close', 'RegisterController::close', ['filter' => array_merge($base, ['can:register.close'])]);
});
