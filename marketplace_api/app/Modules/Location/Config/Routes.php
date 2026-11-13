<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Location\Controllers\LocationController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->get('meta', [LocationController::class, 'meta']);
    $routes->post('location', [LocationController::class, 'resolve']);
});
