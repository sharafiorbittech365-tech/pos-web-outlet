<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */

// Table QR ordering on the POS address — public, no sign-in. The QR on a table opens
// /order/table-5/<secret code>; "table-5" is only for people reading the link, the code decides.
// Every route is rate limited; sending the order also needs the human check (OmniPOS\Ui\Shield).
$routes->group('order', ['namespace' => 'App\Modules\TableOrder\Controllers'], static function ($routes): void {
    $routes->get('([a-z0-9-]{1,40})/([a-f0-9]{24,64})', 'TableOrderController::index/$1/$2', ['filter' => 'shield:40/60,get']);
    $routes->get('([a-z0-9-]{1,40})/([a-f0-9]{24,64})/menu', 'TableOrderController::menu/$1/$2', ['filter' => 'shield:60/60,get']);
    $routes->get('([a-z0-9-]{1,40})/([a-f0-9]{24,64})/challenge', 'TableOrderController::challenge/$1/$2', ['filter' => 'shield:60/60,get']);
    $routes->post('([a-z0-9-]{1,40})/([a-f0-9]{24,64})/quote', 'TableOrderController::quote/$1/$2', ['filter' => 'shield:90/60']);
    $routes->post('([a-z0-9-]{1,40})/([a-f0-9]{24,64})/place', 'TableOrderController::place/$1/$2', ['filter' => 'shield:human,6/120']);
    $routes->get('([a-z0-9-]{1,40})/([a-f0-9]{24,64})/o/([A-Za-z0-9-]{3,32}\.[a-f0-9]{40})', 'TableOrderController::status/$1/$2/$3', ['filter' => 'shield:60/60,get']);
});
