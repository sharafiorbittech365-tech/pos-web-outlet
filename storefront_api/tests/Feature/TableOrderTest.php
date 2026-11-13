<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * Table QR ordering: GET /v1/tables/{code} and POST /v1/orders with "table".
 *
 * @internal
 */
final class TableOrderTest extends ApiTestCase
{
    private function table(Fixture $f, string $name = 'Table 7'): string
    {
        $code = bin2hex(random_bytes(12));
        db_connect()->table('dining_tables')->insert(['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'name' => $name, 'seats' => 4, 'qr_token' => $code]);

        return $code;
    }

    private function shop(): Fixture
    {
        $f = Fixture::create();
        $f->setModule('floor', true);
        $f->setModule('tableqr', true);

        return $f;
    }

    public function testTableLookupOnlyForTheShopsOwnTables(): void
    {
        $f = $this->shop();
        $code = $this->table($f);
        $r = $this->storefront($f, 'GET', 'v1/tables/' . $code);
        $r->assertStatus(200);
        $this->assertSame('Table 7', $this->body($r)['data']['name']);

        // another shop's table code, a made-up code, a malformed code: all "not found"
        $other = $this->shop();
        $this->assertError($this->storefront($other, 'GET', 'v1/tables/' . $code), 404, 'not_found');
        $this->assertError($this->storefront($f, 'GET', 'v1/tables/' . str_repeat('a', 24)), 404, 'not_found');
        $this->assertError($this->storefront($f, 'GET', 'v1/tables/..%2F' . $code), 404, 'not_found');

        // Table QR ordering switched off: the code stops working
        $f->setModule('tableqr', false);
        $this->assertError($this->storefront($f, 'GET', 'v1/tables/' . $code), 404, 'not_found');
    }

    public function testTableOrderIsAnOpenDineInBillOnThatTable(): void
    {
        $f = $this->shop();
        $code = $this->table($f);
        $r = $this->storefront($f, 'POST', 'v1/orders', [
            'items' => [['product_id' => $f->products['charger'], 'qty' => 1]],
            'customer' => ['name' => '', 'phone' => ''], 'payment' => 'cod', 'table' => $code, 'note' => 'No onion', 'channel' => 'storefront',
            // ignored at a table: no address, no delivery charge, no location
            'delivery' => 'delivery', 'location' => ['lat' => 23.8, 'lng' => 90.4],
        ]);
        $r->assertStatus(201);
        $d = $this->body($r)['data'];
        $this->assertSame('open', $d['status']);
        $this->assertSame(0.0, (float) $d['shipping']);
        $o = db_connect()->table('orders')->where('web_ref', $d['reference'])->get()->getRowArray();
        $this->assertSame('Table 7', $o['table_ref']);
        $this->assertSame('Dine In', $o['order_type']);
        $this->assertSame('web', $o['channel']);
        $this->assertNull($o['ship_status']);
        $this->assertNull($o['geo_lat']);
        $this->assertNull($o['acknowledged_at'], 'new for the Tables badge in the POS');
        $this->assertSame((int) $f->businessId, (int) $o['business_id']);
    }

    public function testBadTableCodeIsRefused(): void
    {
        $f = $this->shop();
        $other = $this->shop();
        $foreign = $this->table($other);
        foreach ([$foreign, str_repeat('0', 24), 'not-a-code'] as $code) {
            $r = $this->storefront($f, 'POST', 'v1/orders', ['items' => [['product_id' => $f->products['charger'], 'qty' => 1]], 'payment' => 'cod', 'table' => $code]);
            $this->assertError($r, 422, 'validation_failed');
            $this->assertArrayHasKey('table', $this->body($r)['error']['details']);
        }
        $this->assertSame(0, db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults());
    }
}
