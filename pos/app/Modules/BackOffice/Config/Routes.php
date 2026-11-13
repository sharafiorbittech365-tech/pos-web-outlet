<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->get('nav/counts', 'NavController::counts', ['namespace' => 'App\Modules\BackOffice\Controllers', 'filter' => ['posauth', 'tenantserver']]);
