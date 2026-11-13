<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
// Super Admin › Medicine database: the platform-wide medicine master list (import, browse, enable/disable)
$routes->group('medicines', ['namespace' => 'App\Modules\Medicines\Controllers', 'filter' => ['adminauth', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'MedicinesController::index');
    $routes->get('template.csv', 'MedicinesController::template');
    $routes->post('import', 'MedicinesController::import');
    $routes->post('(:num)/toggle', 'MedicinesController::toggle/$1');
});
