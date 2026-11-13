<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Health\Controllers\HealthController;

$routes->get('v1/health', [HealthController::class, 'index'], ['filter' => 'apicors']);
// CORS preflight for every /v1 route (browsers send it without the API key)
$routes->options('v1/(:any)', [HealthController::class, 'preflight'], ['filter' => 'apicors']);
