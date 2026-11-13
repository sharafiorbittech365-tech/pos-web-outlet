<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 *
 * Multi-outlet website (apps/multistore): server-side credential only (mk_live_ key, or the platform
 * gateway token + X-Multisite-Host) — see App\Filters\MultisiteAuth.
 */

use App\Modules\Multisite\Controllers\MultisiteController;

$routes->group('v1/multisite', ['filter' => ['multisite', 'ratelimit']], static function ($routes): void {
    $routes->get('/', [MultisiteController::class, 'profile'], ['filter' => 'scope:multisite.read']);
    $routes->get('outlets', [MultisiteController::class, 'outlets'], ['filter' => 'scope:multisite.read']);
    $routes->get('outlets/(:num)', [MultisiteController::class, 'outlet'], ['filter' => 'scope:multisite.read']);
    $routes->get('categories', [MultisiteController::class, 'categories'], ['filter' => 'scope:multisite.read']);
    $routes->get('products', [MultisiteController::class, 'products'], ['filter' => 'scope:multisite.read']);
    $routes->get('products/(:num)', [MultisiteController::class, 'product'], ['filter' => 'scope:multisite.read']);
    $routes->post('orders', [MultisiteController::class, 'createOrder'], ['filter' => 'scope:multisite.orders']);
    $routes->get('orders/(:segment)', [MultisiteController::class, 'order'], ['filter' => 'scope:multisite.orders']);
});
