<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Health\Controllers\HealthController;

// monitoring: public, no token (says nothing beyond "up" and the database ping)
$routes->get('v1/health', [HealthController::class, 'index']);
