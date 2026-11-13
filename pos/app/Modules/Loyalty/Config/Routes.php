<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('loyalty', ['namespace' => 'App\Modules\Loyalty\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:loyalty', 'can:customers.view']], static function ($routes): void {
    $routes->get('/', 'LoyaltyController::index');
    $routes->post('rules', 'LoyaltyController::rules', ['filter' => 'can:customers.manage']);
    $routes->post('adjust', 'LoyaltyController::adjust', ['filter' => 'can:customers.manage']);
});
