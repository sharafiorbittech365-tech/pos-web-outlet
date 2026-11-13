<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use OmniPOS\Core\Services\LocationCatalog;

/**
 * The customer's delivery location on an order page: the map pin (geo_lat / geo_lng, captured at
 * checkout with the customer's permission) and the country / city / area picked from the location
 * catalogue, with links to open the pin in Google Maps and OpenStreetMap.
 */
final class OrderLocation
{
    /**
     * @param array<string, mixed> $o order row (OrderService::find)
     * @return ?array{lat: ?float, lng: ?float, area_text: ?string, country: ?string, city: ?string, city_kind: ?string, area: ?string, area_kind: ?string, postal_code: ?string, google: ?string, osm: ?string}
     *         null when the order has neither a pin nor a catalogue address
     */
    public static function for(array $o): ?array
    {
        $lat = isset($o['geo_lat']) && $o['geo_lat'] !== null ? (float) $o['geo_lat'] : null;
        $lng = isset($o['geo_lng']) && $o['geo_lng'] !== null ? (float) $o['geo_lng'] : null;
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            $lat = $lng = null;
        }
        $country = isset($o['country_code']) && $o['country_code'] !== null ? (string) $o['country_code'] : null;
        $cityId = isset($o['city_id']) && $o['city_id'] !== null ? (int) $o['city_id'] : null;
        $areaId = isset($o['area_id']) && $o['area_id'] !== null ? (int) $o['area_id'] : null;
        if ($lat === null && $country === null && $cityId === null && $areaId === null) {
            return null;
        }
        $names = (new LocationCatalog())->names($country, $cityId, $areaId);
        $q = $lat !== null ? sprintf('%.6F,%.6F', $lat, $lng) : null;

        return [
            'lat' => $lat, 'lng' => $lng, 'area_text' => $o['geo_area'] ?? null,
            'google' => $q !== null ? 'https://www.google.com/maps/search/?api=1&query=' . $q : null,
            'osm' => $q !== null ? sprintf('https://www.openstreetmap.org/?mlat=%.6F&mlon=%.6F#map=17/%.6F/%.6F', $lat, $lng, $lat, $lng) : null,
        ] + $names;
    }
}
