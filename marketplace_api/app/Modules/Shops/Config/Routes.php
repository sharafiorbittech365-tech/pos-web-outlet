<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Shops\Controllers\ShopsController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->get('shops', [ShopsController::class, 'index']);
    $routes->get('shops/([a-z0-9][a-z0-9-]{0,99})', [ShopsController::class, 'show']);
});
