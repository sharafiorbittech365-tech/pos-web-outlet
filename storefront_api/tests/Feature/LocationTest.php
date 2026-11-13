<?php

declare(strict_types=1);

namespace Tests\Feature;

use OmniPOS\Core\Services\LocationImporter;
use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * Location catalogue (GET /v1/locations/*) and the address / map-pin checks on POST /v1/orders.
 * The dataset is a made-up test fixture (country "ZZ", a user-assigned ISO code), not real data.
 *
 * @internal
 */
final class LocationTest extends ApiTestCase
{
    /** @return array{country: string, alpha: int, beta: int, north: int, south: int, east: int} */
    private function dataset(): array
    {
        $db = db_connect();
        if ($db->table('countries')->where('code', 'ZZ')->countAllResults() === 0) {
            (new LocationImporter())->import(['code' => 'ZZ', 'name' => 'Testland', 'city_label' => 'District', 'area_label' => 'Thana'], [
                ['city' => 'Alpha', 'city_kind' => 'district', 'area' => 'North Ward', 'area_kind' => 'thana'],
                ['city' => 'Alpha', 'area' => 'South Ward', 'area_kind' => 'thana'],
                ['city' => 'Beta', 'city_kind' => 'district', 'area' => 'East Ward'],
            ]);
        }
        $city = static fn (string $n): int => (int) $db->table('cities')->where('country_code', 'ZZ')->where('name', $n)->get()->getRow('id');
        $area = static fn (string $n): int => (int) $db->table('areas')->where('name', $n)->get()->getRow('id');
        cache()->clean();

        return ['country' => 'ZZ', 'alpha' => $city('Alpha'), 'beta' => $city('Beta'), 'north' => $area('North Ward'), 'south' => $area('South Ward'), 'east' => $area('East Ward')];
    }

    /** @param array<string, mixed> $extra */
    private function order(Fixture $f, array $customer = [], array $extra = []): \CodeIgniter\Test\TestResponse
    {
        return $this->storefront($f, 'POST', 'v1/orders', $extra + [
            'items' => [['product_id' => $f->products['charger'], 'qty' => 1]],
            'customer' => $customer + ['name' => 'Rafi', 'phone' => '01711000000', 'address' => 'House 5, Road 2'],
            'payment' => 'cod', 'delivery' => 'delivery',
        ]);
    }

    public function testCatalogueEndpointsArePublicAndCached(): void
    {
        $ds = $this->dataset();
        $r = $this->api('GET', 'v1/locations/countries');
        $r->assertStatus(200);
        $zz = array_values(array_filter($this->body($r)['data'], static fn ($c) => $c['code'] === 'ZZ'))[0] ?? null;
        $this->assertNotNull($zz);
        $this->assertTrue($zz['has_dataset']);
        $this->assertSame('District', $zz['city_label']);
        $this->assertStringContainsString('max-age=3600', $r->response()->getHeaderLine('Cache-Control'));
        $etag = $r->response()->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);
        $this->assertSame(304, $this->api('GET', 'v1/locations/countries', ['If-None-Match' => $etag])->response()->getStatusCode());

        $r = $this->api('GET', 'v1/locations/cities?country=zz');
        $r->assertStatus(200);
        $this->assertSame(['Alpha', 'Beta'], array_column($this->body($r)['data'], 'name'));
        $this->assertSame('district', $this->body($r)['data'][0]['kind']);
        $this->assertSame(2, $this->body($r)['data'][0]['areas']);

        $r = $this->api('GET', 'v1/locations/areas?city=' . $ds['alpha']);
        $this->assertSame(['North Ward', 'South Ward'], array_column($this->body($r)['data'], 'name'));

        $this->assertError($this->api('GET', 'v1/locations/cities'), 422, 'validation_failed');
        $this->assertError($this->api('GET', 'v1/locations/cities?country=XX'), 404, 'not_found');
        $this->assertError($this->api('GET', 'v1/locations/areas?city=abc'), 422, 'validation_failed');
        $this->assertError($this->api('GET', 'v1/locations/areas?city=999999'), 404, 'not_found');

        // a disabled area disappears from the picker
        db_connect()->table('areas')->where('id', $ds['south'])->update(['is_active' => 0]);
        cache()->clean();
        $r = $this->api('GET', 'v1/locations/areas?city=' . $ds['alpha']);
        $this->assertSame(['North Ward'], array_column($this->body($r)['data'], 'name'));
        db_connect()->table('areas')->where('id', $ds['south'])->update(['is_active' => 1]);
    }

    public function testOrderStoresTheCheckedAddressAndPin(): void
    {
        $ds = $this->dataset();
        $f = Fixture::create();
        // only the area is sent: city and country are filled in from it
        $r = $this->order($f, ['area_id' => $ds['north']], ['location' => ['lat' => '23.810332', 'lng' => 90.4125181]]);
        $r->assertStatus(201);
        $o = db_connect()->table('orders')->where('web_ref', $this->body($r)['data']['reference'])->get()->getRowArray();
        $this->assertSame('ZZ', $o['country_code']);
        $this->assertSame($ds['alpha'], (int) $o['city_id']);
        $this->assertSame($ds['north'], (int) $o['area_id']);
        $this->assertSame('House 5, Road 2, North Ward, Alpha', $o['ship_address']);
        $this->assertSame(23.810332, (float) $o['geo_lat']);
        $this->assertSame(90.412518, (float) $o['geo_lng']);
        $this->assertSame('North Ward', $o['geo_area']);

        // free-text address, no catalogue ids: still accepted, nothing linked
        $r = $this->order($f, ['country_code' => '', 'city_id' => null]);
        $r->assertStatus(201);
        $o = db_connect()->table('orders')->where('web_ref', $this->body($r)['data']['reference'])->get()->getRowArray();
        $this->assertNull($o['country_code']);
        $this->assertNull($o['city_id']);
        $this->assertNull($o['geo_lat']);
    }

    public function testBadRelationsAreRefused(): void
    {
        $ds = $this->dataset();
        $f = Fixture::create();
        $cases = [
            'customer.area_id' => ['country_code' => 'ZZ', 'city_id' => $ds['beta'], 'area_id' => $ds['north']],   // area of another city
            'customer.city_id' => ['country_code' => 'BD', 'city_id' => $ds['alpha']],                              // city of another country
            'customer.country_code' => ['country_code' => 'Z1'],
        ];
        foreach ($cases as $field => $customer) {
            $r = $this->order($f, $customer);
            $this->assertError($r, 422, 'validation_failed');
            $this->assertArrayHasKey($field, $this->body($r)['error']['details'], (string) $r->response()->getBody());
        }
        foreach ([['area_id' => 99999999], ['city_id' => 'abc'], ['city_id' => -3]] as $customer) {
            $r = $this->order($f, $customer);
            $this->assertError($r, 422, 'validation_failed');
        }

        // a disabled city cannot be picked any more
        db_connect()->table('cities')->where('id', $ds['beta'])->update(['is_active' => 0]);
        $r = $this->order($f, ['city_id' => $ds['beta']]);
        $this->assertArrayHasKey('customer.city_id', $this->body($r)['error']['details'] ?? []);
        db_connect()->table('cities')->where('id', $ds['beta'])->update(['is_active' => 1]);

        $this->assertSame(0, db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults());
    }

    public function testBadCoordinatesAreRefused(): void
    {
        $f = Fixture::create();
        $bad = [
            ['lat' => 91, 'lng' => 90],
            ['lat' => 23.8, 'lng' => -180.5],
            ['lat' => 23.8],                                // only one
            ['lat' => '', 'lng' => '90.4'],
            ['lat' => 'abc', 'lng' => '90.4'],
            ['lat' => '1e1', 'lng' => '90.4'],
            ['lat' => '23.8103321234567', 'lng' => '90.4'], // absurd precision
            ['lat' => 0, 'lng' => 0],                       // failed GPS fix
            ['lat' => [1], 'lng' => '90.4'],
        ];
        foreach ($bad as $loc) {
            $r = $this->order($f, [], ['location' => $loc]);
            $this->assertError($r, 422, 'validation_failed');
            $this->assertArrayHasKey('location', $this->body($r)['error']['details'], json_encode($loc));
        }
        $this->assertError($this->order($f, [], ['location' => 'here']), 422, 'validation_failed');
        // no pin at all is fine
        $this->order($f, [], ['location' => ['lat' => null, 'lng' => null]])->assertStatus(201);
        $this->assertSame(1, db_connect()->table('orders')->where('business_id', $f->businessId)->countAllResults());
    }
}
