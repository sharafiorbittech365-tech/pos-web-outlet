<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('expenses', ['namespace' => 'App\Modules\Expenses\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:expenses', 'can:expenses.manage']], static function ($routes): void {
    $routes->get('/', 'ExpensesController::index');
    $routes->post('/', 'ExpensesController::store');
    $routes->post('(:num)/delete', 'ExpensesController::delete/$1');
});
