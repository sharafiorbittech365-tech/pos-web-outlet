<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('websites', ['namespace' => 'App\Modules\Websites\Controllers', 'filter' => ['adminauth', 'can:tenants.view']], static function ($routes): void {
    $routes->get('/', 'WebsitesController::index');
    $routes->post('domains/(:num)/recheck', 'WebsitesController::recheck/$1', ['filter' => 'can:tenants.manage']);
    $routes->post('domains/(:num)/block', 'WebsitesController::block/$1', ['filter' => 'can:tenants.manage']);
});
