<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Checkout\Controllers\CartController;
use App\Modules\Checkout\Controllers\CheckoutController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->post('cart/check', [CartController::class, 'checkItem']);
    $routes->post('cart/quote', [CartController::class, 'quote']);
    $routes->post('checkout', [CheckoutController::class, 'place']);
    $routes->get('checkout/([a-fA-F0-9]{32})', [CheckoutController::class, 'show']);
});
