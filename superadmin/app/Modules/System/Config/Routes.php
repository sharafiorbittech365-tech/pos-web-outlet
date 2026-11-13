<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('system', ['namespace' => 'App\Modules\System\Controllers', 'filter' => ['adminauth', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'SystemController::index');
    $routes->get('health', 'HealthController::index');
    $routes->post('settings', 'SystemController::save');
    $routes->post('brand', 'SystemController::saveBrand');
    $routes->post('gateway/rotate', 'SystemController::rotateGateway');
    $routes->post('gateway/disable', 'SystemController::disableGateway');
});
