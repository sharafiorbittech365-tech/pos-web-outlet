<?php

declare(strict_types=1);

namespace App\Modules\Location\Services;

use App\Libraries\Geo;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * The visitor's location. The website keeps it (in a 30-day cookie) and sends it with every
 * request; this API only resolves and validates it — the value is client data, so it is cleaned
 * on every read.
 *
 * Shape: {lat: float, lng: float, area: string, city: string, source: gps|manual|nearest|address|account}
 */
final class LocationStore
{
    /** From a browser position: names the nearest known area when it is close enough. */
    public function fromCoordinates(float $lat, float $lng): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new ValidationException('That location is not valid.', ['lat' => 'Invalid coordinates.']);
        }
        $n = Geo::nearestArea($lat, $lng);

        return ['lat' => round($lat, 5), 'lng' => round($lng, 5), 'area' => $n !== null && $n['km'] < 25 ? $n['area'] : '', 'city' => $n !== null && $n['km'] < 60 ? $n['city'] : '', 'source' => 'gps'];
    }

    /** From a picked area (or city) of the offline list. */
    public function fromArea(string $area): array
    {
        $loc = Geo::byName($area);
        if ($loc === null) {
            throw new ValidationException('Choose an area from the list.', ['area' => 'Unknown area.']);
        }

        return $loc;
    }

    /** A signed-in buyer keeps the last location on the account. */
    public function rememberFor(int $buyerId, array $loc): void
    {
        if ($buyerId <= 0) {
            return;
        }
        db_connect()->table('marketplace_buyers')->where('id', $buyerId)
            ->update(['lat' => $loc['lat'], 'lng' => $loc['lng'], 'area' => $loc['area'] !== '' ? $loc['area'] : null, 'city' => $loc['city'] !== '' ? $loc['city'] : null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /** @return array{lat: float, lng: float, area: string, city: string, source: string}|null */
    public static function clean(array $loc): ?array
    {
        if (! isset($loc['lat'], $loc['lng']) || ! is_numeric($loc['lat']) || ! is_numeric($loc['lng'])) {
            return null;
        }
        $lat = (float) $loc['lat'];
        $lng = (float) $loc['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        $txt = static fn ($v): string => mb_substr(trim(strip_tags((string) ($v ?? ''))), 0, 64);
        $source = in_array($loc['source'] ?? '', ['gps', 'manual', 'nearest', 'address', 'account'], true) ? $loc['source'] : 'manual';

        return ['lat' => round($lat, 5), 'lng' => round($lng, 5), 'area' => $txt($loc['area'] ?? ''), 'city' => $txt($loc['city'] ?? ''), 'source' => $source];
    }
}
