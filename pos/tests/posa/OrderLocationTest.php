<?php

declare(strict_types=1);

use App\Modules\Orders\Services\OrderLocation;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Services\LocationImporter;
use Tests\Support\PosA\TestCase;

/**
 * Order page: the customer's location card (city / area names, map pin, Google Maps link).
 * The location data is a made-up test fixture (country "ZZ").
 *
 * @internal
 */
final class OrderLocationTest extends TestCase
{
    private function sale(): array
    {
        $p = $this->product();

        return (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'cash', 'amount' => 99999]], 'tok-loc-' . bin2hex(random_bytes(4)))['order'];
    }

    /** @return array{city: int, area: int} */
    private function dataset(): array
    {
        $db = db_connect();
        if ($db->table('countries')->where('code', 'ZZ')->countAllResults() === 0) {
            (new LocationImporter())->import(['code' => 'ZZ', 'name' => 'Testland'], [
                ['city' => 'Alpha', 'city_kind' => 'district', 'area' => 'North Ward', 'area_kind' => 'thana', 'postal_code' => '1200'],
            ]);
        }

        return [
            'city' => (int) $db->table('cities')->where('country_code', 'ZZ')->where('name', 'Alpha')->get()->getRow('id'),
            'area' => (int) $db->table('areas')->where('name', 'North Ward')->get()->getRow('id'),
        ];
    }

    public function testOrderPageShowsTheCustomerLocationMap(): void
    {
        $ds = $this->dataset();
        $o = $this->sale();
        db_connect()->table('orders')->where('id', $o['id'])->update([
            'channel' => 'web', 'ship_status' => 'ordered', 'ship_address' => 'House 5, Road 2, North Ward, Alpha',
            'geo_lat' => 23.810332, 'geo_lng' => 90.412518, 'country_code' => 'ZZ', 'city_id' => $ds['city'], 'area_id' => $ds['area'],
        ]);

        $r = $this->getPage('orders/' . $o['id']);
        $r->assertOK();
        $html = (string) $r->response()->getBody();
        $this->assertStringContainsString('data-order-map', $html);
        $this->assertStringContainsString('data-lat="23.810332"', $html);
        $this->assertStringContainsString('Open in Google Maps', $html);
        $this->assertStringContainsString('https://www.google.com/maps/search/?api=1&query=23.810332,90.412518', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('order-map.js', $html);
        $this->assertStringContainsString('Testland', $html);
        $this->assertStringContainsString('District', $html);
        $this->assertStringContainsString('Thana', $html);
        $this->assertStringContainsString('North Ward', $html);
    }

    public function testNoLocationNoCard(): void
    {
        $o = $this->sale();
        $html = (string) $this->getPage('orders/' . $o['id'])->response()->getBody();
        $this->assertStringNotContainsString('data-order-location', $html);
        $this->assertNull(OrderLocation::for(['geo_lat' => null, 'geo_lng' => null]));

        // names only (no pin): card without a map
        $ds = $this->dataset();
        $p = OrderLocation::for(['geo_lat' => null, 'geo_lng' => null, 'country_code' => 'ZZ', 'city_id' => $ds['city'], 'area_id' => null]);
        $this->assertSame('Alpha', $p['city']);
        $this->assertNull($p['google']);
    }
}
