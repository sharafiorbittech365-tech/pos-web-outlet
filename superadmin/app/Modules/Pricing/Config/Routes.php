<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('pricing', ['namespace' => 'App\Modules\Pricing\Controllers', 'filter' => ['adminauth', 'can:plans.manage']], static function ($routes): void {
    $routes->get('/', 'PricingController::index');
    $routes->post('items', 'PricingController::saveItems');
    $routes->post('tiers', 'PricingController::saveTiers');
    $routes->post('packages', 'PricingController::savePackage');
    $routes->post('packages/(:num)', 'PricingController::savePackage/$1');
    $routes->post('packages/(:num)/delete', 'PricingController::deletePackage/$1');
    $routes->get('customer/(:num)', 'PricingController::customer/$1');
    $routes->post('customer/(:num)', 'PricingController::saveCustomer/$1');
});
