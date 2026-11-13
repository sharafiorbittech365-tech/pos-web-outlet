<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('', ['namespace' => 'App\Modules\Auth\Controllers'], static function ($routes): void {
    $routes->get('login', 'AuthController::login');
    $routes->post('login', 'AuthController::attempt', ['filter' => 'shield:human,10/60']);
    $routes->post('logout', 'AuthController::logout');
});
