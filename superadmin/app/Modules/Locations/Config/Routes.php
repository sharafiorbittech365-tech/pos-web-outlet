<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('locations', ['namespace' => 'App\Modules\Locations\Controllers', 'filter' => ['adminauth', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'LocationsController::index');
    $routes->get('template.csv', 'LocationsController::template');
    $routes->post('countries', 'LocationsController::createCountry');
    $routes->post('import', 'LocationsController::import');
    $routes->get('cities/(:num)', 'LocationsController::city/$1');
    $routes->post('cities/(:num)/toggle', 'LocationsController::toggleCity/$1');
    $routes->post('areas/(:num)/toggle', 'LocationsController::toggleArea/$1');
    $routes->get('([A-Za-z]{2})', 'LocationsController::country/$1');
    $routes->post('([A-Za-z]{2})', 'LocationsController::updateCountry/$1');
    $routes->post('([A-Za-z]{2})/toggle', 'LocationsController::toggleCountry/$1');
});
