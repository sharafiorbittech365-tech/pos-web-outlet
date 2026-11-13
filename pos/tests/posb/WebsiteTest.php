<?php

declare(strict_types=1);

use App\Modules\Website\Services\WebsiteService;
use OmniPOS\Core\Services\ApiKeyService;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class WebsiteTest extends FeatureTestCase
{
    public function testApiKeyIsShownOnceAndOnlyItsHashIsStored(): void
    {
        $this->postForm('website/keys', ['key_name' => 'My Next.js site', 'key_type' => 'publishable', 'scopes' => ['products:read', 'orders:create', 'orders:read']])->assertRedirect();
        $flash = $_SESSION['new_key'] ?? null;
        $this->assertIsArray($flash, 'The full key is handed to the next page once (flash).');
        $key = (string) $flash['key'];
        $this->assertMatchesRegularExpression('/^pk_live_[A-Za-z0-9]{32}$/', $key);

        $row = db_connect()->table('api_keys')->where('business_id', $this->fx['business_id'])->where('name', 'My Next.js site')->get()->getRowArray();
        $this->assertSame(ApiKeyService::hash($key), $row['key_hash']);
        $this->assertStringNotContainsString($key, json_encode($row));
        // a publishable key never keeps secret-only scopes
        $this->assertSame(['products:read', 'orders:create'], json_decode((string) $row['scopes'], true));

        // the list shows only prefix and last 4
        $page = $this->getPage('website', ['tab' => 'api']);
        $page->assertOK();
        $page->assertSee($row['prefix'] . '••••' . $row['last4']);
        $page->assertDontSee($key);

        $this->postForm('website/keys/' . $row['id'] . '/revoke')->assertRedirect();
        $this->assertNotNull(db_connect()->table('api_keys')->where('id', $row['id'])->get()->getRow('revoked_at'));
    }

    public function testModeStorefrontDomainsOriginsWebhooks(): void
    {
        $this->postForm('website/mode', ['mode' => 'storefront'])->assertRedirect();
        $this->postForm('website/storefront', ['theme' => 'bold', 'palette' => 'royal', 'hero' => 'Hello', 'sub' => 'World', 'checkout' => ['cod' => '1'], 'delivery' => '60', 'free_above' => '0',
            'sections' => ['hero' => '1'], 'marketplace_listed' => '1', 'seo_title' => 'Shop'])->assertRedirect();
        $site = db_connect()->table('sites')->where('business_id', $this->fx['business_id'])->get()->getRowArray();
        $this->assertSame('storefront', $site['mode']);
        $this->assertSame('bold', $site['theme']);
        $this->assertSame(1, (int) $site['marketplace_listed']);
        $this->assertSame('#2563EB', json_decode((string) $site['brand'], true)['color']);

        $this->postForm('website/domains', ['host' => 'https://Shop.Example.com/'])->assertRedirect();
        $d = db_connect()->table('site_domains')->where('site_id', $site['id'])->get()->getRowArray();
        $this->assertSame('shop.example.com', $d['host']);
        $this->assertSame('pending', $d['status']);
        $this->bind('owner');
        $svc = new WebsiteService();
        $this->assertFalse($svc->verifyDomain((int) $d['id'], static fn (): array => ['something-else']));
        $this->assertTrue($svc->verifyDomain((int) $d['id'], static fn (string $n): array => $n === '_omnipos.shop.example.com' ? [$d['verify_token']] : []));
        $d = db_connect()->table('site_domains')->where('id', $d['id'])->get()->getRowArray();
        $this->assertSame('active', $d['status']);
        $this->assertSame(1, (int) $d['is_primary']);

        $this->postForm('website/origins', ['origin' => 'https://www.example.com'])->assertRedirect();
        $this->assertSame(1, db_connect()->table('site_origins')->where('site_id', $site['id'])->countAllResults());
        $this->postForm('website/webhooks', ['url' => 'https://example.com/hook', 'events' => ['order.created', 'bogus']])->assertRedirect();
        $h = db_connect()->table('webhooks')->where('site_id', $site['id'])->get()->getRowArray();
        $this->assertSame(['order.created'], json_decode((string) $h['events'], true));
        $this->postForm('website/webhooks/' . $h['id'] . '/test')->assertRedirect();
        $this->assertSame(1, db_connect()->table('webhook_deliveries')->where('webhook_id', $h['id'])->countAllResults());

        foreach (['overview', 'storefront', 'domains', 'api', 'webhooks', 'sync'] as $tab) {
            $this->getPage('website', ['tab' => $tab])->assertOK();
        }
    }
}
