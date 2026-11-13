<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Services;

use App\Modules\Catalog\Services\ProductPresenter;
use App\Modules\Storefront\Services\StorefrontService;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Pricing\TaxPolicy;
use OmniPOS\Core\Services\MultisiteKeys;
use RuntimeException;

/**
 * The multi-outlet website of the current request and the outlets it may reach.
 *
 * "Authorised" = a multisite_outlets row of this website and tenant that is switched on, for an
 * active outlet of the same tenant. Every catalogue, stock and order query of the multisite
 * endpoints is limited to outletIds() — the client can only ever pick among those.
 */
final class MultisiteDirectory
{
    /** @var array<string, mixed>|null */
    private ?array $ms = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $outlets = null;

    /**
     * The multisites row with the id of the home outlet's site (created when missing).
     *
     * @return array<string, mixed>
     */
    public static function load(int $id): array
    {
        $db = db_connect();
        $ms = $db->table('multisites m')->select('m.*, s.id AS home_site_id')
            ->join('sites s', 's.business_id = m.home_business_id AND s.tenant_id = m.tenant_id', 'left')
            ->where('m.id', $id)->get()->getRowArray() ?? throw new NotFoundException('Website not found.');
        if ($ms['home_site_id'] === null) {
            $ms['home_site_id'] = MultisiteKeys::ensureSite((int) $ms['tenant_id'], (int) $ms['home_business_id']);
        }

        return $ms;
    }

    /** @param array<string, mixed> $ms */
    public function set(array $ms): void
    {
        $this->ms = $ms;
        $this->outlets = null;
    }

    /** @return array<string, mixed> */
    public function website(): array
    {
        return $this->ms ?? throw new RuntimeException('No multi-outlet website for this request (is the "multisite" filter on the route?).');
    }

    public function tenantId(): int
    {
        return (int) $this->website()['tenant_id'];
    }

    /**
     * Authorised outlets in display order: businesses row (settings decoded) + site_id + checkout.
     *
     * @return list<array<string, mixed>>
     */
    public function outlets(): array
    {
        if ($this->outlets !== null) {
            return $this->outlets;
        }
        $ms = $this->website();
        $rows = db_connect()->table('multisite_outlets mo')
            ->select('b.id, b.tenant_id, b.business_type, b.name, b.slug, b.phone, b.email, b.address, b.city, b.area, b.lat, b.lng, b.delivery_km, b.currency_code, b.currency_symbol,'
                . ' b.currency_decimals, b.tax_label, b.tax_rate, b.tax_inclusive, b.tax_rates, b.stock_enabled, b.settings, bt.name AS type_name, bt.store_kind, s.id AS site_id, s.checkout, mo.sort_order')
            ->join('businesses b', 'b.id = mo.business_id AND b.tenant_id = mo.tenant_id')
            ->join('business_types bt', 'bt.code = b.business_type', 'left')
            ->join('sites s', 's.business_id = b.id AND s.tenant_id = b.tenant_id')
            ->where('mo.tenant_id', (int) $ms['tenant_id'])->where('mo.multisite_id', (int) $ms['id'])
            ->where('mo.is_enabled', 1)->where('b.is_active', 1)
            ->orderBy('mo.sort_order')->orderBy('b.name')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['site_id'] = (int) $r['site_id'];
            $r['settings'] = json_decode((string) ($r['settings'] ?? ''), true) ?: [];
            $c = json_decode((string) ($r['checkout'] ?? ''), true) ?: [];
            $r['checkout'] = [
                'cod' => (bool) ($c['cod'] ?? true), 'bkash' => (bool) ($c['bkash'] ?? false), 'card' => (bool) ($c['card'] ?? false),
                'pickup' => (bool) ($c['pickup'] ?? false), 'delivery' => (float) ($c['delivery'] ?? 0), 'freeAbove' => (float) ($c['freeAbove'] ?? 0),
            ];
        }
        unset($r);

        return $this->outlets = $rows;
    }

    /** @return list<int> */
    public function outletIds(): array
    {
        return array_map(static fn (array $o): int => $o['id'], $this->outlets());
    }

    /** @return array<string, mixed> an authorised outlet; 404 for any other id (other tenant, not opted in, inactive) */
    public function outlet(int $id): array
    {
        foreach ($this->outlets() as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }

        throw new NotFoundException('No such outlet on this website.');
    }

    /**
     * Public shape of an outlet.
     *
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    public static function present(array $o, ?int $products = null): array
    {
        $policy = TaxPolicy::fromBusiness($o);
        $hours = (array) ($o['settings']['hours'] ?? []);

        return [
            'id' => $o['id'],
            'name' => (string) $o['name'],
            'type' => (string) $o['business_type'],
            'type_name' => (string) ($o['type_name'] ?? ''),
            'phone' => $o['phone'],
            'email' => $o['email'],
            'address' => $o['address'],
            'area' => $o['area'],
            'city' => $o['city'],
            'lat' => $o['lat'] !== null ? (float) $o['lat'] : null,
            'lng' => $o['lng'] !== null ? (float) $o['lng'] : null,
            'delivery_km' => $o['delivery_km'] !== null ? (float) $o['delivery_km'] : null,
            'hours' => isset($hours['open'], $hours['close']) ? ['open' => (string) $hours['open'], 'close' => (string) $hours['close']] : null,
            'logo_icon' => (string) ($o['settings']['logoIcon'] ?? 'store'),
            'currency' => ['code' => (string) $o['currency_code'], 'symbol' => (string) $o['currency_symbol'], 'decimals' => (int) $o['currency_decimals']],
            'tax' => ['label' => $policy->label, 'rate' => ProductPresenter::num($policy->defaultRate), 'inclusive' => $policy->inclusive],
            'checkout' => $o['checkout'],
            'products' => $products,
        ];
    }

    /**
     * GET /v1/multisite: website name, brand and settings (from the home outlet's Website settings),
     * the outlets and the merged categories.
     *
     * @param list<array<string, mixed>> $categories
     * @return array<string, mixed>
     */
    public function profile(array $categories): array
    {
        $ms = $this->website();
        $db = db_connect();
        $site = $db->table('sites')->select('slug, theme, brand, seo')->where('id', (int) $ms['home_site_id'])->get()->getRowArray() ?? [];
        $home = $db->table('businesses')->select('name, business_type, currency_code, currency_symbol, currency_decimals, settings')
            ->where('id', (int) $ms['home_business_id'])->where('tenant_id', (int) $ms['tenant_id'])->get()->getRowArray() ?? [];
        $tenant = (string) ($db->table('tenants')->select('name')->where('id', (int) $ms['tenant_id'])->get()->getRow('name') ?? '');
        $brand = json_decode((string) ($site['brand'] ?? ''), true) ?: [];
        $seo = json_decode((string) ($site['seo'] ?? ''), true) ?: [];
        $settings = json_decode((string) ($home['settings'] ?? ''), true) ?: [];
        $name = trim((string) ($ms['name'] ?? '')) !== '' ? (string) $ms['name'] : ($tenant !== '' ? $tenant : (string) ($home['name'] ?? 'Our stores'));
        $type = (string) ($home['business_type'] ?? '');
        $suffix = '.' . config(\OmniPOS\Core\Config\OmniPOS::class)->multisiteHost;

        $counts = [];
        foreach ($this->outlets() as $o) {
            $counts[$o['id']] = 0;
        }
        if ($counts !== []) {
            foreach ($db->table('products')->select('business_id, COUNT(*) AS n')->where('tenant_id', (int) $ms['tenant_id'])->whereIn('business_id', array_keys($counts))
                ->where('is_active', 1)->where('is_online', 1)->where('deleted_at', null)->groupBy('business_id')->get()->getResultArray() as $c) {
                $counts[(int) $c['business_id']] = (int) $c['n'];
            }
        }

        return [
            'website' => [
                'name' => $name,
                'tagline' => trim((string) ($ms['tagline'] ?? '')) !== '' ? (string) $ms['tagline'] : (string) ($brand['sub'] ?? ''),
                'slug' => (string) $ms['slug'],
                'host' => trim((string) ($ms['domain'] ?? '')) !== '' ? (string) $ms['domain'] : $ms['slug'] . $suffix,
                'connection' => (string) (service('apiCaller')->key()['via'] ?? 'key'),
                'theme' => StorefrontService::theme((string) ($site['theme'] ?? ''), $type),
                'brand' => [
                    'color' => (string) ($brand['color'] ?? ''), 'accent' => (string) ($brand['accent'] ?? ''), 'dark' => (string) ($brand['dark'] ?? ''),
                    'hero' => (string) ($brand['hero'] ?? ''), 'sub' => (string) ($brand['sub'] ?? ''), 'announce' => (string) ($brand['announce'] ?? ''),
                    'logo_url' => StorefrontService::httpsUrl($brand['logo'] ?? null), 'hero_image' => StorefrontService::httpsUrl($brand['heroImg'] ?? null),
                    'favicon_url' => StorefrontService::httpsUrl($brand['favicon'] ?? null),
                    'logo_icon' => (string) ($settings['logoIcon'] ?? 'store'),
                ],
                'seo' => ['title' => (string) ($seo['title'] ?? ''), 'description' => (string) ($seo['description'] ?? '')],
                'currency' => ['code' => (string) ($home['currency_code'] ?? 'BDT'), 'symbol' => (string) ($home['currency_symbol'] ?? '৳'),
                    'decimals' => (int) ($home['currency_decimals'] ?? 2), 'position' => (string) ($settings['currencyPosition'] ?? 'before')],
                // the home outlet's business words (Products / Medicines / Services …), BusinessTypeSetup::LABELS
                'labels' => \OmniPOS\Core\Services\BusinessTypeSetup::terms($type, (array) ($settings['labels'] ?? [])),
            ],
            'outlets' => array_map(static fn (array $o): array => self::present($o, $counts[$o['id']] ?? 0), $this->outlets()),
            'categories' => $categories,
        ];
    }
}
