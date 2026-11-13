<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('announcements', ['namespace' => 'App\Modules\Announcements\Controllers', 'filter' => ['adminauth', 'can:announcements.manage']], static function ($routes): void {
    $routes->get('/', 'AnnouncementsController::index');
    $routes->post('/', 'AnnouncementsController::create');
    $routes->post('(:num)', 'AnnouncementsController::update/$1');
    $routes->post('(:num)/toggle', 'AnnouncementsController::toggle/$1');
    $routes->post('(:num)/delete', 'AnnouncementsController::delete/$1');
});
