<?php

/**
 * @var \CodeIgniter\Router\RouteCollection $routes
 *
 * POS › Website › Multi-outlet website (account owner only — checked in MultisiteAdmin).
 */
$routes->group('website/multisite', ['namespace' => 'App\Modules\Multisite\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:website', 'module:multisite', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'MultisiteController::index');
    $routes->post('settings', 'MultisiteController::save');
    $routes->post('outlets', 'MultisiteController::outlets');
    $routes->post('key', 'MultisiteController::issueKey');
    $routes->post('key/revoke', 'MultisiteController::revokeKey');
});
