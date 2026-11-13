<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('products', ['namespace' => 'App\Modules\Products\Controllers', 'filter' => ['posauth', 'tenantserver']], static function ($routes): void {
    $view = ['filter' => 'can:inventory.view'];
    $manage = ['filter' => 'can:inventory.view,products.manage'];

    $routes->get('/', 'ProductsController::index', $view);
    $routes->get('new', 'ProductsController::create', $manage);
    $routes->post('/', 'ProductsController::store', $manage);
    $routes->get('export', 'ProductsController::export', $view);
    $routes->get('import', 'ProductsController::importForm', $manage);
    $routes->post('import', 'ProductsController::import', $manage);
    $routes->post('bulk', 'ProductsController::bulk', $manage);
    $routes->get('labels', 'LabelsController::index', $view);
    $routes->post('labels/design', 'LabelsController::saveTemplate', $manage);
    $routes->post('labels/codes', 'LabelsController::createCodes', $manage);

    $routes->get('categories', 'CategoriesController::index', $view);
    $routes->post('categories', 'CategoriesController::store', $manage);
    $routes->post('categories/(:num)', 'CategoriesController::update/$1', $manage);
    $routes->post('categories/(:num)/delete', 'CategoriesController::delete/$1', $manage);

    $routes->get('(:num)/reviews', 'ReviewsController::index/$1', $view);
    $routes->post('(:num)/reviews', 'ReviewsController::store/$1', $manage);
    $routes->post('reviews/(:num)/status', 'ReviewsController::status/$1', $manage);
    $routes->post('reviews/(:num)/verified', 'ReviewsController::verified/$1', $manage);
    $routes->post('reviews/(:num)/delete', 'ReviewsController::delete/$1', $manage);
    $routes->get('(:num)', 'ProductsController::edit/$1', $view);
    $routes->post('(:num)', 'ProductsController::update/$1', $manage);
    $routes->post('(:num)/delete', 'ProductsController::delete/$1', $manage);
});
