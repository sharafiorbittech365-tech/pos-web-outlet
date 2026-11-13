<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Catalog\Controllers\CatalogController;

$routes->group('v1', ['filter' => ['apicors', 'apikey', 'ratelimit']], static function ($routes): void {
    $routes->get('categories', [CatalogController::class, 'categories'], ['filter' => 'scope:products.read']);
    $routes->get('products', [CatalogController::class, 'products'], ['filter' => 'scope:products.read']);
    $routes->get('products/(:num)', [CatalogController::class, 'product'], ['filter' => 'scope:products.read']);
    $routes->get('products/(:num)/reviews', [CatalogController::class, 'reviews'], ['filter' => 'scope:products.read']);
    $routes->post('products/(:num)/reviews', [CatalogController::class, 'addReview'], ['filter' => 'scope:orders.create']);
    $routes->get('inventory', [CatalogController::class, 'inventory'], ['filter' => ['scope:inventory.read', 'apimodule:inventory']]);
});
