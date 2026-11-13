<?php

/**
 * @var CodeIgniter\Router\RouteCollection $routes
 *
 * Website images (logo, favicon, banner) referenced by the multi-outlet website profile:
 * public, immutable, addressed by id + hash — see MediaController.
 */
$routes->get('media/(\d+)-([a-f0-9]{16})\.(png|jpg|webp|gif|ico)', '\App\Modules\Storefront\Controllers\MediaController::show/$1/$2/$3');
