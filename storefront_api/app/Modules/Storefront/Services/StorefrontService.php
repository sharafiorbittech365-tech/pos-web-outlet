<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Services;

use App\Libraries\ApiSite;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Catalog\Services\ProductPresenter;
use OmniPOS\Core\Pricing\TaxPolicy;

/** Shop profile and site settings for rendering a storefront (GET /v1/storefront). */
final class StorefrontService
{
    /** Themes the storefront ships with; a site without a valid choice gets its business type's default. */
    public const THEMES = ['fresh', 'bold', 'minimal', 'luxe', 'bistro', 'care', 'studio', 'neon', 'breeze', 'rose'];

    /** Category-specific default theme per business type. */
    public const TYPE_THEMES = [
        'grocery' => 'fresh', 'bakery' => 'bistro', 'restaurant' => 'bistro', 'cafe' => 'bistro', 'fashion' => 'minimal', 'footwear' => 'minimal', 'furniture' => 'minimal',
        'electronics' => 'bold', 'accessories' => 'bold', 'hardware' => 'bold', 'wholesale' => 'bold', 'pharmacy' => 'care', 'cosmetics' => 'rose',
        'salon' => 'luxe', 'karaoke' => 'neon', 'clinic' => 'care', 'laundry' => 'breeze', 'printing' => 'studio', 'agency' => 'studio',
        'mobile' => 'bold', 'departmental' => 'fresh', 'jewellery' => 'luxe', 'books' => 'minimal', 'sports' => 'bold', 'gifts' => 'rose', 'paint' => 'bold', 'electrical' => 'bold', 'pet' => 'fresh', 'autoparts' => 'bold', 'sanitary' => 'bold', 'optical' => 'care', 'agro' => 'fresh', 'tailor' => 'luxe', 'barber' => 'luxe', 'parlour' => 'rose', 'carwash' => 'breeze', 'mobilerepair' => 'bold', 'computerrepair' => 'bold', 'photography' => 'studio', 'diagnostic' => 'care', 'travel' => 'breeze',
    ];

    public static function theme(string $chosen, string $type): string
    {
        return in_array($chosen, self::THEMES, true) ? $chosen : (self::TYPE_THEMES[$type] ?? 'fresh');
    }

    /** An absolute https:// (or http:// outside production) image URL, else null. */
    public static function httpsUrl(mixed $url): ?string
    {
        $u = trim((string) $url);
        // an uploaded picture (site_media, shared database) is served by this API: rebuild its address here,
        // whatever host the back office saved it with (a moved port or domain must not break logos and banners)
        if (preg_match('#^(?:https?://[^/]+)?/(media/\d+-[a-f0-9]{16}\.(?:png|jpg|webp|gif|ico))$#i', $u, $m) === 1) {
            $u = rtrim((string) config(\OmniPOS\Core\Config\OmniPOS::class)->apiUrl, '/') . '/' . $m[1];
        }
        $ok = $u !== '' && filter_var($u, FILTER_VALIDATE_URL) && (str_starts_with($u, 'https://') || (ENVIRONMENT !== 'production' && str_starts_with($u, 'http://')));

        return $ok ? $u : null;
    }

    public function __construct(private readonly ApiSite $site)
    {
    }

    /** @return array{business: array<string, mixed>, site: array<string, mixed>, categories: list<array<string, mixed>>} */
    public function profile(): array
    {
        $b = $this->site->business();
        $s = $this->site->site();
        $policy = TaxPolicy::fromBusiness($b);
        $bookingKind = $b['booking_kind'] !== null && $b['booking_kind'] !== '' ? (string) $b['booking_kind'] : null;
        $bookingsOn = $bookingKind !== null && service('moduleAccess')->forTenant((int) $b['tenant_id'])->has('bookings');
        $symbol = (string) $b['currency_symbol'];
        $brand = $s['brand'] + ['hero' => '', 'sub' => '', 'color' => '', 'accent' => '', 'slides' => []];
        $seo = $s['seo'] + ['title' => (string) $b['name'], 'description' => ''];
        // everything entered in POS Setup › Website, as clean fields (images are API addresses, links are checked)
        $url = static fn (mixed $u): ?string => self::httpsUrl($u);
        $social = [];
        foreach (['facebook', 'instagram', 'tiktok', 'youtube'] as $k) {
            $social[$k] = $url($brand['social'][$k] ?? null);
        }
        $pol = (array) ($brand['policy'] ?? []);
        $text = static fn (mixed $v): string => trim((string) $v);
        $contactEmail = $text($brand['contactEmail'] ?? '');

        return [
            'business' => [
                'id' => (int) $b['id'],
                'name' => (string) $b['name'],
                'type' => (string) $b['business_type'],
                'type_name' => (string) ($b['type_name'] ?? ''),
                'phone' => $b['phone'],
                'email' => $b['email'],
                'address' => $b['address'],
                'area' => $b['area'],
                'city' => $b['city'],
                'lat' => $b['lat'] !== null ? (float) $b['lat'] : null,
                'lng' => $b['lng'] !== null ? (float) $b['lng'] : null,
                'delivery_km' => $b['delivery_km'] !== null ? (float) $b['delivery_km'] : null,
                'logo_icon' => (string) ($b['settings']['logoIcon'] ?? 'store'),
                'logo_url' => self::httpsUrl($s['brand']['logo'] ?? null),
                'favicon_url' => $url($brand['favicon'] ?? null),
                'cover_url' => $url($brand['heroImg'] ?? null),
                'branch' => $text($b['settings']['branch'] ?? ''),
                'bin' => $text($b['settings']['bin'] ?? ''),
                // the colour chosen in Setup › General (the admin theme); the website's own palette is site.brand.color
                'brand_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['settings']['theme']['primary'] ?? '')) === 1 ? strtoupper((string) $b['settings']['theme']['primary']) : null,
                'currency' => [
                    'code' => (string) $b['currency_code'],
                    'symbol' => $symbol,
                    'decimals' => (int) $b['currency_decimals'],
                    'position' => (string) ($b['settings']['currencyPosition'] ?? 'before'),
                ],
                'tax' => ['label' => $policy->label, 'rate' => ProductPresenter::num($policy->defaultRate), 'inclusive' => $policy->inclusive],
                // the business's words (Products / Services / Medicines, Stylist / Doctor …), BusinessTypeSetup::LABELS
                'labels' => \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) $b['business_type'], (array) ($b['settings']['labels'] ?? [])),
            ],
            'site' => [
                'slug' => (string) $s['slug'],
                'mode' => (string) $s['mode'],
                'theme' => self::theme((string) $s['theme'], (string) $b['business_type']),
                'theme_default' => self::TYPE_THEMES[(string) $b['business_type']] ?? 'fresh',
                'brand' => $brand,
                // Setup › Website, one field each (also inside brand, as stored)
                'about' => $text($brand['about'] ?? ''),
                'tagline' => $text($brand['sub'] ?? ''),
                'footer' => $text($brand['footer'] ?? ''),
                'contact' => [
                    'phone' => $text($b['phone'] ?? ''), 'email' => $contactEmail !== '' ? $contactEmail : $text($b['email'] ?? ''),
                    'whatsapp' => preg_replace('/\D+/', '', (string) ($brand['whatsapp'] ?? '')) ?: null,
                    'hours_text' => $text($brand['hoursText'] ?? ''), 'map_url' => $url($brand['mapUrl'] ?? null),
                ],
                'social' => $social,
                'policies' => ['delivery' => $text($pol['delivery'] ?? ''), 'returns' => $text($pol['returns'] ?? ''), 'privacy' => $text($pol['privacy'] ?? ''), 'terms' => $text($pol['terms'] ?? '')],
                'terms' => $text($b['settings']['invoicePage']['terms'] ?? ($b['settings']['terms'] ?? '')),
                'assets' => ['logo' => $url($brand['logo'] ?? null), 'favicon' => $url($brand['favicon'] ?? null), 'cover' => $url($brand['heroImg'] ?? null)],
                'sections' => (object) $s['sections'],
                'checkout' => $this->site->checkout(),
                'seo' => $seo,
                'pages' => $this->pages((int) $b['id']),
                'product_page' => $this->productPage((array) ($s['product_page'] ?? []), $brand, $text($b['phone'] ?? '')),
                'host' => $this->site->host(),
                'served_host' => (string) (service('apiCaller')->key()['served_host'] ?? ''),
                'connection' => (string) (service('apiCaller')->key()['via'] ?? 'key'),
                'kind' => (string) ($b['store_kind'] ?? 'shop'),
                'booking_kind' => $bookingKind,
                'bookings_enabled' => $bookingsOn,
                // clinic sites: doctors & appointments through GET /v1/doctors (module clinic)
                'clinic_enabled' => $bookingsOn && \App\Modules\Clinic\Services\ClinicDirectory::enabledFor($b),
                'staff' => self::staff($b['settings']),
                'hours' => (object) ((array) ($b['settings']['hours'] ?? [])),
            ],
            'categories' => (new CatalogService($this->site))->categories(),
        ];
    }

    /** @return list<array{slug: string, title: string, kind: string, footer: bool}> the published information pages (footer links come from here) */
    private function pages(int $businessId): array
    {
        $rows = db_connect()->table('site_pages')->select('slug, title, kind, show_in_footer')->where('business_id', $businessId)->where('is_published', 1)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();

        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'title' => (string) $r['title'], 'kind' => (string) $r['kind'], 'footer' => (bool) $r['show_in_footer']], $rows);
    }

    /**
     * One published page with its text as safe HTML.
     *
     * @return array<string, mixed>
     */
    public function page(string $slug): array
    {
        $b = $this->site->business();
        $r = db_connect()->table('site_pages')->where('business_id', (int) $b['id'])->where('slug', $slug)->where('is_published', 1)->get()->getRowArray()
            ?? throw new \OmniPOS\Core\Exceptions\NotFoundException('No such page.');

        return ['slug' => (string) $r['slug'], 'title' => (string) $r['title'], 'kind' => (string) $r['kind'], 'body_html' => \OmniPOS\Core\Services\SitePages::render((string) $r['body']),
            'seo_description' => $r['seo_description'], 'updated_at' => (string) $r['updated_at']];
    }

    /**
     * The product page settings (POS › Website › Storefront › Product page), checked: the call number falls back to the shop's
     * phone, the WhatsApp number is the one from Setup › Website, the Messenger link must be https.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $brand
     * @return array<string, mixed>
     */
    private function productPage(array $stored, array $brand, string $shopPhone): array
    {
        $p = \OmniPOS\Core\Services\ProductPageSettings::normalise($stored);
        $p['messenger'] = self::httpsUrl($p['messenger']);
        $p['callPhone'] = $p['callPhone'] !== '' ? $p['callPhone'] : $shopPhone;
        $p['whatsapp'] = $p['showWhatsapp'] ? (preg_replace('/\D+/', '', (string) ($brand['whatsapp'] ?? '')) ?: null) : null;

        return $p;
    }

    /** @return list<string> names of staff a customer can book (same source as the core BookingService) */
    public static function staff(array $settings): array
    {
        $list = (array) (! empty($settings['serviceStaff']) ? $settings['serviceStaff'] : ($settings['staff'] ?? []));

        return array_values(array_filter(array_map(static fn ($x): string => is_array($x) ? (string) ($x['name'] ?? '') : (string) $x, $list), static fn (string $n): bool => $n !== ''));
    }
}
