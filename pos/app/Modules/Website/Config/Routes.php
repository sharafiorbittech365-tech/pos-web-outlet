<?php

/** @var \CodeIgniter\Router\RouteCollection $routes */
$routes->group('website', ['namespace' => 'App\Modules\Website\Controllers', 'filter' => ['posauth', 'tenantserver', 'module:website', 'can:settings.manage']], static function ($routes): void {
    $routes->get('/', 'WebsiteController::index');
    $routes->post('mode', 'WebsiteController::mode');
    $routes->post('storefront', 'WebsiteController::storefront');
    $routes->post('product-page', 'WebsiteController::productPage');
    $routes->post('pages', 'WebsiteController::savePage');
    $routes->post('pages/(:num)', 'WebsiteController::savePage/$1');
    $routes->post('pages/(:num)/delete', 'WebsiteController::deletePage/$1');
    $routes->post('slug', 'WebsiteController::slug');
    $routes->post('cache', 'WebsiteController::cache');
    $routes->post('domains', 'WebsiteController::addDomain', ['filter' => 'feature:website.domains']);
    $routes->post('domains/(:num)/verify', 'WebsiteController::verifyDomain/$1');
    $routes->post('domains/(:num)/primary', 'WebsiteController::primaryDomain/$1');
    $routes->post('domains/(:num)/delete', 'WebsiteController::removeDomain/$1');
    $routes->post('domains/(:num)/toggle', 'WebsiteController::toggleDomain/$1');
    $routes->post('domains/(:num)/hosting', 'WebsiteController::domainHosting/$1');
    $routes->post('clients', 'WebsiteController::createClient');
    $routes->post('clients/(:num)/rotate', 'WebsiteController::rotateClient/$1');
    $routes->post('clients/(:num)/toggle', 'WebsiteController::toggleClient/$1');
    $routes->post('clients/(:num)/revoke', 'WebsiteController::revokeClient/$1');
    $routes->post('keys', 'WebsiteController::createKey', ['filter' => 'module:devapi']);
    $routes->post('keys/(:num)/revoke', 'WebsiteController::revokeKey/$1');
    $routes->post('origins', 'WebsiteController::addOrigin');
    $routes->post('origins/delete', 'WebsiteController::removeOrigin');
    $routes->post('webhooks', 'WebsiteController::addWebhook', ['filter' => 'module:devapi']);
    $routes->post('webhooks/(:num)/toggle', 'WebsiteController::toggleWebhook/$1');
    $routes->post('webhooks/(:num)/test', 'WebsiteController::testWebhook/$1', ['filter' => 'module:devapi']);
    $routes->post('webhooks/(:num)/delete', 'WebsiteController::deleteWebhook/$1');
});
