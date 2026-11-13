<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;
use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * Self-hosted storefronts (CLIENT_ID + CLIENT_SECRET), domain authorisation, tenant isolation
 * and exact-origin CORS.
 *
 * @internal
 */
final class SelfHostedTest extends ApiTestCase
{
    /**
     * A shop with one verified, enabled, self-hosted domain and a storefront client. Domains are
     * unique per fixture because the test database keeps rows between tests.
     *
     * @return array{f: Fixture, id: string, secret: string, domainId: int, host: string}
     */
    private function shop(array $o = [], string $hosting = 'external'): array
    {
        $f = Fixture::create($o);
        $host = 'www.' . $f->slug . '.test';
        $db = db_connect();
        $db->table('site_domains')->insert(['site_id' => $f->siteId, 'host' => $host, 'hosting' => $hosting, 'status' => 'active', 'is_primary' => 1,
            'is_enabled' => 1, 'admin_blocked' => 0, 'verify_token' => 'omnipos-verify=x', 'ssl_status' => 'active', 'verified_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        $domainId = (int) $db->insertID();
        $c = service('storefrontClients')->create($f->siteId, 'Test client');

        return ['f' => $f, 'id' => $c['client_id'], 'secret' => $c['client_secret'], 'domainId' => $domainId, 'host' => $host];
    }

    /** @param array<string, string> $extra */
    private function client(string $id, string $secret, string $host, string $path = 'v1/storefront', array $extra = []): TestResponse
    {
        return $this->api('GET', $path, $extra + ['X-Client-Id' => $id, 'Authorization' => 'Bearer ' . $secret, 'X-Storefront-Host' => $host]);
    }

    public function testClientOnItsVerifiedDomainGetsItsOwnStore(): void
    {
        $s = $this->shop(['type' => 'grocery']);
        $r = $this->client($s['id'], $s['secret'], $s['host']);
        $r->assertStatus(200);
        $d = $this->body($r)['data'];
        $this->assertSame($s['f']->businessId, $d['business']['id']);
        $this->assertSame('client', $d['site']['connection']);
        $this->assertSame($s['host'], $d['site']['served_host']);
        $this->assertSame('fresh', $d['site']['theme_default'], 'grocery → fresh');
    }

    public function testStoredSecretIsOnlyAHash(): void
    {
        $s = $this->shop();
        $row = db_connect()->table('storefront_clients')->where('client_id', $s['id'])->get()->getRowArray();
        $this->assertSame(hash('sha256', $s['secret']), $row['secret_hash']);
        $this->assertStringNotContainsString($s['secret'], (string) json_encode($row));
    }

    public function testWrongSecretOrUnknownClientIs401(): void
    {
        $s = $this->shop();
        $this->assertError($this->client($s['id'], 'sfs_' . str_repeat('A', 48), $s['host']), 401, 'unauthenticated');
        $this->assertError($this->client('sfc_' . str_repeat('B', 24), 'sfs_' . str_repeat('A', 48), $s['host']), 401, 'unauthenticated');
    }

    public function testHostIsRequiredWithClientId(): void
    {
        $s = $this->shop();
        $this->assertError($this->api('GET', 'v1/storefront', ['X-Client-Id' => $s['id'], 'Authorization' => 'Bearer ' . $s['secret']]), 422, 'validation_failed');
    }

    public function testUnverifiedDisabledOrBlockedDomainIsRefused(): void
    {
        $s = $this->shop();
        $this->assertError($this->client($s['id'], $s['secret'], 'not-my-domain.test'), 403, 'forbidden');

        service('domains')->setEnabled($s['domainId'], false);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 403, 'forbidden');

        service('domains')->setEnabled($s['domainId'], true);
        $this->client($s['id'], $s['secret'], $s['host'])->assertStatus(200);

        service('domains')->setBlocked($s['domainId'], true, 'Abuse report', ['name' => 'Test']);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 403, 'forbidden');

        db_connect()->table('site_domains')->where('id', $s['domainId'])->update(['admin_blocked' => 0, 'status' => 'pending']);
        service('domains')->forget($s['f']->siteId);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 403, 'forbidden');
    }

    public function testClientCannotUseAnotherShopsDomainOrData(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        // A's credentials on B's verified domain: refused (the domain belongs to another site)
        $this->assertError($this->client($a['id'], $a['secret'], $b['host']), 403, 'forbidden');
        // A's credentials can only ever see A's products
        $this->assertError($this->client($a['id'], $a['secret'], $a['host'], 'v1/products/' . $b['f']->products['charger']), 404, 'not_found');
        $this->client($a['id'], $a['secret'], $a['host'], 'v1/products/' . $a['f']->products['charger'])->assertStatus(200);
    }

    public function testRevokedPausedAndRotatedClients(): void
    {
        $s = $this->shop();
        $siteId = $s['f']->siteId;
        $rowId = (int) db_connect()->table('storefront_clients')->select('id')->where('client_id', $s['id'])->get()->getRow('id');

        service('storefrontClients')->setActive($rowId, $siteId, false);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 401, 'unauthenticated');
        service('storefrontClients')->setActive($rowId, $siteId, true);
        $this->client($s['id'], $s['secret'], $s['host'])->assertStatus(200);

        $new = service('storefrontClients')->rotate($rowId, $siteId);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 401, 'unauthenticated');
        $this->client($s['id'], $new['client_secret'], $s['host'])->assertStatus(200);

        service('storefrontClients')->revoke($rowId, $siteId);
        $this->assertError($this->client($s['id'], $new['client_secret'], $s['host']), 401, 'unauthenticated');
    }

    public function testClientRespectsSubscriptionAndModule(): void
    {
        $s = $this->shop();
        $s['f']->setStatus('suspended');
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 402, 'subscription_inactive');
        $s['f']->setStatus('active');
        $s['f']->setModule('website', false);
        $this->assertError($this->client($s['id'], $s['secret'], $s['host']), 403, 'module_disabled');
    }

    public function testExactOriginCors(): void
    {
        $s = $this->shop(['origins' => ['https://app.listed-origin.test']]);
        $origin = 'https://' . $s['host'];
        $ok = $this->api('OPTIONS', 'v1/products', ['Origin' => $origin]);
        $ok->assertStatus(204);
        $this->assertSame($origin, $ok->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->api('OPTIONS', 'v1/products', ['Origin' => 'https://app.listed-origin.test'])->assertStatus(204);
        // look-alikes and unknown origins: 403 and no CORS header
        foreach (['https://evil.test', $origin . '.evil.test', 'https://evil' . $s['host'], $origin . ':8443'] as $bad) {
            $r = $this->api('OPTIONS', 'v1/products', ['Origin' => $bad]);
            $r->assertStatus(403);
            $this->assertSame('', $r->response()->getHeaderLine('Access-Control-Allow-Origin'), $bad);
        }
    }

    public function testBrowserCallsGetOnlyTheirExactOriginEchoed(): void
    {
        $s = $this->shop();
        $origin = 'https://' . $s['host'];
        $r = $this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $s['f']->publishable, 'Origin' => $origin]);
        $r->assertStatus(200);
        $this->assertSame($origin, $r->response()->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $s['f']->publishable, 'Origin' => 'https://evil.test']), 403, 'forbidden');
    }

    public function testSecretKeysAreRefusedFromBrowsers(): void
    {
        $s = $this->shop();
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $s['f']->secret, 'Origin' => 'https://' . $s['host']]), 403, 'forbidden');
    }

    public function testGatewayRefusesDisabledCustomDomain(): void
    {
        $s = $this->shop([], 'platform');
        $hdr = ['Authorization' => 'Bearer ' . $this->gateway, 'X-Storefront-Host' => $s['host']];
        $this->api('GET', 'v1/storefront', $hdr)->assertStatus(200);
        service('domains')->setEnabled($s['domainId'], false);
        $this->assertError($this->api('GET', 'v1/storefront', $hdr), 404, 'not_found');
    }
}
