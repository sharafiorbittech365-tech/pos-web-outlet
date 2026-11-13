<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'App\Modules\Auth\Controllers'], static function ($routes): void {
    $routes->get('/', 'AuthController::home');
    $routes->get('login', 'AuthController::login');
    $routes->post('login', 'AuthController::attempt', ['filter' => 'shield:human,12/60']);
    $routes->post('logout', 'AuthController::logout');
    $routes->get('media/(:num)-(:segment)', 'MediaController::show/$1/$2');
    $routes->get('signup', 'SignupController::form');
    $routes->post('signup', 'SignupController::create', ['filter' => 'shield:human,6/300']);
});

// PIN lock / switch user: the device is signed in, nobody has to be at the till
$routes->group('', ['namespace' => 'App\Modules\Auth\Controllers', 'filter' => ['posdevice', 'tenantserver']], static function ($routes): void {
    $routes->get('switch-user', 'AuthController::switchUser');
    $routes->post('switch-user', 'AuthController::pin', ['filter' => ['posdevice', 'tenantserver', 'shield:12/60']]);
    $routes->post('lock', 'AuthController::lock');
});

$routes->group('outlets', ['namespace' => 'App\Modules\Auth\Controllers', 'filter' => ['posauth', 'tenantserver']], static function ($routes): void {
    $routes->get('/', 'AuthController::outlets');
    $routes->post('switch', 'AuthController::switchOutlet');
});

$routes->group('welcome', ['namespace' => 'App\Modules\Auth\Controllers', 'filter' => ['posauth', 'tenantserver', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'AuthController::welcome');
    $routes->post('/', 'AuthController::setup');
});
