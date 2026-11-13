<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 *
 * Modular routing: each feature module (app/Modules/<Name>) declares its routes in
 * app/Modules/<Name>/Config/Routes.php. Files are loaded in name order.
 */
$routes->setAutoRoute(false);

$moduleRoutes = glob(APPPATH . 'Modules/*/Config/Routes.php') ?: [];
sort($moduleRoutes);
foreach ($moduleRoutes as $file) {
    require $file;
}
