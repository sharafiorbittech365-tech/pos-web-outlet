<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Buyers\Controllers\BuyersController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->post('buyers/login', [BuyersController::class, 'login']);
    $routes->post('buyers', [BuyersController::class, 'register']);
    $routes->get('me', [BuyersController::class, 'me']);
    $routes->get('me/orders', [BuyersController::class, 'orders']);
    $routes->get('me/orders/(:num)', [BuyersController::class, 'order']);
    $routes->get('me/bookings', [BuyersController::class, 'bookings']);
});
