<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('staff', ['namespace' => 'App\Modules\Staff\Controllers', 'filter' => ['adminauth', 'can:admins.manage']], static function ($routes): void {
    $routes->get('/', 'StaffController::index');
    $routes->post('/', 'StaffController::create');
    $routes->post('(:num)', 'StaffController::update/$1');
    $routes->post('(:num)/delete', 'StaffController::delete/$1');
    $routes->get('roles', 'StaffController::roles');
    $routes->post('roles', 'StaffController::createRole');
    $routes->post('roles/permissions', 'StaffController::permissions');
    $routes->post('roles/(:num)', 'StaffController::updateRole/$1');
    $routes->post('roles/(:num)/delete', 'StaffController::deleteRole/$1');
});
