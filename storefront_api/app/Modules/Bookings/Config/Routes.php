<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Bookings\Controllers\BookingsController;

$routes->group('v1', ['filter' => ['apicors', 'apikey', 'ratelimit']], static function ($routes): void {
    $routes->get('bookings/slots', [BookingsController::class, 'slots'], ['filter' => 'scope:bookings.create']);
    $routes->post('bookings', [BookingsController::class, 'create'], ['filter' => 'scope:bookings.create']);
});
