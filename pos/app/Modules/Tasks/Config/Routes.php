<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('tasks', ['namespace' => 'App\Modules\Tasks\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:tasks']], static function ($routes): void {
    $routes->get('/', 'TasksController::index');
    $routes->post('/', 'TasksController::store', ['filter' => 'can:tasks.manage']);
    $routes->post('auto', 'TasksController::auto', ['filter' => 'can:tasks.manage']);
    $routes->post('(:num)', 'TasksController::update/$1', ['filter' => 'can:tasks.manage']);
    $routes->post('(:num)/toggle', 'TasksController::toggle/$1');
    $routes->post('(:num)/delete', 'TasksController::delete/$1', ['filter' => 'can:tasks.manage']);
});
