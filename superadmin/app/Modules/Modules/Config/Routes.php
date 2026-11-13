<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('modules', ['namespace' => 'App\Modules\Modules\Controllers', 'filter' => ['adminauth', 'can:plans.manage']], static function ($routes): void {
    $routes->get('/', 'ModulesController::index');
    $routes->post('prices', 'ModulesController::prices');
    $routes->post('recommended', 'ModulesController::recommended');
    $routes->post('features', 'ModulesController::saveFeature');
    $routes->post('features/(:segment)/delete', 'ModulesController::deleteFeature/$1');
    $routes->post('features/(:segment)', 'ModulesController::saveFeature/$1');
    $routes->post('(:segment)', 'ModulesController::update/$1');
});
