<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('reports', ['namespace' => 'App\Modules\Reports\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:reports', 'can:reports.view']], static function ($routes): void {
    $routes->get('/', 'ReportsController::index');
    $routes->get('outlets', 'OutletReportController::index');
    $routes->get('credit-ledger', 'LedgerReportController::credit');
    $routes->get('exchange-ledger', 'LedgerReportController::exchange');
});
