<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('tenants', ['namespace' => 'App\Modules\Tenants\Controllers', 'filter' => ['adminauth', 'can:tenants.view']], static function ($routes): void {
    $routes->get('/', 'TenantsController::index');
    $routes->get('new', 'TenantsController::new', ['filter' => 'can:tenants.create']);
    $routes->post('/', 'TenantsController::create', ['filter' => 'can:tenants.create']);
    $routes->get('(:num)', 'TenantsController::show/$1');
    $routes->post('(:num)/subscription', 'TenantsController::subscription/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/extend-trial', 'TenantsController::extendTrial/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/convert', 'TenantsController::convert/$1', ['filter' => 'can:billing.manage']);
    $routes->post('(:num)/cancel', 'TenantsController::cancel/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/limits', 'TenantsController::limits/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/modules', 'TenantsController::modules/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/notes', 'TenantsController::note/$1', ['filter' => 'can:tenants.note']);
    $routes->post('(:num)/server', 'TenantsController::server/$1', ['filter' => 'can:servers.manage']);
    $routes->post('(:num)/suspend', 'TenantsController::suspend/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/reactivate', 'TenantsController::reactivate/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/reset-password', 'TenantsController::resetPassword/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('(:num)/delete', 'TenantsController::delete/$1', ['filter' => 'can:tenants.delete']);
});
