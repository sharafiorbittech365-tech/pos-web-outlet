<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('plans', ['namespace' => 'App\Modules\Plans\Controllers', 'filter' => ['adminauth', 'can:plans.manage']], static function ($routes): void {
    $routes->get('/', 'PlansController::index');
    $routes->get('new', 'PlansController::new');
    $routes->post('/', 'PlansController::create');
    $routes->post('prices', 'PlansController::prices');
    $routes->get('(:num)/edit', 'PlansController::edit/$1');
    $routes->post('(:num)', 'PlansController::update/$1');
    $routes->post('(:num)/delete', 'PlansController::delete/$1');
});
