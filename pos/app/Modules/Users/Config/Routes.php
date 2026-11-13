<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('users', ['namespace' => 'App\Modules\Users\Controllers', 'filter' => ['posauth', 'tenantserver', 'can:users.manage']], static function ($routes): void {
    $routes->get('/', 'UsersController::index');
    $routes->get('new', 'UsersController::create');
    $routes->post('/', 'UsersController::store');
    $routes->get('roles', 'UsersController::roles');
    $routes->post('roles', 'UsersController::storeRole');
    $routes->post('roles/(:num)', 'UsersController::updateRole/$1');
    $routes->post('roles/(:num)/delete', 'UsersController::deleteRole/$1');
    $routes->get('activity', 'UsersController::activity');
    $routes->get('(:num)', 'UsersController::edit/$1');
    $routes->post('(:num)', 'UsersController::update/$1');
});
