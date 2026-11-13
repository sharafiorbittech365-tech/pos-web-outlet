<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * @internal
 */
final class CatalogTest extends ApiTestCase
{
    public function testListPaginatesAndHidesOfflineAndDeletedProducts(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'GET', 'v1/products?per_page=3&sort=name');
        $r->assertStatus(200);
        $b = $this->body($r);
        $this->assertSame(['total' => 4, 'page' => 1, 'per_page' => 3, 'last_page' => 2, 'from' => 1, 'to' => 3], $b['meta']);
        $this->assertCount(3, $b['data']);

        $page2 = $this->body($this->storefront($f, 'GET', 'v1/products?per_page=3&page=2&sort=name'));
        $this->assertCount(1, $page2['data']);
        $ids = array_merge(array_column($b['data'], 'id'), array_column($page2['data'], 'id'));
        $this->assertNotContains($f->products['hidden'], $ids);
        $this->assertNotContains($f->products['deleted'], $ids);

        // per_page is capped at 100
        $this->assertSame(100, $this->body($this->storefront($f, 'GET', 'v1/products?per_page=1000'))['meta']['per_page']);
    }

    public function testPriceIsWhatTheCustomerPays(): void
    {
        $f = Fixture::create();
        $data = array_column($this->body($this->storefront($f, 'GET', 'v1/products'))['data'], null, 'id');
        $charger = $data[$f->products['charger']];
        $this->assertEqualsWithDelta(105.0, $charger['price'], 0.001);            // 100 + 5% added
        $this->assertSame(['label' => 'VAT', 'rate' => 5, 'mode' => 'exclusive'], $charger['tax']);
        $speaker = $data[$f->products['speaker']];
        $this->assertEqualsWithDelta(200.0, $speaker['price'], 0.001);            // 10% already inside
        $this->assertSame('inclusive', $speaker['tax']['mode']);
        $this->assertSame(5, $charger['stock']);
        $this->assertTrue($charger['in_stock']);
        $repair = $data[$f->products['repair']];
        $this->assertNull($repair['stock']);                                     // not stock-tracked
        $this->assertTrue($repair['in_stock']);
        $this->assertSame(60, $repair['duration_min']);
        $this->assertFalse($data[$f->products['cable']]['in_stock']);
    }

    public function testFiltersAndSort(): void
    {
        $f = Fixture::create();
        $ids = fn (string $q): array => array_column($this->body($this->storefront($f, 'GET', 'v1/products?' . $q))['data'], 'id');
        $this->assertSame([$f->products['speaker']], $ids('featured=1'));
        $this->assertNotContains($f->products['cable'], $ids('in_stock=1'));
        $this->assertSame([$f->products['charger']], $ids('q=Fast'));            // FULLTEXT (>= 3 chars)
        $this->assertSame([$f->products['charger']], $ids('q=Vo'));              // prefix LIKE on brand
        $byPrice = $ids('sort=-price');
        $this->assertSame($f->products['repair'], $byPrice[0]);
        $this->assertSame($f->products['cable'], end($byPrice));
        $this->assertError($this->storefront($f, 'GET', 'v1/products?sort=bogus'), 422, 'validation_failed');
    }

    public function testDetailAndNotFound(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'GET', 'v1/products/' . $f->products['charger']);
        $r->assertStatus(200);
        $this->assertArrayHasKey('description', $this->body($r)['data']);
        $this->assertArrayHasKey('options', $this->body($r)['data']);
        $this->assertError($this->storefront($f, 'GET', 'v1/products/' . $f->products['hidden']), 404, 'not_found');
        // another shop's product is invisible
        $other = Fixture::create();
        $this->assertError($this->storefront($f, 'GET', 'v1/products/' . $other->products['charger']), 404, 'not_found');
    }

    public function testEtagAndCacheControl(): void
    {
        $f = Fixture::create();
        $first = $this->storefront($f, 'GET', 'v1/categories');
        $first->assertStatus(200);
        $etag = $first->response()->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);
        $this->assertSame('private, max-age=45', $first->response()->getHeaderLine('Cache-Control'));
        $again = $this->storefront($f, 'GET', 'v1/categories', null, ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', (string) $again->response()->getBody());
    }

    public function testInventory(): void
    {
        $f = Fixture::create();
        $r = $this->api('GET', 'v1/inventory?product_ids=' . $f->products['charger'] . ',' . $f->products['repair'], ['Authorization' => 'Bearer ' . $f->secret]);
        $r->assertStatus(200);
        $rows = array_column($this->body($r)['data'], null, 'product_id');
        $this->assertSame(['product_id' => $f->products['charger'], 'tracked' => true, 'available' => 5, 'variants' => []], $rows[$f->products['charger']]);
        $this->assertFalse($rows[$f->products['repair']]['tracked']);
        $this->assertError($this->api('GET', 'v1/inventory?product_ids=1,x', ['Authorization' => 'Bearer ' . $f->secret]), 422, 'validation_failed');
    }
}
