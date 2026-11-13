<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->get('subscriptions', '\App\Modules\Subscriptions\Controllers\SubscriptionsController::index', ['filter' => ['adminauth', 'can:billing.view']]);
