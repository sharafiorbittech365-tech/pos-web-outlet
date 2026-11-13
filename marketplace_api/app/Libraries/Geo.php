<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Geo as GeoConfig;

/**
 * Location helpers (PHP port of omnipos-geo.js): Haversine distance, nearest known area,
 * area found in an address, area by name, and the bounding box used to prefilter SQL.
 */
final class Geo
{
    public const EARTH_KM = 6371.0;
    /** km per degree of latitude */
    public const KM_PER_DEG = 111.045;

    private static function cfg(): GeoConfig
    {
        return config(GeoConfig::class);
    }

    /** Straight-line distance in km, rounded to 0.1 km (null when a point is missing). */
    public static function distanceKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(2 * self::EARTH_KM * asin(min(1.0, sqrt($h))), 1);
    }

    /**
     * Bounding box around a point (prefilter for an indexed lat/lng range scan).
     *
     * @return array{minLat: float, maxLat: float, minLng: float, maxLng: float}
     */
    public static function box(float $lat, float $lng, float $radiusKm): array
    {
        $dLat = $radiusKm / self::KM_PER_DEG;
        $dLng = $radiusKm / (self::KM_PER_DEG * max(0.01, cos(deg2rad($lat))));

        return ['minLat' => $lat - $dLat, 'maxLat' => $lat + $dLat, 'minLng' => $lng - $dLng, 'maxLng' => $lng + $dLng];
    }

    /** @param array{0: string, 1: string, 2: float, 3: float} $x */
    private static function toLoc(array $x, string $source): array
    {
        return ['area' => $x[0], 'city' => $x[1], 'lat' => $x[2], 'lng' => $x[3], 'source' => $source];
    }

    /** @return array{area: string, city: string, lat: float, lng: float, source: string, km: float}|null */
    public static function nearestArea(float $lat, float $lng): ?array
    {
        $best = null;
        $bd = INF;
        foreach (self::cfg()->areas as $x) {
            $d = self::distanceKm($lat, $lng, $x[2], $x[3]);
            if ($d < $bd) {
                $bd = $d;
                $best = $x;
            }
        }

        return $best ? self::toLoc($best, 'nearest') + ['km' => $bd] : null;
    }

    public static function byName(string $name): ?array
    {
        $name = mb_strtolower(trim($name));
        foreach (self::cfg()->areas as $x) {
            if (mb_strtolower($x[0]) === $name) {
                return self::toLoc($x, 'manual');
            }
        }
        foreach (self::cfg()->cities as $city => [$lat, $lng]) {
            if (mb_strtolower($city) === $name) {
                return ['area' => '', 'city' => $city, 'lat' => $lat, 'lng' => $lng, 'source' => 'manual'];
            }
        }

        return null;
    }

    /** Area (or city centre) named in a free-text address. */
    public static function fromText(string $text): ?array
    {
        $t = mb_strtolower($text);
        if (trim($t) === '') {
            return null;
        }
        $city = null;
        foreach (array_keys(self::cfg()->cities) as $c) {
            if (str_contains($t, mb_strtolower($c)) || ($c === 'Chattogram' && preg_match('/chittagong|\bctg\b/', $t))) {
                $city = $c;
                break;
            }
        }
        $hits = array_values(array_filter(self::cfg()->areas, static fn (array $x): bool => str_contains($t, mb_strtolower((string) preg_replace('/\s*\(.*\)/', '', $x[0])))));
        usort($hits, static fn (array $a, array $b): int => ($city !== null ? (int) ($b[1] === $city) - (int) ($a[1] === $city) : 0) ?: mb_strlen($b[0]) - mb_strlen($a[0]));
        if ($hits !== []) {
            return self::toLoc($hits[0], 'address');
        }

        return $city !== null ? ['area' => '', 'city' => $city, 'lat' => self::cfg()->cities[$city][0], 'lng' => self::cfg()->cities[$city][1], 'source' => 'address'] : null;
    }

    /** @return list<string> */
    public static function cities(): array
    {
        return array_values(array_unique(array_map(static fn (array $x): string => $x[1], self::cfg()->areas)));
    }

    /** @return list<array{area: string, city: string, lat: float, lng: float, source: string}> */
    public static function areasIn(?string $city = null): array
    {
        $out = [];
        foreach (self::cfg()->areas as $x) {
            if ($city === null || $x[1] === $city) {
                $out[] = self::toLoc($x, 'manual');
            }
        }

        return $out;
    }

    public static function label(?array $loc): string
    {
        if ($loc === null) {
            return 'Choose your location';
        }
        $parts = array_filter([(string) ($loc['area'] ?? ''), (string) ($loc['city'] ?? '')], static fn (string $s): bool => $s !== '');

        return $parts !== [] ? implode(', ', $parts) : sprintf('%.3f, %.3f', (float) $loc['lat'], (float) $loc['lng']);
    }
}
