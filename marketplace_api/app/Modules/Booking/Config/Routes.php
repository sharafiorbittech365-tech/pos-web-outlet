<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
use App\Modules\Booking\Controllers\BookingController;

$routes->group('v1/market', ['filter' => 'marketauth'], static function ($routes): void {
    $routes->post('bookings', [BookingController::class, 'create']);
    $routes->get('bookings/([a-fA-F0-9]{32})', [BookingController::class, 'show']);
});
