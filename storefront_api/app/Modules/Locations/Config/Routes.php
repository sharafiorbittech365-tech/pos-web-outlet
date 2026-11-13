<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Locations\Controllers\LocationsController;

// public reference data: no API key, rate-limited per IP; cached (ETag + max-age)
$routes->group('v1/locations', ['filter' => ['apicors', 'ratelimit']], static function ($routes): void {
    $routes->get('countries', [LocationsController::class, 'countries']);
    $routes->get('cities', [LocationsController::class, 'cities']);
    $routes->get('areas', [LocationsController::class, 'areas']);
});
