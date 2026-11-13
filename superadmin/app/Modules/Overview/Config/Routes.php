<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->addRedirect('/', 'overview');
$routes->get('overview', '\App\Modules\Overview\Controllers\OverviewController::index', ['filter' => ['adminauth', 'can:overview.view']]);
