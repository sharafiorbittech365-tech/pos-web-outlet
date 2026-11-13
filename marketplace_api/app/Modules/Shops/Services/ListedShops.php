<?php

declare(strict_types=1);

namespace App\Modules\Shops\Services;

use App\Libraries\Geo;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Pricing\TaxPolicy;

/**
 * The set of shops on the marketplace right now (cached 60 s).
 *
 * A shop (outlet) is listed when its site opted in (sites.marketplace_listed = 1, mode not off),
 * the outlet and the account are active, and ModuleAccess says the subscription is not locked
 * and the modules "website" and "marketplace" are on. ModuleAccess is the only source of truth
 * for plans, add-ons and Super Admin switches; "bookings" decides whether services can be booked.
 */
final class ListedShops
{
    public const CACHE_KEY = 'mk_listed_shops';
    public const TTL = 60;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $memo = null;

    /** @return array<int, array<string, mixed>> business id => shop */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $rows = cache(self::CACHE_KEY);
        if (! is_array($rows)) {
            $rows = $this->build();
            cache()->save(self::CACHE_KEY, $rows, self::TTL);
        }

        return $this->memo = $rows;
    }

    public function flush(): void
    {
        $this->memo = null;
        cache()->delete(self::CACHE_KEY);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, mixed>|null */
    public function get(int $businessId): ?array
    {
        return $this->all()[$businessId] ?? null;
    }

    /** @return array<string, mixed> */
    public function getOrFail(int $businessId): array
    {
        return $this->get($businessId) ?? throw new NotFoundException('This shop is not on the marketplace.');
    }

    /** @return array<string, mixed>|null */
    public function bySlug(string $slug): ?array
    {
        foreach ($this->all() as $s) {
            if ($s['site_slug'] === $slug || $s['slug'] === $slug) {
                return $s;
            }
        }

        return null;
    }

    /**
     * The shop as seen from a location: distance, and whether it delivers there.
     *
     * @return array<string, mixed>
     */
    public static function at(array $shop, ?array $loc): array
    {
        $shop['distance_km'] = $loc !== null ? Geo::distanceKm((float) $loc['lat'], (float) $loc['lng'], $shop['lat'], $shop['lng']) : null;
        $radius = $shop['delivery_radius_km'];
        $shop['delivers_here'] = $shop['delivery_on'] && ($shop['distance_km'] === null || $radius === null || $shop['distance_km'] <= $radius);

        return $shop;
    }

    /**
     * Nearest listed shops (the "Shops near you" strip). Without a location: all, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function nearby(?array $loc, float $radiusKm = 0, int $limit = 12): array
    {
        $out = [];
        foreach ($this->all() as $s) {
            $s = self::at($s, $loc);
            if ($loc !== null && $radiusKm > 0 && ($s['distance_km'] === null || $s['distance_km'] > $radiusKm)) {
                continue;
            }
            $out[] = $s;
        }
        usort($out, static fn (array $a, array $b): int => ($a['distance_km'] ?? 1e9) <=> ($b['distance_km'] ?? 1e9) ?: strcmp($a['name'], $b['name']));

        return array_slice($out, 0, $limit);
    }

    /** Ids of shops whose name or type matches a search term (search also finds a shop's whole range). */
    public function matchingName(string $q): array
    {
        $q = mb_strtolower(trim($q));
        if (mb_strlen($q) < 3) {
            return [];
        }
        $ids = [];
        foreach ($this->all() as $id => $s) {
            if (str_contains(mb_strtolower($s['name'] . ' ' . $s['type_name']), $q)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<int, array<string, mixed>> */
    private function build(): array
    {
        $db = db_connect();
        $rows = $db->table('sites s')
            ->select('b.id, b.tenant_id, b.business_type, b.name, b.slug, b.phone, b.address, b.area, b.city, b.lat, b.lng, b.delivery_km,
                b.currency_code, b.currency_symbol, b.currency_decimals, b.tax_label, b.tax_rate, b.tax_inclusive, b.tax_rates, b.stock_enabled, b.settings,
                s.slug AS site_slug, s.mode, s.checkout, s.brand, s.marketplace_radius_km, bt.name AS type_name, bt.icon AS type_icon, bt.store_kind, bt.booking_kind')
            ->join('businesses b', 'b.id = s.business_id')
            ->join('tenants t', 't.id = b.tenant_id')
            ->join('business_types bt', 'bt.code = b.business_type')
            ->where('s.marketplace_listed', 1)->where('s.mode !=', 'off')
            ->where('b.is_active', 1)->where('t.deleted_at', null)
            ->orderBy('b.name')
            ->get()->getResultArray();

        $access = service('moduleAccess');
        $out = [];
        foreach ($rows as $r) {
            $acc = $access->forTenant((int) $r['tenant_id']);   // cached per tenant by ModuleAccess
            if ($acc->isLocked() || ! $acc->has('website') || ! $acc->has('marketplace')) {
                continue;
            }
            // bookings: the module must be on (ModuleAccess) and the business type must take bookings
            $out[(int) $r['id']] = self::shape($r, $acc->has('bookings') && $r['booking_kind'] !== null);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function shape(array $r, bool $bookings): array
    {
        $settings = json_decode((string) ($r['settings'] ?? '{}'), true) ?: [];
        $checkout = json_decode((string) ($r['checkout'] ?? '{}'), true) ?: [];
        $brand = json_decode((string) ($r['brand'] ?? '{}'), true) ?: [];
        $siteRadius = (float) $r['marketplace_radius_km'];
        $deliveryKm = $r['delivery_km'] !== null ? (float) $r['delivery_km'] : null;
        $palette = ['#5B5BF6', '#FF7A59', '#16A34A', '#0EA5E9', '#D946EF', '#F59E0B', '#14B8A6', '#E5484D'];
        $timed = in_array($r['booking_kind'], ['appointment', 'room', 'table', 'visit', 'pickup', 'repair'], true);

        return [
            'id' => (int) $r['id'], 'tenant_id' => (int) $r['tenant_id'], 'type' => $r['business_type'], 'type_name' => $r['type_name'], 'type_icon' => $r['type_icon'],
            'store_kind' => $r['store_kind'], 'booking_kind' => $r['booking_kind'] ?: 'appointment', 'booking_timed' => $r['booking_kind'] === null || $timed,
            'name' => $r['name'], 'slug' => $r['slug'], 'site_slug' => $r['site_slug'], 'mode' => $r['mode'], 'phone' => $r['phone'], 'address' => $r['address'],
            // the shop's own website (hosted storefront)
            'storefront_url' => $r['mode'] === 'storefront' ? config(\OmniPOS\Core\Config\OmniPOS::class)->storefrontUrl . '?site=' . rawurlencode((string) $r['site_slug']) : null,
            'area' => $r['area'], 'city' => $r['city'],
            'lat' => $r['lat'] !== null ? (float) $r['lat'] : null, 'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
            // delivery radius: the site's marketplace radius when set, else the outlet's delivery_km (null = no limit)
            'delivery_radius_km' => $siteRadius > 0 ? $siteRadius : $deliveryKm,
            'delivery_on' => array_key_exists('delivery', $checkout) ? $checkout['delivery'] !== false : true,
            'delivery_charge' => is_numeric($checkout['delivery'] ?? null) ? (float) $checkout['delivery'] : 0.0,
            'free_above' => is_numeric($checkout['freeAbove'] ?? null) && (float) $checkout['freeAbove'] > 0 ? (float) $checkout['freeAbove'] : null,
            'pickup' => (bool) ($checkout['pickup'] ?? true), 'cod' => (bool) ($checkout['cod'] ?? true), 'bkash' => (bool) ($checkout['bkash'] ?? false),
            'currency' => ['code' => $r['currency_code'], 'symbol' => $r['currency_symbol'], 'decimals' => (int) $r['currency_decimals']],
            'tax' => ['tax_label' => $r['tax_label'], 'tax_rate' => (float) $r['tax_rate'], 'tax_inclusive' => (int) $r['tax_inclusive'], 'tax_rates' => $r['tax_rates']],
            'stock_enabled' => (bool) $r['stock_enabled'], 'takes_bookings' => $bookings,
            'is_service_shop' => in_array($r['store_kind'], ['service', 'booking'], true),
            'logo_icon' => (string) ($settings['logoIcon'] ?? $r['type_icon']),
            'hero' => (string) ($brand['hero'] ?? ''), 'sub' => (string) ($brand['sub'] ?? ''),
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($brand['color'] ?? '')) ? $brand['color'] : $palette[crc32((string) $r['business_type']) % count($palette)],
        ];
    }

    public static function taxPolicy(array $shop): TaxPolicy
    {
        return TaxPolicy::fromBusiness($shop['tax']);
    }
}
