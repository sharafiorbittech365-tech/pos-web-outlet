<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Multisite\Services\MultisiteDirectory;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\TestResponse;
use OmniPOS\Core\Services\MultisiteKeys;
use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * Multi-outlet website endpoints (/v1/multisite): credential, tenant / outlet isolation, merged
 * catalogue with per-outlet availability, and orders into one chosen outlet.
 *
 * @internal
 */
final class MultisiteTest extends ApiTestCase
{
    /**
     * Tenant with three outlets: A (the Fixture outlet), B (authorised), C (not opted in).
     *
     * @return array{f: Fixture, b: int, c: int, msId: int, key: string, slug: string, bCharger: int, bOnly: int, cOnly: int}
     */
    private function chain(bool $enabled = true): array
    {
        $f = Fixture::create();
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $u = substr($f->slug, 2);
        $outlet = static function (string $name, int $inclusive) use ($db, $f, $now, $u): int {
            $db->table('businesses')->insert(['tenant_id' => $f->tenantId, 'business_type' => 'electronics', 'name' => $name . ' ' . $u, 'slug' => strtolower($name) . '-' . $u,
                'phone' => '01800000000', 'address' => 'Road 1', 'city' => 'Dhaka', 'area' => $name, 'lat' => 23.75, 'lng' => 90.39, 'currency_code' => 'BDT', 'currency_symbol' => '৳',
                'currency_decimals' => 2, 'tax_label' => 'VAT', 'tax_rate' => 5, 'tax_inclusive' => $inclusive, 'stock_enabled' => 1,
                'settings' => json_encode(['hours' => ['open' => '09:00', 'close' => '21:00']]), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

            return (int) $db->insertID();
        };
        $b = $outlet('Banani', 1);
        $c = $outlet('Uttara', 1);
        $product = static function (int $bid, string $name, float $price, float $stock) use ($db, $f, $now): int {
            $db->table('products')->insert(['tenant_id' => $f->tenantId, 'business_id' => $bid, 'name' => $name, 'price' => $price, 'tax_mode' => 'default',
                'stock_type' => 'simple', 'stock_qty' => $stock, 'is_online' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

            return (int) $db->insertID();
        };
        // the same article as outlet A's charger (grouped by name): own price, out of stock
        $bCharger = $product($b, 'Fast Charger ' . $u, 120, 0);
        $bOnly = $product($b, 'Power Bank ' . $u, 900, 4);
        $cOnly = $product($c, 'Secret Item ' . $u, 10, 9);
        // B has a site (checkout settings: cod + pickup); C has none
        $db->table('sites')->insert(['tenant_id' => $f->tenantId, 'business_id' => $b, 'mode' => 'off', 'slug' => 'bsite' . $u, 'theme' => 'fresh',
            'checkout' => json_encode(['cod' => true, 'pickup' => true, 'delivery' => 50, 'freeAbove' => 0]), 'created_at' => $now]);

        $slug = 'ms' . $u;
        $db->table('multisites')->insert(['tenant_id' => $f->tenantId, 'home_business_id' => $f->businessId, 'slug' => $slug, 'name' => 'Chain ' . $u,
            'is_enabled' => $enabled ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        $msId = (int) $db->insertID();
        $db->table('multisite_outlets')->insertBatch([
            ['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'multisite_id' => $msId, 'is_enabled' => 1, 'sort_order' => 0, 'created_at' => $now],
            ['tenant_id' => $f->tenantId, 'business_id' => $b, 'multisite_id' => $msId, 'is_enabled' => 1, 'sort_order' => 1, 'created_at' => $now],
            ['tenant_id' => $f->tenantId, 'business_id' => $c, 'multisite_id' => $msId, 'is_enabled' => 0, 'sort_order' => 2, 'created_at' => $now],
        ]);
        $key = (new MultisiteKeys())->issue($msId)['key'];

        return ['f' => $f, 'b' => $b, 'c' => $c, 'msId' => $msId, 'key' => $key, 'slug' => $slug, 'bCharger' => $bCharger, 'bOnly' => $bOnly, 'cOnly' => $cOnly];
    }

    /** @param array<string, string> $headers */
    private function ms(string $key, string $method, string $path, ?array $json = null, array $headers = []): TestResponse
    {
        Services::injectMock('multisite', new MultisiteDirectory());

        return $this->api($method, $path, $headers + ['Authorization' => 'Bearer ' . $key], $json);
    }

    /** @return list<array<string, mixed>> */
    private function items(TestResponse $r): array
    {
        $r->assertStatus(200);

        return $this->body($r)['data'];
    }

    private function find(array $items, string $prefix): ?array
    {
        foreach ($items as $i) {
            if (str_starts_with((string) $i['name'], $prefix)) {
                return $i;
            }
        }

        return null;
    }

    public function testCredentialRules(): void
    {
        $c = $this->chain();
        $this->assertError($this->ms('', 'GET', 'v1/multisite'), 401, 'unauthenticated');
        // a single-outlet key is not a website key; this API has no single-outlet endpoints at all
        $this->assertError($this->ms($c['f']->secret, 'GET', 'v1/multisite'), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'v1/products', ['Authorization' => 'Bearer ' . $c['key']]), 404, 'not_found');
        // server-side only
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite', null, ['Origin' => 'https://shop.example']), 403, 'forbidden');

        $r = $this->ms($c['key'], 'GET', 'v1/multisite');
        $r->assertStatus(200);
        $d = $this->body($r)['data'];
        $this->assertSame('Chain ' . substr($c['f']->slug, 2), $d['website']['name']);
        $ids = array_column($d['outlets'], 'id');
        $this->assertSame([$c['f']->businessId, $c['b']], $ids, 'only authorised outlets, in display order');
        $this->assertSame(['open' => '09:00', 'close' => '21:00'], $d['outlets'][1]['hours']);
        $this->assertSame('gadgets', $d['categories'][0]['key']);

        // rotation: the old key stops working at once
        $new = (new MultisiteKeys())->issue($c['msId'])['key'];
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite'), 401, 'unauthenticated');
        $this->ms($new, 'GET', 'v1/multisite')->assertStatus(200);
        (new MultisiteKeys())->revoke($c['msId']);
        $this->assertError($this->ms($new, 'GET', 'v1/multisite'), 401, 'unauthenticated');
    }

    public function testWebsiteOffModuleOffAndSuspended(): void
    {
        $c = $this->chain(false);
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite'), 403, 'forbidden');
        db_connect()->table('multisites')->where('id', $c['msId'])->update(['is_enabled' => 1]);
        $this->ms($c['key'], 'GET', 'v1/multisite')->assertStatus(200);
        $c['f']->setModule('multisite', false);
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite'), 403, 'module_disabled');
        $c['f']->setModule('multisite', true);
        $c['f']->setStatus('suspended');
        $this->assertSame(402, $this->ms($c['key'], 'GET', 'v1/multisite')->response()->getStatusCode());
    }

    public function testGatewayTokenWithHost(): void
    {
        $c = $this->chain();
        $r = $this->ms($this->gateway, 'GET', 'v1/multisite/outlets', null, ['X-Multisite-Host' => $c['slug']]);
        $this->assertCount(2, $this->items($r));
        $this->assertError($this->ms($this->gateway, 'GET', 'v1/multisite', null, ['X-Multisite-Host' => 'nosuchsite']), 404, 'not_found');
        $this->assertError($this->ms('sfg_wrong', 'GET', 'v1/multisite', null, ['X-Multisite-Host' => $c['slug']]), 401, 'unauthenticated');
    }

    public function testCatalogueGroupsOutletsWithTheirOwnPriceAndStock(): void
    {
        $c = $this->chain();
        $u = substr($c['f']->slug, 2);
        $items = $this->items($this->ms($c['key'], 'GET', 'v1/multisite/products', null, ['X-Ignore' => '1']));
        $names = array_column($items, 'name');
        $this->assertNotContains('Secret Item ' . $u, $names, 'outlet C is not authorised');
        $this->assertNotContains('Hidden ' . $u, $names);

        $charger = $this->find($items, 'Fast Charger');
        $this->assertNotNull($charger);
        $this->assertSame($c['f']->products['charger'], $charger['id']);
        $this->assertCount(2, $charger['offers']);
        [$a, $b] = $charger['offers'];
        $this->assertSame([$c['f']->businessId, 105.0, true, 5], [$a['outlet_id'], (float) $a['price'], $a['in_stock'], $a['stock']]);
        $this->assertSame([$c['b'], 120.0, false, 0], [$b['outlet_id'], (float) $b['price'], $b['in_stock'], $b['stock']]);
        $this->assertSame([105.0, 120.0, 1, 2], [(float) $charger['price_min'], (float) $charger['price_max'], $charger['outlets_in_stock'], $charger['outlets_total']]);

        // detail by any product id of the group
        $r = $this->ms($c['key'], 'GET', 'v1/multisite/products/' . $c['bCharger']);
        $d = $this->body($r)['data'];
        $this->assertSame($c['f']->products['charger'], $d['id']);
        $this->assertCount(2, $d['offers']);
        $this->assertArrayHasKey('description', $d);
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/products/' . $c['cOnly']), 404, 'not_found');

        // ETag: unchanged → 304
        $etag = $r->response()->getHeaderLine('ETag');
        $this->assertSame(304, $this->ms($c['key'], 'GET', 'v1/multisite/products/' . $c['bCharger'], null, ['If-None-Match' => $etag])->response()->getStatusCode());
    }

    public function testFilters(): void
    {
        $c = $this->chain();
        $u = substr($c['f']->slug, 2);
        $names = fn (array $q): array => array_column($this->items($this->ms($c['key'], 'GET', 'v1/multisite/products?' . http_build_query($q))), 'name');

        $this->assertSame(['Fast Charger ' . $u, 'Power Bank ' . $u], $names(['outlet' => $c['b'], 'sort' => 'name']));
        $this->assertNotContains('Cable ' . $u, $names(['in_stock' => 1]));
        $this->assertContains('Cable ' . $u, $names([]));
        // charger is in stock at A only: still listed with in_stock, B's offer shown as out of stock
        $this->assertContains('Fast Charger ' . $u, $names(['in_stock' => 1]));
        $this->assertNotContains('Power Bank ' . $u, $names(['category' => 'Gadgets']));
        $this->assertSame(['Power Bank ' . $u], $names(['min_price' => 600]));
        $this->assertSame(['Power Bank ' . $u], $names(['q' => 'power']));
        $sorted = $names(['sort' => '-price']);
        $this->assertSame('Power Bank ' . $u, $sorted[0]);

        $r = $this->ms($c['key'], 'GET', 'v1/multisite/products?per_page=2&page=1');
        $this->assertSame(2, $this->body($r)['meta']['per_page']);
        $this->assertGreaterThanOrEqual(5, $this->body($r)['meta']['total']);

        // an outlet that is not opted in, or another tenant's outlet: not found
        $other = Fixture::create();
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/products?outlet=' . $c['c']), 404, 'not_found');
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/products?outlet=' . $other->businessId), 404, 'not_found');
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/outlets/' . $other->businessId), 404, 'not_found');
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/products?sort=bogus'), 422, 'validation_failed');
    }

    public function testOrderGoesIntoTheChosenOutletOnly(): void
    {
        $c = $this->chain();
        $order = ['outlet_id' => $c['b'], 'items' => [['product_id' => $c['bOnly'], 'qty' => 2]], 'customer' => ['name' => 'Rina', 'phone' => '01711000000'],
            'delivery' => 'pickup', 'payment' => 'cod'];
        $r = $this->ms($c['key'], 'POST', 'v1/multisite/orders', $order, ['Idempotency-Key' => 'ms-' . $c['slug']]);
        $r->assertStatus(201);
        $d = $this->body($r)['data'];
        $this->assertSame($c['b'], $d['outlet']['id']);
        $this->assertSame(1800.0, (float) $d['total']);
        $row = db_connect()->table('orders')->where('web_ref', $d['reference'])->get()->getRowArray();
        $this->assertSame([$c['b'], $c['f']->tenantId, 'web'], [(int) $row['business_id'], (int) $row['tenant_id'], $row['channel']]);
        $this->assertSame(2.0, (float) db_connect()->table('products')->select('stock_qty')->where('id', $c['bOnly'])->get()->getRow('stock_qty'));

        // replay with the same Idempotency-Key: same order, not a second one
        $again = $this->ms($c['key'], 'POST', 'v1/multisite/orders', $order, ['Idempotency-Key' => 'ms-' . $c['slug']]);
        $this->assertSame($d['reference'], $this->body($again)['data']['reference']);

        // tracking
        $t = $this->ms($c['key'], 'GET', 'v1/multisite/orders/' . $d['reference']);
        $t->assertStatus(200);
        $this->assertSame($c['b'], $this->body($t)['data']['outlet']['id']);

        // a product of outlet B ordered from outlet A: refused
        $bad = $order;
        $bad['outlet_id'] = $c['f']->businessId;
        $this->assertError($this->ms($c['key'], 'POST', 'v1/multisite/orders', $bad), 422, 'validation_failed');
        $this->assertArrayHasKey('items.0.product_id', $this->body($this->ms($c['key'], 'POST', 'v1/multisite/orders', $bad))['error']['details']);

        // outlet C (not opted in) and another tenant's outlet: refused
        $other = Fixture::create();
        foreach ([$c['c'], $other->businessId, 0] as $oid) {
            $x = $order;
            $x['outlet_id'] = $oid;
            $x['items'] = [['product_id' => $oid === $c['c'] ? $c['cOnly'] : $other->products['charger'], 'qty' => 1]];
            $resp = $this->ms($c['key'], 'POST', 'v1/multisite/orders', $x);
            $this->assertError($resp, 422, 'validation_failed');
            $this->assertArrayHasKey('outlet_id', $this->body($resp)['error']['details']);
        }
        $this->assertSame(9.0, (float) db_connect()->table('products')->select('stock_qty')->where('id', $c['cOnly'])->get()->getRow('stock_qty'));

        // another tenant's order reference is not visible
        $this->assertError($this->ms($c['key'], 'GET', 'v1/multisite/orders/WNOPE12'), 404, 'not_found');
    }

    public function testOrderFollowsTheOutletsCheckoutSettings(): void
    {
        $c = $this->chain();
        // outlet B's site has no bKash
        $r = $this->ms($c['key'], 'POST', 'v1/multisite/orders', ['outlet_id' => $c['b'], 'items' => [['product_id' => $c['bOnly'], 'qty' => 1]],
            'customer' => ['name' => 'Rina', 'phone' => '01711000000', 'address' => 'House 1, Road 2'], 'delivery' => 'delivery', 'payment' => 'bkash', 'trx_id' => 'TRX12345']);
        $this->assertError($r, 422, 'validation_failed');
        $this->assertArrayHasKey('payment', $this->body($r)['error']['details']);
        // delivery charge from B's settings (50)
        $r = $this->ms($c['key'], 'POST', 'v1/multisite/orders', ['outlet_id' => $c['b'], 'items' => [['product_id' => $c['bOnly'], 'qty' => 1]],
            'customer' => ['name' => 'Rina', 'phone' => '01711000000', 'address' => 'House 1, Road 2'], 'delivery' => 'delivery', 'payment' => 'cod',
            'location' => ['lat' => 23.79, 'lng' => 90.40, 'area' => 'Banani']]);
        $r->assertStatus(201);
        $this->assertSame(50.0, (float) $this->body($r)['data']['shipping']);
    }
}
