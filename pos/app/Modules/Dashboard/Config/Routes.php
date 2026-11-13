<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->get('dashboard', 'DashboardController::index', ['namespace' => 'App\Modules\Dashboard\Controllers', 'filter' => ['posauth', 'tenantserver']]);
