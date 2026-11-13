<?php

declare(strict_types=1);

namespace Tests\Feature;

use OmniPOS\Core\Pricing\Cart;
use OmniPOS\Core\Pricing\CartLine;
use OmniPOS\Core\Pricing\OrderCalculator;
use OmniPOS\Core\Pricing\TaxPolicy;
use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * @internal
 */
final class OrderTest extends ApiTestCase
{
    private function payload(Fixture $f, array $over = []): array
    {
        return $over + [
            'items' => [['product_id' => $f->products['charger'], 'qty' => 2], ['product_id' => $f->products['speaker'], 'qty' => 1]],
            'customer' => ['name' => 'Rafi', 'phone' => '01711000000', 'address' => 'House 5, Road 2, Uttara'],
            'delivery' => 'delivery', 'payment' => 'cod', 'location' => ['lat' => 23.87, 'lng' => 90.38, 'area' => 'Uttara'], 'channel' => 'storefront',
        ];
    }

    public function testCreateOrderTakesStockAndMatchesCalculator(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'POST', 'v1/orders', $this->payload($f));
        $r->assertStatus(201);
        $d = $this->body($r)['data'];

        // 2 x 100 (+5% added) + 200 (10% inside) = 410 goods; below freeAbove 1000 -> delivery 60
        $b = db_connect()->table('businesses')->where('id', $f->businessId)->get()->getRowArray();
        $expected = (new OrderCalculator(2))->calculate(new Cart(
            lines: [new CartLine(qty: 2, unitPrice: 100, taxMode: 'default', taxRate: null), new CartLine(qty: 1, unitPrice: 200, taxMode: 'inclusive', taxRate: 10.0)],
            shipping: 60,
        ), TaxPolicy::fromBusiness($b));
        $this->assertMatchesRegularExpression('/^W[0-9A-Z]{6}$/', $d['reference']);
        $this->assertSame('due', $d['status']);
        $this->assertEqualsWithDelta(60.0, $d['shipping'], 0.001);
        $this->assertEqualsWithDelta($expected->subtotal, $d['subtotal'], 0.001);
        $this->assertEqualsWithDelta($expected->tax, $d['tax'], 0.001);
        $this->assertEqualsWithDelta($expected->total, $d['total'], 0.001);
        $this->assertEqualsWithDelta(470.0, $d['total'], 0.001);

        $this->assertSame(3.0, $f->stock('charger'));
        $this->assertSame(1.0, $f->stock('speaker'));
        $o = db_connect()->table('orders')->where('web_ref', $d['reference'])->get()->getRowArray();
        $this->assertSame('web', $o['channel']);
        $this->assertSame($f->businessId, (int) $o['business_id']);
        $this->assertNotNull($o['api_key_id']);
        $this->assertSame('Uttara', $o['geo_area']);
        $this->assertSame(0, db_connect()->table('order_payments')->where('order_id', $o['id'])->countAllResults());

        // tracking
        $t = $this->storefront($f, 'GET', 'v1/orders/' . $d['reference']);
        $t->assertStatus(200);
        $this->assertSame('ordered', $this->body($t)['data']['ship_status']);
        $this->assertCount(2, $this->body($t)['data']['items']);
        $this->assertError($this->storefront(Fixture::create(), 'GET', 'v1/orders/' . $d['reference']), 404, 'not_found');
    }

    public function testFreeDeliveryAboveAndPickup(): void
    {
        $f = Fixture::create(['freeAbove' => 400]);
        $d = $this->body($this->storefront($f, 'POST', 'v1/orders', $this->payload($f)))['data'];
        $this->assertEqualsWithDelta(0.0, $d['shipping'], 0.001);

        $p = $this->body($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['delivery' => 'pickup', 'items' => [['product_id' => $f->products['charger'], 'qty' => 1]]])))['data'];
        $this->assertEqualsWithDelta(0.0, $p['shipping'], 0.001);
        $this->assertEqualsWithDelta(105.0, $p['total'], 0.001);
    }

    public function testBkashRecordsPayment(): void
    {
        $f = Fixture::create();
        $d = $this->body($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['payment' => 'bkash', 'trx_id' => 'TRX9A8B7C'])))['data'];
        $this->assertSame('paid', $d['status']);
        $o = db_connect()->table('orders')->where('web_ref', $d['reference'])->get()->getRowArray();
        $pay = db_connect()->table('order_payments')->where('order_id', $o['id'])->get()->getRowArray();
        $this->assertSame('bkash', $pay['method']);
        $this->assertSame('TRX9A8B7C', $pay['reference']);
        $this->assertEqualsWithDelta($d['total'], (float) $pay['amount'], 0.001);
    }

    public function testValidation(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'POST', 'v1/orders', ['items' => [], 'payment' => 'card', 'customer' => ['phone' => '1']]);
        $this->assertError($r, 422, 'validation_failed');
        $details = $this->body($r)['error']['details'];
        foreach (['items', 'payment', 'customer.name', 'customer.phone', 'customer.address'] as $k) {
            $this->assertArrayHasKey($k, $details);
        }
        $this->assertError($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['payment' => 'bkash'])), 422, 'validation_failed');
        $this->assertError($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['items' => [['product_id' => $f->products['charger'], 'qty' => 0]]])), 422, 'validation_failed');
        $this->assertError($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['items' => array_fill(0, 51, ['product_id' => $f->products['charger'], 'qty' => 1])])), 422, 'validation_failed');
        // a product of another shop is not orderable
        $other = Fixture::create();
        $bad = $this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['items' => [['product_id' => $other->products['charger'], 'qty' => 1]]]));
        $this->assertError($bad, 422, 'validation_failed');
        $this->assertArrayHasKey('items.0', $this->body($bad)['error']['details']);
        $this->assertSame(5.0, $other->stock('charger'));
    }

    public function testOutOfStockIs409AndNothingIsWritten(): void
    {
        $f = Fixture::create();
        $before = db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults();
        $r = $this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['items' => [['product_id' => $f->products['charger'], 'qty' => 1], ['product_id' => $f->products['speaker'], 'qty' => 3]]]));
        $this->assertError($r, 409, 'out_of_stock');
        $this->assertSame($before, db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults());
        $this->assertSame(5.0, $f->stock('charger'));   // rolled back
    }

    public function testIdempotentReplay(): void
    {
        $f = Fixture::create();
        $h = ['Idempotency-Key' => 'cart-' . bin2hex(random_bytes(4))];
        $first = $this->storefront($f, 'POST', 'v1/orders', $this->payload($f), $h);
        $first->assertStatus(201);
        $second = $this->storefront($f, 'POST', 'v1/orders', $this->payload($f), $h);
        $second->assertStatus(201);
        $this->assertSame('true', $second->response()->getHeaderLine('Idempotent-Replayed'));
        $this->assertSame($this->body($first)['data'], $this->body($second)['data']);
        $this->assertSame(1, db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults());
        $this->assertSame(3.0, $f->stock('charger'));

        // same key, different request
        $this->assertError($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['note' => 'changed']), $h), 409, 'conflict');

        // a failed request releases the key: fixing the body and retrying works
        $k2 = ['Idempotency-Key' => 'cart-' . bin2hex(random_bytes(4))];
        $this->assertError($this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['payment' => 'bkash']), $k2), 422, 'validation_failed');
        $this->storefront($f, 'POST', 'v1/orders', $this->payload($f, ['payment' => 'bkash', 'trx_id' => 'TRX55555']), $k2)->assertStatus(201);
    }

    public function testIntegratorOrderListAndDetail(): void
    {
        $f = Fixture::create();
        $ref = $this->body($this->storefront($f, 'POST', 'v1/orders', $this->payload($f)))['data']['reference'];
        $key = ['Authorization' => 'Bearer ' . $f->secret];
        $list = $this->body($this->api('GET', 'v1/orders?status=due&from=' . date('Y-m-d') . '&to=' . date('Y-m-d'), $key));
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame($ref, $list['data'][0]['reference']);
        $this->assertSame(0, $this->body($this->api('GET', 'v1/orders?status=paid', $key))['meta']['total']);
        $detail = $this->body($this->api('GET', 'v1/orders/' . $list['data'][0]['id'], $key))['data'];
        $this->assertCount(2, $detail['items']);
        $this->assertNotEmpty($detail['taxes']);
        $this->assertError($this->api('GET', 'v1/orders?status=nope', $key), 422, 'validation_failed');
        // another tenant's order id is not visible
        $this->assertError($this->api('GET', 'v1/orders/' . $list['data'][0]['id'], ['Authorization' => 'Bearer ' . Fixture::create()->secret]), 404, 'not_found');
    }
}
