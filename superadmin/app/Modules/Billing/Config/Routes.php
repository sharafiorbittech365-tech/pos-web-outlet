<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('billing', ['namespace' => 'App\Modules\Billing\Controllers', 'filter' => 'adminauth'], static function ($routes): void {
    $routes->get('/', 'BillingController::index', ['filter' => 'can:billing.view']);
    $routes->get('payments', 'BillingController::payments', ['filter' => 'can:billing.view']);
    $routes->get('invoices/(:num)', 'BillingController::show/$1', ['filter' => 'can:billing.view']);
    $routes->post('run', 'BillingController::run', ['filter' => 'can:billing.manage']);
    $routes->post('invoices/(:num)/pay', 'BillingController::pay/$1', ['filter' => 'can:billing.manage']);
    $routes->post('invoices/(:num)/void', 'BillingController::void/$1', ['filter' => 'can:billing.manage']);
});
