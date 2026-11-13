<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Listing\Controllers\ListingController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->get('listings/(:num)/(:num)', [ListingController::class, 'show']);
});
