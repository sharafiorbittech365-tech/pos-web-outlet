<?php

/**
 * Reachable while the account is locked (PosAuth lets "subscription" through) so the owner can pay.
 *
 * @var \CodeIgniter\Router\RouteCollection $routes
 */
$routes->group('subscription', ['namespace' => 'App\Modules\Subscription\Controllers', 'filter' => ['posauth', 'tenantserver', 'can:billing.manage']], static function ($routes): void {
    $routes->get('/', 'SubscriptionController::index');
    $routes->get('pay', 'SubscriptionController::payForm');
    $routes->post('pay', 'SubscriptionController::pay');
    $routes->post('plan', 'SubscriptionController::plan');
    $routes->get('quote', 'SubscriptionController::quote');
    $routes->post('selection', 'SubscriptionController::selection');
    $routes->post('modules/(:segment)/add', 'SubscriptionController::addModule/$1');
    $routes->post('modules/(:segment)/remove', 'SubscriptionController::removeModule/$1');
    $routes->post('cancel', 'SubscriptionController::cancel');
    $routes->post('resume', 'SubscriptionController::resume');
    $routes->post('method', 'SubscriptionController::method');
    $routes->get('invoices/(:num)', 'SubscriptionController::invoice/$1');
});
