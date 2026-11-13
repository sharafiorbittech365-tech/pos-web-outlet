<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 */

use App\Modules\Storefront\Controllers\StorefrontController;

$routes->group('v1', ['filter' => ['apicors', 'apikey', 'ratelimit']], static function ($routes): void {
    $routes->get('storefront', [StorefrontController::class, 'show'], ['filter' => 'scope:products.read']);
    $routes->get('pages/([a-z0-9-]{1,80})', [StorefrontController::class, 'sitePage'], ['filter' => 'scope:products.read']);
});

// website images (logo, favicon, banner): public, immutable, addressed by id + hash — see MediaController
$routes->get('media/(\d+)-([a-f0-9]{16})\.(png|jpg|webp|gif|ico)', '\App\Modules\Storefront\Controllers\MediaController::show/$1/$2/$3');
