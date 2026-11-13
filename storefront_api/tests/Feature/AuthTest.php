<?php

declare(strict_types=1);

namespace Tests\Feature;

use OmniPOS\Core\Services\StorefrontGateway;
use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * @internal
 */
final class AuthTest extends ApiTestCase
{
    public function testMissingKeyIs401(): void
    {
        $this->assertError($this->api('GET', 'v1/products'), 401, 'unauthenticated');
    }

    public function testWrongKeyIs401(): void
    {
        $r = $this->api('GET', 'v1/products', ['Authorization' => 'Bearer pk_live_' . str_repeat('x', 32)]);
        $this->assertError($r, 401, 'unauthenticated');
    }

    public function testUnknownStorefrontHostIs404(): void
    {
        $r = $this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $this->gateway, 'X-Storefront-Host' => 'no-such-shop']);
        $this->assertError($r, 404, 'not_found');
    }

    public function testGatewayTokenFromSuperAdminReplacesTheEnvToken(): void
    {
        $f = Fixture::create();
        $call = fn (string $token) => $this->api('GET', 'v1/storefront', ['Authorization' => 'Bearer ' . $token, 'X-Storefront-Host' => $f->slug]);
        $put = static function (array $value): void {
            db_connect()->query('INSERT INTO platform_settings (setting_key, value, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE value = VALUES(value)', [StorefrontGateway::SETTING, json_encode($value)]);
            service('catalog')->flush();
        };
        try {
            $put(['hash' => StorefrontGateway::hash('sfg_new'), 'prefix' => 'sfg_new', 'last4' => '_new', 'disabled' => false,
                'previous' => ['hash' => StorefrontGateway::hash('sfg_old'), 'prefix' => 'sfg_old', 'last4' => '_old', 'valid_until' => date('Y-m-d H:i:s', time() + 3600)]]);
            $call('sfg_new')->assertStatus(200);
            $call('sfg_old')->assertStatus(200);   // inside its grace period
            if ($this->gateway !== '') {
                $this->assertError($call($this->gateway), 401, 'unauthenticated');   // the .env token no longer applies
            }

            $put(['hash' => StorefrontGateway::hash('sfg_new'), 'prefix' => 'sfg_new', 'last4' => '_new', 'disabled' => false,
                'previous' => ['hash' => StorefrontGateway::hash('sfg_old'), 'prefix' => 'sfg_old', 'last4' => '_old', 'valid_until' => date('Y-m-d H:i:s', time() - 60)]]);
            $this->assertError($call('sfg_old'), 401, 'unauthenticated');   // grace period over

            // the first token from Super Admin: this server's .env token keeps working for the grace period
            $put(['hash' => StorefrontGateway::hash('sfg_new'), 'prefix' => 'sfg_new', 'last4' => '_new', 'disabled' => false, 'previous' => null,
                'env_valid_until' => date('Y-m-d H:i:s', time() + 3600)]);
            if ($this->gateway !== '') {
                $call($this->gateway)->assertStatus(200);
            }

            $put(['hash' => StorefrontGateway::hash('sfg_new'), 'prefix' => 'sfg_new', 'last4' => '_new', 'disabled' => true, 'previous' => null]);
            $this->assertError($call('sfg_new'), 401, 'unauthenticated');   // disabled in Super Admin
        } finally {
            db_connect()->table('platform_settings')->where('setting_key', StorefrontGateway::SETTING)->delete();
            service('catalog')->flush();
        }
    }

    public function testGatewayTokenActsAsTheSitesStorefrontKey(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'GET', 'v1/storefront');
        $r->assertStatus(200);
        $data = $this->body($r)['data'];
        $this->assertSame($f->slug, $data['site']['slug']);
        $this->assertSame(['label' => 'VAT', 'rate' => 5, 'inclusive' => false], $data['business']['tax']);
        $this->assertSame('repair', $data['site']['booking_kind']);
        $this->assertTrue($data['site']['bookings_enabled']);
        $this->assertSame(4, $data['categories'][0]['products']);   // hidden + deleted not counted
        $this->assertNotEmpty($r->response()->getHeaderLine('X-Request-Id'));
        // the auto storefront key was created on first use
        $this->assertSame(1, db_connect()->table('api_keys')->where('site_id', $f->siteId)->where('is_storefront', 1)->countAllResults());
    }

    public function testSuspendedSubscriptionIs402(): void
    {
        $f = Fixture::create(['status' => 'suspended']);
        $this->assertError($this->storefront($f, 'GET', 'v1/products'), 402, 'subscription_inactive');
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $f->secret]), 402, 'subscription_inactive');
    }

    public function testTrialSubscriptionWorks(): void
    {
        $f = Fixture::create(['status' => 'trial']);
        $this->storefront($f, 'GET', 'v1/products')->assertStatus(200);
    }

    public function testModuleDisabledIs403(): void
    {
        $f = Fixture::create();
        $f->setModule('devapi', false);
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $f->secret]), 403, 'module_disabled');
        // the hosted storefront needs "website", not "devapi"
        $this->storefront($f, 'GET', 'v1/products')->assertStatus(200);
    }

    public function testMissingScopeIs403(): void
    {
        $f = Fixture::create();
        $key = ['Authorization' => 'Bearer ' . $f->productsOnly];
        $this->api('GET', 'v1/products', $key)->assertStatus(200);
        $this->assertError($this->api('GET', 'v1/orders', $key), 403, 'forbidden');
        $this->assertError($this->api('GET', 'v1/orders/1', $key), 403, 'forbidden');
        $this->assertError($this->api('GET', 'v1/orders', ['Authorization' => 'Bearer ' . $f->publishable]), 403, 'forbidden');
    }

    public function testPublishableKeyOriginCheck(): void
    {
        $f = Fixture::create(['origins' => ['https://shop.example']]);
        $ok = $this->api('GET', 'v1/categories', ['Authorization' => 'Bearer ' . $f->publishable, 'Origin' => 'https://shop.example']);
        $ok->assertStatus(200);
        $this->assertSame('https://shop.example', $ok->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertError($this->api('GET', 'v1/categories', ['Authorization' => 'Bearer ' . $f->publishable, 'Origin' => 'https://evil.example']), 403, 'forbidden');
        // secret keys are server-side only: any browser request (Origin header) with one is refused
        $this->assertError($this->api('GET', 'v1/categories', ['Authorization' => 'Bearer ' . $f->secret, 'Origin' => 'https://shop.example']), 403, 'forbidden');
    }

    public function testUnpublishedSiteIs403(): void
    {
        $f = Fixture::create(['mode' => 'off']);
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $f->secret]), 403, 'forbidden');
    }

    public function testPreflightIsAnsweredWithoutKey(): void
    {
        $r = $this->api('OPTIONS', 'v1/orders', ['Origin' => 'https://shop.example', 'Access-Control-Request-Method' => 'POST']);
        $r->assertStatus(204);
        $this->assertStringContainsString('Idempotency-Key', $r->response()->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testUnknownRouteIsJson404(): void
    {
        $this->assertError($this->api('GET', 'v1/nothing-here'), 404, 'not_found');
    }

    public function testHealth(): void
    {
        $r = $this->api('GET', 'v1/health');
        $r->assertStatus(200);
        $this->assertSame('ok', $this->body($r)['data']['status']);
        $this->assertTrue($this->body($r)['data']['database']['ok']);
    }
}
