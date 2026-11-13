<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->get('audit', '\App\Modules\Audit\Controllers\AuditController::index', ['filter' => ['adminauth', 'can:audit.view']]);
