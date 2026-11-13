<?php

/**
 * Clinic: doctors and their free slots for the website (POST /v1/bookings with doctor_id books one).
 *
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Clinic\Controllers\DoctorsController;

$routes->group('v1', ['filter' => ['apicors', 'apikey', 'ratelimit']], static function ($routes): void {
    $routes->get('doctors', [DoctorsController::class, 'index'], ['filter' => 'scope:bookings.create']);
    $routes->get('doctors/(:num)', [DoctorsController::class, 'show'], ['filter' => 'scope:bookings.create']);
    $routes->get('doctors/(:num)/slots', [DoctorsController::class, 'slots'], ['filter' => 'scope:bookings.create']);
    $routes->get('doctors/(:num)/photo', [DoctorsController::class, 'photo'], ['filter' => 'scope:bookings.create']);
});
