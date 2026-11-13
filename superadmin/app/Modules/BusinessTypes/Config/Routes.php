<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('business-types', ['namespace' => 'App\Modules\BusinessTypes\Controllers', 'filter' => ['adminauth', 'can:plans.manage']], static function ($routes): void {
    $routes->get('/', 'BusinessTypesController::index');
    $routes->get('(:segment)', 'BusinessTypesController::edit/$1');
    $routes->post('(:segment)/reset', 'BusinessTypesController::reset/$1');
    $routes->post('(:segment)', 'BusinessTypesController::update/$1');
});
