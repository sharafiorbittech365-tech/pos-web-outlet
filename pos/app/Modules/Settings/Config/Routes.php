<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('settings', ['namespace' => 'App\Modules\Settings\Controllers', 'filter' => ['posauth', 'tenantserver', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'SettingsController::index');
    $routes->post('/', 'SettingsController::save');
    $routes->post('smtp-test', 'SettingsController::smtpTest');
    $routes->get('outlets', 'SettingsController::outlets');
    $routes->post('outlets', 'SettingsController::addOutlet');
    $routes->post('outlets/(:num)', 'SettingsController::updateOutlet/$1');
    $routes->post('outlets/(:num)/close', 'SettingsController::closeOutlet/$1');
    $routes->post('outlets/(:num)/open', 'SettingsController::openOutlet/$1');
    $routes->get('outlets/(:num)/import', 'SettingsController::importProducts/$1');
    $routes->post('outlets/(:num)/import', 'SettingsController::runImport/$1');
});
