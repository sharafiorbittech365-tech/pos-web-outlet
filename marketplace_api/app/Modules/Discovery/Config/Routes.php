<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Discovery\Controllers\DiscoveryController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    // q, kind (product|service), category, radius (km, 0 = any), sort (distance|price|-price), stock=1, page + lat, lng, area, city, src
    $routes->get('listings', [DiscoveryController::class, 'index']);
});
