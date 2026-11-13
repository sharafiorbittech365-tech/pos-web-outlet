<?php

declare(strict_types=1);

namespace App\Modules\Website\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\ApiKeyService;
use OmniPOS\Core\Services\SiteAddresses;

/**
 * The outlet's website (one sites row): mode (storefront / API / off), storefront look & checkout,
 * marketplace listing, custom domains with DNS verification, API keys (core ApiKeyService),
 * allowed origins (CORS), webhooks and their recent deliveries, cache lifetimes.
 */
final class WebsiteService
{
    public const THEMES = [
        'fresh' => ['Fresh', 'Light, rounded and friendly — grocery, cosmetics, bakery'], 'bold' => ['Bold', 'Dark header, strong colour — electronics, gadgets, hardware'],
        'minimal' => ['Minimal', 'White space and elegant type — fashion, furniture, lifestyle'], 'luxe' => ['Luxe', 'Black & gold, editorial — barber, salon, spa'],
        'bistro' => ['Bistro', 'Warm cream, food first — restaurant, café, bakery'], 'care' => ['Care', 'Clean teal, trustworthy — pharmacy, clinic, health'],
        'studio' => ['Studio', 'Bold gradient, portfolio feel — agency, printing'], 'neon' => ['Neon', 'Dark with glowing accents — karaoke, gaming, nightlife'],
        'breeze' => ['Breeze', 'Airy blue, spotless — laundry, cleaning services'], 'rose' => ['Rose', 'Soft pink & script type — beauty, cosmetics, boutique'],
    ];
    /** [id => [name, brand, accent, dark]] */
    public const PALETTES = [
        'emerald' => ['Emerald & Cream', '#059669', '#F59E0B', '#064E3B'], 'royal' => ['Royal Blue & Amber', '#2563EB', '#F59E0B', '#0F1E46'],
        'indigo' => ['Indigo & Coral', '#4F46E5', '#FB7185', '#1E1B4B'], 'midnight' => ['Midnight & Gold', '#C9A45C', '#E9D8A6', '#0E0D0B'],
        'rose' => ['Rose & Blush', '#E11D74', '#F9A8D4', '#4A1630'], 'terracotta' => ['Terracotta & Sand', '#C2410C', '#EAB308', '#3B1F12'],
        'ocean' => ['Ocean & Sky', '#0284C7', '#22D3EE', '#0C2A4A'], 'plum' => ['Plum & Peach', '#7C3AED', '#FB923C', '#2E1065'],
        'forest' => ['Forest & Lime', '#15803D', '#A3E635', '#14301F'], 'teal' => ['Teal & Mint', '#0F766E', '#34D399', '#0B3B37'],
        'cherry' => ['Cherry & Charcoal', '#DC2626', '#FBBF24', '#18181B'], 'mocha' => ['Mocha & Latte', '#92400E', '#D6A76C', '#2A1A10'],
    ];
    public const SECTIONS = ['hero' => 'Hero banner', 'categories' => 'Category tiles', 'featured' => 'Featured products', 'deals' => 'Deals & countdown', 'all' => 'All products', 'about' => 'About & contact', 'reviews' => 'Customer reviews', 'newsletter' => 'Newsletter'];
    public const CHECKOUT = ['cod' => 'Cash on delivery', 'bkash' => 'bKash / Nagad / Rocket', 'card' => 'Card (SSLCommerz)', 'pickup' => 'Store pickup'];
    public const EVENTS = ['order.created', 'order.paid', 'order.cancelled', 'product.updated', 'inventory.updated', 'invoice.created', 'booking.created'];
    /** Key prefix of the multi-outlet website credential (managed in Website › Multi-outlet, owner only). */
    private const MULTISITE_PREFIX = 'mk_live_';

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    private function access(): \OmniPOS\Core\Services\TenantAccess
    {
        return service('posSession')->access();
    }

    /** @return array<string, mixed> the outlet's site (created, mode off, on first use) with JSON decoded */
    public function site(): array
    {
        $db = $this->db();
        $row = $db->table('sites')->where('business_id', $this->bid())->get()->getRowArray();
        if ($row === null) {
            $b = service('posSession')->business();
            $slug = $this->freeSlug((string) ($b['slug'] ?? 'shop'));
            $db->table('sites')->insert(['tenant_id' => service('tenant')->tenantId(), 'business_id' => $this->bid(), 'mode' => 'off', 'slug' => $slug, 'theme' => 'fresh', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            $row = $db->table('sites')->where('business_id', $this->bid())->get()->getRowArray();
        }
        foreach (['brand', 'sections', 'checkout', 'seo', 'product_page'] as $k) {
            $row[$k] = json_decode((string) ($row[$k] ?? '{}'), true) ?: [];
        }
        $row['brand'] += ['hero' => '', 'sub' => '', 'color' => '', 'accent' => '', 'dark' => '', 'palette' => '', 'announce' => '', 'slides' => []];
        $row['sections'] += array_fill_keys(array_keys(self::SECTIONS), true);
        $row['checkout'] += ['cod' => true, 'bkash' => true, 'card' => false, 'pickup' => true, 'delivery' => 60, 'freeAbove' => 0];
        $row['seo'] += ['title' => '', 'description' => ''];
        $row['product_page'] = self::productPage($row['product_page']);

        return $row;
    }

    private function freeSlug(string $base): string
    {
        return SiteAddresses::free($base);
    }

    public function host(array $site): string
    {
        $primary = $this->db()->table('site_domains')->select('host')->where('site_id', $site['id'])->where('status', 'active')->where('is_primary', 1)->get()->getRow('host');

        return $primary ?: $site['slug'] . '.' . config(OmniPOS::class)->siteHost;
    }

    public function previewUrl(array $site): string
    {
        return rtrim(config(OmniPOS::class)->storefrontUrl, '/') . '/?site=' . rawurlencode((string) $site['slug']);
    }

    /** @return array{orders_today: int, sales_today: float, sales_7d: float, api_30d: int, keys: int, hooks: int, origins: int} */
    public function overview(array $site): array
    {
        $db = $this->db();
        $bid = $this->bid();
        $t0 = date('Y-m-d 00:00:00');
        $today = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS t FROM orders WHERE business_id = ? AND channel IN ('web','api') AND created_at >= ? AND status <> 'cancelled'", [$bid, $t0])->getRowArray() ?? [];
        $week = (float) ($db->query("SELECT COALESCE(SUM(total), 0) AS t FROM orders WHERE business_id = ? AND channel IN ('web','api') AND created_at >= ? AND status <> 'cancelled'", [$bid, date('Y-m-d 00:00:00', strtotime('-6 days'))])->getRow('t') ?? 0);
        $api = (int) ($db->query('SELECT COALESCE(SUM(u.requests), 0) AS n FROM api_usage_daily u JOIN api_keys k ON k.id = u.api_key_id WHERE k.site_id = ? AND u.day >= ?', [$site['id'], date('Y-m-d', strtotime('-29 days'))])->getRow('n') ?? 0);

        return ['orders_today' => (int) ($today['n'] ?? 0), 'sales_today' => (float) ($today['t'] ?? 0), 'sales_7d' => $week, 'api_30d' => $api,
            'keys' => $db->table('api_keys')->where('site_id', $site['id'])->where('revoked_at', null)->where('is_storefront', 0)->where('prefix <>', self::MULTISITE_PREFIX)->countAllResults(),
            'hooks' => $db->table('webhooks')->where('site_id', $site['id'])->countAllResults(),
            'origins' => $db->table('site_origins')->where('site_id', $site['id'])->countAllResults()];
    }

    public function webOrdersQuery(): BaseBuilder
    {
        return $this->db()->table('orders o')->select('o.id, o.doc_no, o.web_ref, o.created_at, o.customer_name, o.order_type, o.table_ref, o.ship_address, o.total, o.status, o.channel, inv.doc_no AS invoice_no')
            ->join('invoices inv', 'inv.order_id = o.id AND inv.business_id = o.business_id', 'left')
            ->where('o.business_id', $this->bid())->whereIn('o.channel', ['web', 'api', 'marketplace']);
    }

    /* ------------------------------------------------------------------ mode & storefront */

    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['off', 'storefront', 'api'], true)) {
            throw new ValidationException('Unknown website mode.', ['mode' => 'Choose a mode.']);
        }
        if ($mode === 'api' && ! $this->access()->has('devapi')) {
            throw new ModuleDisabledException('API-only websites need the Developer API & webhooks module (Subscription › Modules).');
        }
        $site = $this->site();
        $upd = ['mode' => $mode, 'updated_at' => date('Y-m-d H:i:s')];
        if ($mode !== 'off' && $site['published_at'] === null) {
            $upd['published_at'] = date('Y-m-d H:i:s');
        }
        if ($mode === 'off') {
            $upd['marketplace_listed'] = 0;
        }
        $this->db()->table('sites')->where('id', $site['id'])->update($upd);
        $this->clearHostCache($site);
        service('audit')->activity('Website mode', $mode);
    }

    public const PAGE_STYLES = ['standard' => ['Standard', 'Picture, price and buy buttons side by side'], 'detailed' => ['Detailed', 'Photo gallery, product code, order & cart buttons, call box, Messenger / WhatsApp, delivery-charge table and side notes']];
    /** Icons a side note can use (names of the shared icon set). */
    public const NOTE_ICONS = ['check' => 'Tick', 'phone' => 'Phone', 'credit-card' => 'Payment', 'tag' => 'Tag', 'truck' => 'Delivery', 'shield' => 'Shield', 'star' => 'Star', 'gift' => 'Gift', 'clock' => 'Clock', 'handshake' => 'Handshake'];

    /** @return array<string, mixed> the product-page settings with every key present (what a shop that never saved them shows) */
    public static function productPage(array $stored = []): array
    {
        return \OmniPOS\Core\Services\ProductPageSettings::normalise($stored);
    }

    /**
     * The product page of the website (Website › Storefront › Product page): style, labels, call box,
     * Messenger link, delivery-charge table and side notes. Stored in sites.product_page; the storefronts read it
     * from GET /v1/storefront (site.product_page).
     *
     * @param array<string, mixed> $in
     */
    public function saveProductPage(array $in): void
    {
        $site = $this->site();
        $errors = [];
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $flag = static fn (string $k): bool => ! empty($in[$k]);
        $messenger = $text('pp_messenger', 300);
        if ($messenger !== '' && preg_match('#^[A-Za-z0-9._-]{3,80}$#', $messenger) === 1) {
            $messenger = 'https://m.me/' . $messenger; // a bare page name is enough
        }
        if ($messenger !== '' && (! filter_var($messenger, FILTER_VALIDATE_URL) || ! preg_match('#^https://#i', $messenger))) {
            $errors['pp_messenger'] = 'Enter your Messenger link (https://m.me/yourpage) or just the page name.';
        }
        $delivery = [];
        foreach ((array) ($in['pp_delivery'] ?? []) as $row) {
            $area = mb_substr(trim((string) ($row['area'] ?? '')), 0, 60);
            if ($area === '') {
                continue;
            }
            $charge = trim((string) ($row['charge'] ?? ''));
            if ($charge !== '' && (! is_numeric($charge) || (float) $charge < 0)) {
                $errors['pp_delivery'] = 'A delivery charge must be a number of zero or more.';
                continue;
            }
            $delivery[] = ['area' => $area, 'charge' => round((float) $charge, 2)];
            if (count($delivery) >= 8) {
                break;
            }
        }
        $notes = [];
        foreach ((array) ($in['pp_notes'] ?? []) as $row) {
            $title = mb_substr(trim((string) ($row['title'] ?? '')), 0, 60);
            $body = mb_substr(trim((string) ($row['text'] ?? '')), 0, 300);
            if ($title === '' && $body === '') {
                continue;
            }
            $icon = (string) ($row['icon'] ?? 'check');
            $notes[] = ['icon' => isset(self::NOTE_ICONS[$icon]) ? $icon : 'check', 'title' => $title, 'text' => $body];
            if (count($notes) >= 6) {
                break;
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Some product-page details need a look.', $errors);
        }
        $style = (string) ($in['pp_style'] ?? 'standard');
        $page = [
            'style' => isset(self::PAGE_STYLES[$style]) ? $style : 'standard',
            'showCode' => $flag('pp_showCode'), 'showCategory' => $flag('pp_showCategory'), 'showStock' => $flag('pp_showStock'),
            'orderLabel' => $text('pp_orderLabel', 30) ?: 'Order now', 'cartLabel' => $text('pp_cartLabel', 30) ?: 'Add to cart',
            'callBox' => $flag('pp_callBox'), 'callText' => $text('pp_callText', 120), 'callPhone' => $text('pp_callPhone', 32),
            'messenger' => $messenger, 'showWhatsapp' => $flag('pp_showWhatsapp'),
            'deliveryTable' => $flag('pp_deliveryTable'), 'deliveryTitle' => $text('pp_deliveryTitle', 60) ?: 'Delivery Charge', 'delivery' => $delivery,
            'showNotes' => $flag('pp_showNotes'), 'notes' => $notes,
        ];
        $this->db()->table('sites')->where('id', $site['id'])->update(['product_page' => json_encode($page, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->clearHostCache($site);
        service('audit')->activity('Product page saved', $page['style']);
    }

    /** @param array<string, mixed> $in */
    public function saveStorefront(array $in): void
    {
        $site = $this->site();
        $hex = static fn (string $v): string => preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : '';
        $palette = array_key_exists((string) ($in['palette'] ?? ''), self::PALETTES) ? (string) $in['palette'] : '';
        $brand = $site['brand'];
        $brand['hero'] = mb_substr(trim((string) ($in['hero'] ?? $brand['hero'])), 0, 120);
        $brand['sub'] = mb_substr(trim((string) ($in['sub'] ?? $brand['sub'])), 0, 200);
        $brand['announce'] = mb_substr(trim((string) ($in['announce'] ?? '')), 0, 90);
        // logo image (absolute https URL, e.g. from your own site or a CDN); empty = the icon
        $logo = trim((string) ($in['logo'] ?? ($brand['logo'] ?? '')));
        if ($logo !== '' && (! filter_var($logo, FILTER_VALIDATE_URL) || ! preg_match('#^https://#i', $logo) || strlen($logo) > 300)) {
            throw new ValidationException('The logo must be an https:// image address.', ['logo' => 'Use an https:// link to an image.']);
        }
        $brand['logo'] = $logo;
        $brand['palette'] = $palette;
        if ($palette !== '') {
            [, $brand['color'], $brand['accent'], $brand['dark']] = self::PALETTES[$palette];
        } else {
            foreach (['color', 'accent', 'dark'] as $k) {
                $brand[$k] = $hex((string) ($in['brand_' . $k] ?? ''));
            }
        }
        $sections = [];
        foreach (array_keys(self::SECTIONS) as $k) {
            $sections[$k] = ! empty($in['sections'][$k]);
        }
        $checkout = [];
        foreach (array_keys(self::CHECKOUT) as $k) {
            $checkout[$k] = ! empty($in['checkout'][$k]);
        }
        if (! array_filter($checkout)) {
            throw new ValidationException('Turn on at least one way to pay or collect.', ['checkout' => 'Choose at least one checkout option.']);
        }
        $checkout['delivery'] = max(0, round((float) ($in['delivery'] ?? 0), 2));
        $checkout['freeAbove'] = max(0, round((float) ($in['free_above'] ?? 0), 2));
        $listed = ! empty($in['marketplace_listed']);
        if ($listed && ! $this->access()->has('marketplace')) {
            throw new ModuleDisabledException('The Marketplace listing module is not enabled for this account.');
        }
        if ($listed && $site['mode'] === 'off') {
            throw new BusinessRuleException('Switch the website on before listing the shop on the Marketplace.');
        }
        $theme = array_key_exists((string) ($in['theme'] ?? ''), self::THEMES) ? (string) $in['theme'] : $site['theme'];
        $this->db()->table('sites')->where('id', $site['id'])->update([
            'theme' => $theme, 'brand' => json_encode($brand, JSON_UNESCAPED_UNICODE), 'sections' => json_encode($sections), 'checkout' => json_encode($checkout),
            'seo' => json_encode(['title' => mb_substr(trim((string) ($in['seo_title'] ?? '')), 0, 70), 'description' => mb_substr(trim((string) ($in['seo_description'] ?? '')), 0, 170)], JSON_UNESCAPED_UNICODE),
            'marketplace_listed' => $listed ? 1 : 0, 'marketplace_radius_km' => max(0, min(200, round((float) ($in['marketplace_radius_km'] ?? 0), 1))),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->clearHostCache($site);
        service('audit')->activity('Website saved', $theme . ($listed ? ' · marketplace' : ''));
    }

    /** @return list<array<string, mixed>> the site's information pages (the four starter pages are created on first use) */
    public function pages(): array
    {
        $this->ensurePages();

        return $this->db()->table('site_pages')->where('business_id', $this->bid())->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function page(int $id): ?array
    {
        return $this->db()->table('site_pages')->where('id', $id)->where('business_id', $this->bid())->get()->getRowArray();
    }

    /** A shop that has no pages yet gets the four starter pages, filled from the policy texts it already wrote in Setup › Website. */
    public function ensurePages(): void
    {
        $db = $this->db();
        if ($db->table('site_pages')->where('business_id', $this->bid())->countAllResults() > 0) {
            return;
        }
        $b = service('posSession')->business();
        $brand = $this->site()['brand'];
        $legacy = array_merge((array) ($brand['policy'] ?? []), ['about' => (string) ($brand['about'] ?? '')]);
        $now = date('Y-m-d H:i:s');
        foreach (\OmniPOS\Core\Services\SitePages::defaults((string) ($b['name'] ?? 'Our shop'), (string) ($b['currency_code'] ?? 'BDT'), $legacy) as $p) {
            $db->table('site_pages')->insert(['tenant_id' => service('tenant')->tenantId(), 'business_id' => $this->bid(), 'slug' => $p['slug'], 'kind' => $p['kind'], 'title' => $p['title'], 'body' => $p['body'],
                'is_published' => 1, 'show_in_footer' => $p['footer'], 'sort_order' => $p['sort'], 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Creates (id null) or updates a page. The four starter pages keep their kind and cannot be deleted (only unpublished).
     *
     * @param array<string, mixed> $in title, slug, body, published, footer, sort, seo_description
     */
    public function savePage(?int $id, array $in): int
    {
        $db = $this->db();
        $old = $id !== null ? ($this->page($id) ?? throw new NotFoundException('Page not found.')) : null;
        $errors = [];
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '') {
            $errors['title'] = 'Enter the page title.';
        }
        $slug = \OmniPOS\Core\Services\SitePages::slugify(trim((string) ($in['slug'] ?? '')) !== '' ? (string) $in['slug'] : $title);
        if ($slug === '') {
            $errors['slug'] = 'Enter a web address for the page (letters and numbers).';
        } elseif (in_array($slug, ['all', 'cart', 'checkout', 'track', 'book', 'contact', 'about', 'policy', 'wishlist', 'services', 'doctors', 'done', 'booked', 'api', 'media'], true)) {
            $errors['slug'] = 'That address is used by the website itself — choose another.';
        } else {
            $dup = $db->table('site_pages')->where('business_id', $this->bid())->where('slug', $slug);
            if ($id !== null) {
                $dup->where('id <>', $id);
            }
            if ($dup->countAllResults() > 0) {
                $errors['slug'] = 'Another page already uses this address.';
            }
        }
        $raw = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $body = \OmniPOS\Core\Services\SitePages::isHtml($raw) ? \OmniPOS\Core\Services\SitePages::sanitize($raw) : $raw;
        if (mb_strlen($body) > 60000) {
            $errors['body'] = 'The page is too long (60,000 characters at most).';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the page.', $errors);
        }
        $now = date('Y-m-d H:i:s');
        $row = ['slug' => $slug, 'title' => $title, 'body' => $body !== '' ? $body : null, 'is_published' => ! empty($in['published']) ? 1 : 0, 'show_in_footer' => ! empty($in['footer']) ? 1 : 0,
            'sort_order' => max(0, min(999, (int) ($in['sort'] ?? 0))), 'seo_description' => mb_substr(trim((string) ($in['seo_description'] ?? '')), 0, 300) ?: null, 'updated_at' => $now];
        if ($old !== null) {
            $db->table('site_pages')->where('id', $id)->update($row);
            $kind = (string) $old['kind'];
        } else {
            $db->table('site_pages')->insert($row + ['tenant_id' => service('tenant')->tenantId(), 'business_id' => $this->bid(), 'kind' => 'custom', 'created_at' => $now]);
            $id = (int) $db->insertID();
            $kind = 'custom';
        }
        // the PHP storefront still reads the policy texts from the site's brand: keep them in step
        if (in_array($kind, ['delivery', 'privacy', 'terms'], true)) {
            $site = $this->site();
            $brand = $site['brand'];
            $brand['policy'][$kind] = \OmniPOS\Core\Services\SitePages::toText($body);
            $db->table('sites')->where('id', $site['id'])->update(['brand' => json_encode($brand, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
        }
        $this->clearHostCache($this->site());
        service('audit')->activity('Page saved', $title);

        return (int) $id;
    }

    public function deletePage(int $id): void
    {
        $p = $this->page($id) ?? throw new NotFoundException('Page not found.');
        if ($p['kind'] !== 'custom') {
            throw new BusinessRuleException('The standard pages cannot be deleted — switch "Published" off to hide one.');
        }
        $this->db()->table('site_pages')->where('id', $id)->delete();
        $this->clearHostCache($this->site());
        service('audit')->activity('Page deleted', (string) $p['title']);
    }

    public const SOCIALS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube'];
    public const POLICIES = ['delivery' => 'Delivery', 'returns' => 'Returns & refunds', 'privacy' => 'Privacy policy', 'terms' => 'Terms & conditions'];
    private const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];

    /**
     * The storefront's identity & content (Setup › Website): logo, favicon, banner, about text, contact,
     * social links, footer line and the policy pages. Stored in sites.brand and passed on to the storefront
     * as-is (api StorefrontService::profile); theme, colours, sections and checkout stay on the Website page.
     *
     * @param array<string, mixed>                             $in
     * @param array<string, \CodeIgniter\HTTP\Files\UploadedFile> $files keys: logo, favicon, hero
     */
    public function saveProfile(array $in, array $files = []): void
    {
        $site = $this->site();
        $brand = $site['brand'];
        $errors = [];
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $url = static function (string $k) use ($in, &$errors): string {
            $v = trim((string) ($in[$k] ?? ''));
            if ($v !== '' && (! filter_var($v, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $v) || strlen($v) > 300)) {
                $errors[$k] = 'Enter a full web address starting with https://';

                return '';
            }

            return $v;
        };
        $uploads = [];
        foreach ([['logo', 'logo'], ['favicon', 'favicon'], ['hero', 'heroImg']] as [$field, $key]) {
            $file = $files[$field] ?? null;
            if ($file !== null && $file->isValid() && ! $file->hasMoved()) {
                if (self::imageExt($file) === null) {
                    $errors['web_' . $field . '_file'] = 'Use a PNG, JPG, WebP or GIF image up to 900 KB.';
                } else {
                    $uploads[$key] = $file;
                }
            } elseif (! empty($in['web_' . $field . '_remove'])) {
                $uploads[$key] = null;
            } elseif (trim((string) ($in['web_' . $field . '_url'] ?? '')) !== '') {
                $brand[$key] = $url('web_' . $field . '_url');
            }
        }
        $brand['sub'] = $text('web_tagline', 200);
        $brand['about'] = $text('web_about', 1500);
        $brand['whatsapp'] = preg_replace('/\D+/', '', $text('web_whatsapp', 20)) ?? '';
        $brand['contactEmail'] = $text('web_email', 160);
        if ($brand['contactEmail'] !== '' && ! filter_var($brand['contactEmail'], FILTER_VALIDATE_EMAIL)) {
            $errors['web_email'] = 'Enter a valid email address.';
        }
        $brand['hoursText'] = $text('web_hours', 120);
        $brand['mapUrl'] = $url('web_map');
        $brand['footer'] = $text('web_footer', 160);
        $brand['social'] = [];
        foreach (array_keys(self::SOCIALS) as $k) {
            $brand['social'][$k] = $url('web_social_' . $k);
        }
        foreach (array_keys(self::POLICIES) as $k) {
            if (isset($in['web_policy_' . $k])) {
                $brand['policy'][$k] = $text('web_policy_' . $k, 4000);
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Some website details need a look.', $errors);
        }
        // images are written only once everything else has passed (no orphan files after an error)
        foreach ($uploads as $key => $file) {
            $this->deleteImage((string) ($brand[$key] ?? ''));
            $brand[$key] = $file === null ? '' : $this->storeImage($file, $key === 'heroImg' ? 'hero' : $key);
        }
        $this->db()->table('sites')->where('id', $site['id'])->update(['brand' => json_encode($brand, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
        $this->clearHostCache($site);
        service('audit')->activity('Website profile saved', '');
    }

    /** File extension for an acceptable image (type, and up to 900 KB so it fits one database packet), else null. */
    private static function imageExt(\CodeIgniter\HTTP\Files\UploadedFile $file): ?string
    {
        return $file->getSize() > 900 * 1024 ? null : (self::IMAGE_TYPES[(string) $file->getMimeType()] ?? null);
    }

    /**
     * Keeps an uploaded image in the shared database (site_media) and returns its address on the API
     * (<api>/media/<id>-<hash>.<ext>): storefronts only ever talk to the API, never to this POS server.
     */
    private function storeImage(\CodeIgniter\HTTP\Files\UploadedFile $file, string $slot): string
    {
        $ext = self::imageExt($file);
        $bytes = @file_get_contents($file->getTempName());
        $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        // a real picture only (an .ico has no size the GD way, so it is checked by its header instead)
        if ($ext === null || $bytes === false || ($info === false && ! ($ext === 'ico' && str_starts_with($bytes, "\x00\x00\x01\x00")))) {
            throw new ValidationException('That file is not a usable image.', []);
        }
        $db = $this->db();
        $db->table('site_media')->insert([
            'tenant_id' => service('tenant')->tenantId(), 'business_id' => $this->bid(), 'slot' => $slot, 'mime' => (string) $file->getMimeType(), 'ext' => $ext,
            'size' => strlen($bytes), 'hash' => substr(hash('sha256', $bytes), 0, 16), 'data' => $bytes, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->insertID();
        $hash = substr(hash('sha256', $bytes), 0, 16);

        return rtrim((string) config(OmniPOS::class)->apiUrl, '/') . '/media/' . $id . '-' . $hash . '.' . $ext;
    }

    /** Removes a previously stored image: its site_media row (API address) or, for older uploads, the file. External links are left alone. */
    private function deleteImage(string $address): void
    {
        if (preg_match('#/media/(\d+)-[a-f0-9]{16}\.[a-z]{3,4}$#', $address, $m) === 1) {
            $this->db()->table('site_media')->where('id', (int) $m[1])->where('business_id', $this->bid())->delete();

            return;
        }
        $marker = 'uploads/site/' . $this->bid() . '/';
        $at = strpos($address, $marker);
        if ($at === false) {
            return;
        }
        $path = FCPATH . $marker . basename(substr($address, $at + strlen($marker)));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function saveSlug(string $slug): void
    {
        $slug = strtolower(trim($slug));
        if (! SiteAddresses::valid($slug)) {
            throw new ValidationException('Choose another address.', ['slug' => '3–48 letters, digits or dashes; not a reserved word.']);
        }
        $site = $this->site();
        if (! SiteAddresses::isFree($slug, (int) $site['id'])) {
            throw new ValidationException('That address is taken.', ['slug' => 'Already used by another shop or multi-outlet website.']);
        }
        $this->db()->table('sites')->where('id', $site['id'])->update(['slug' => $slug, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->clearHostCache($site);
        service('audit')->activity('Website address', $slug);
    }

    /** @param array<string, mixed> $in cache_pages_sec, cache_api_sec */
    public function saveCache(array $in): void
    {
        $site = $this->site();
        $pages = in_array((int) ($in['cache_pages_sec'] ?? 300), [0, 60, 300, 900, 3600], true) ? (int) $in['cache_pages_sec'] : 300;
        $api = in_array((int) ($in['cache_api_sec'] ?? 60), [0, 30, 60, 300], true) ? (int) $in['cache_api_sec'] : 60;
        $this->db()->table('sites')->where('id', $site['id'])->update(['cache_pages_sec' => $pages, 'cache_api_sec' => $api, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    private function clearHostCache(array $site): void
    {
        service('domains')->forget((int) $site['id']);
        service('sharedCache')->delete('omnipos_sfhost_' . sha1(strtolower($site['slug'] . '.' . config(OmniPOS::class)->siteHost)));
        service('sharedCache')->delete('omnipos_sfhost_' . sha1(strtolower((string) $site['slug'])));
        foreach ($this->db()->table('site_domains')->select('host')->where('site_id', $site['id'])->get()->getResultArray() as $d) {
            service('sharedCache')->delete('omnipos_sfhost_' . sha1(strtolower($d['host'])));
        }
    }

    /* ------------------------------------------------------------------ domains */

    /** @return list<array<string, mixed>> */
    public function domains(int $siteId): array
    {
        return $this->db()->table('site_domains')->where('site_id', $siteId)->orderBy('is_primary', 'DESC')->orderBy('id')->get()->getResultArray();
    }

    public function addDomain(string $host): void
    {
        $host = strtolower(trim(preg_replace('#^https?://#i', '', $host) ?? ''));
        $host = rtrim(explode('/', $host)[0], '.');
        if (! preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $host)) {
            throw new ValidationException('Enter a domain like yourshop.com or shop.yourcompany.com.', ['host' => 'Not a valid domain name.']);
        }
        if (SiteAddresses::isPlatformHost($host)) {
            throw new ValidationException('That is your free address — add your own domain here.', ['host' => 'Use your own domain.']);
        }
        if (SiteAddresses::domainTaken($host)) {
            throw new BusinessRuleException($host . ' is already connected to a shop.');
        }
        $site = $this->site();
        if (count($this->domains((int) $site['id'])) >= 5) {
            throw new BusinessRuleException('At most 5 custom domains per website.');
        }
        $this->db()->table('site_domains')->insert(['site_id' => $site['id'], 'host' => $host, 'status' => 'pending', 'is_primary' => 0, 'verify_token' => 'omnipos-verify=' . bin2hex(random_bytes(12)),
            'ssl_status' => 'none', 'created_at' => date('Y-m-d H:i:s')]);
        service('audit')->activity('Domain added', $host);
    }

    /** @return array<string, mixed> domain of this outlet's site */
    private function domain(int $id): array
    {
        $site = $this->site();

        return $this->db()->table('site_domains')->where('id', $id)->where('site_id', $site['id'])->get()->getRowArray() ?? throw new NotFoundException('Domain not found.');
    }

    /**
     * Check the TXT record _omnipos.<host> for the verify token. On success the domain is connected
     * and SSL is issued (the edge issues the certificate; here its status is recorded).
     */
    public function verifyDomain(int $id, ?callable $resolver = null): bool
    {
        $d = $this->domain($id);
        $resolver ??= static function (string $name): array {
            $out = [];
            foreach (@dns_get_record($name, DNS_TXT) ?: [] as $r) {
                $out[] = (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? [])));
            }

            return $out;
        };
        $found = in_array($d['verify_token'], array_map('trim', $resolver('_omnipos.' . $d['host'])), true);
        $db = $this->db();
        if (! $found) {
            $db->table('site_domains')->where('id', $id)->update(['status' => $d['status'] === 'active' ? 'active' : 'pending']);

            return false;
        }
        $hasPrimary = $db->table('site_domains')->where('site_id', $d['site_id'])->where('is_primary', 1)->where('status', 'active')->countAllResults() > 0;
        $db->table('site_domains')->where('id', $id)->update(['status' => 'active', 'verified_at' => date('Y-m-d H:i:s'), 'ssl_status' => 'active',
            'ssl_expires_at' => date('Y-m-d H:i:s', strtotime('+90 days')), 'is_primary' => $hasPrimary ? (int) $d['is_primary'] : 1]);
        service('domains')->forget((int) $d['site_id']);
        service('audit')->activity('Domain verified', $d['host']);

        return true;
    }

    public function setPrimary(int $id): void
    {
        $d = $this->domain($id);
        if ($d['status'] !== 'active') {
            throw new BusinessRuleException('Verify the domain before making it primary.');
        }
        $db = $this->db();
        $db->table('site_domains')->where('site_id', $d['site_id'])->update(['is_primary' => 0]);
        $db->table('site_domains')->where('id', $id)->update(['is_primary' => 1]);
        service('audit')->activity('Primary domain', $d['host']);
    }

    public function removeDomain(int $id): void
    {
        $d = $this->domain($id);
        service('domains')->forget((int) $d['site_id']);
        $this->db()->table('site_domains')->where('id', $id)->delete();
        service('audit')->activity('Domain removed', $d['host']);
    }

    /* ------------------------------------------------------------------ domain control (owner) */

    /** Owner's on/off switch for a domain. A platform block (Super Admin) cannot be lifted here. */
    public function setDomainEnabled(int $id, bool $enabled): void
    {
        $d = $this->domain($id);
        if ($enabled && (int) $d['admin_blocked'] === 1) {
            throw new BusinessRuleException($d['host'] . ' is blocked by ' . \OmniPOS\Ui\Brand::name() . ($d['blocked_reason'] ? ' (' . $d['blocked_reason'] . ')' : '') . '. Contact support.');
        }
        service('domains')->setEnabled($id, $enabled, ['name' => service('tenant')->userName()]);
        service('audit')->activity($enabled ? 'Domain enabled' : 'Domain disabled', $d['host']);
    }

    /** Where the domain points: the OmniPOS platform, or the customer's own hosting (self-hosted storefront). */
    public function setDomainHosting(int $id, string $hosting): void
    {
        if (! in_array($hosting, ['platform', 'external'], true)) {
            throw new ValidationException('Choose where this domain is hosted.', ['hosting' => 'Unknown hosting.']);
        }
        $d = $this->domain($id);
        $this->db()->table('site_domains')->where('id', $id)->update(['hosting' => $hosting, 'changed_by' => service('tenant')->userName(), 'changed_at' => date('Y-m-d H:i:s')]);
        service('domains')->forget((int) $d['site_id']);
        service('audit')->activity('Domain hosting', $d['host'] . ' → ' . ($hosting === 'external' ? 'own server' : \OmniPOS\Ui\Brand::name()));
    }

    /* ------------------------------------------------------------------ self-hosted storefront clients */

    /** @return list<array<string, mixed>> clients of this site (never the secret hash) */
    public function clients(int $siteId): array
    {
        return $this->db()->table('storefront_clients')
            ->select('id, client_id, secret_last4, name, is_active, last_used_at, last_used_host, rotated_at, revoked_at, created_at')
            ->where('site_id', $siteId)->where('business_id', $this->bid())->orderBy('revoked_at IS NULL', 'DESC', false)->orderBy('id', 'DESC')
            ->get()->getResultArray();
    }

    /** @return array{client_id: string, client_secret: string} — the secret is returned once and never stored */
    public function createClient(string $name): array
    {
        $site = $this->site();
        $out = service('storefrontClients')->create((int) $site['id'], $name, service('tenant')->userId());
        service('audit')->activity('Storefront client created', $out['client_id']);

        return ['client_id' => $out['client_id'], 'client_secret' => $out['client_secret']];
    }

    /** @return array{client_id: string, client_secret: string} */
    public function rotateClient(int $id): array
    {
        $site = $this->site();
        $out = service('storefrontClients')->rotate($id, (int) $site['id']);
        service('audit')->activity('Storefront secret rotated', $out['client_id']);

        return $out;
    }

    public function toggleClient(int $id): bool
    {
        $site = $this->site();
        $c = $this->db()->table('storefront_clients')->where('id', $id)->where('site_id', $site['id'])->where('revoked_at', null)->get()->getRowArray()
            ?? throw new NotFoundException('Client not found.');
        $on = ! (bool) $c['is_active'];
        service('storefrontClients')->setActive($id, (int) $site['id'], $on);
        service('audit')->activity($on ? 'Storefront client resumed' : 'Storefront client paused', (string) $c['client_id']);

        return $on;
    }

    public function revokeClient(int $id): void
    {
        $site = $this->site();
        service('storefrontClients')->revoke($id, (int) $site['id']);
        service('audit')->activity('Storefront client revoked', '#' . $id);
    }

    /* ------------------------------------------------------------------ API keys & origins */


    public function keysQuery(int $siteId): BaseBuilder
    {
        return $this->db()->table('api_keys k')->select('k.id, k.name, k.key_type, k.prefix, k.last4, k.scopes, k.is_storefront, k.last_used_at, k.revoked_at, k.created_at, u.name AS user_name')
            ->join('users u', 'u.id = k.created_by', 'left')
            ->where('k.site_id', $siteId)->where('k.business_id', $this->bid())->where('k.prefix <>', self::MULTISITE_PREFIX);
    }

    /**
     * @param list<string> $scopes
     * @return array{key: string, row: array<string, mixed>} the full key — shown once, never stored
     */
    public function createKey(string $name, string $type, array $scopes): array
    {
        if (! $this->access()->has('devapi')) {
            throw new ModuleDisabledException('API keys need the Developer API & webhooks module (Subscription › Modules).');
        }
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') {
            throw new ValidationException('Name the key.', ['key_name' => 'e.g. “My Next.js site”.']);
        }
        $type = $type === 'secret' ? 'secret' : 'publishable';
        $allowed = $type === 'secret' ? array_keys(ApiKeyService::SCOPES) : ApiKeyService::publishableScopes();
        $scopes = array_values(array_intersect($allowed, $scopes));
        if ($scopes === []) {
            throw new ValidationException('Choose what the key may do.', ['scopes' => 'Choose at least one scope.']);
        }
        $site = $this->site();
        $out = service('apiKeys')->create((int) $site['id'], $type, $name, $scopes, service('tenant')->userId());
        service('audit')->activity('API key created', $name . ' · ' . $type . ' · ' . $out['row']['prefix'] . '…' . $out['row']['last4']);

        return $out;
    }

    public function revokeKey(int $keyId): void
    {
        $site = $this->site();
        $k = $this->db()->table('api_keys')->select('id, name, revoked_at')->where('id', $keyId)->where('site_id', $site['id'])->where('business_id', $this->bid())
            ->where('prefix <>', self::MULTISITE_PREFIX)->get()->getRowArray()
            ?? throw new NotFoundException('Key not found.');
        if ($k['revoked_at'] !== null) {
            return;
        }
        service('apiKeys')->revoke($keyId);
        service('audit')->activity('API key revoked', (string) $k['name']);
    }

    /** @return list<string> */
    public function origins(int $siteId): array
    {
        return array_column($this->db()->table('site_origins')->select('origin')->where('site_id', $siteId)->orderBy('origin')->get()->getResultArray(), 'origin');
    }

    public function addOrigin(string $origin): void
    {
        $origin = rtrim(strtolower(trim($origin)), '/');
        if (! preg_match('#^https?://(localhost|\d{1,3}(\.\d{1,3}){3}|([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24})(:\d{2,5})?$#', $origin)) {
            throw new ValidationException('Enter an origin like https://www.yourshop.com.', ['origin' => 'Scheme and host only, no path.']);
        }
        if (str_starts_with($origin, 'http://') && ! preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
            throw new ValidationException('Use https:// (http is allowed only for localhost).', ['origin' => 'Use https://.']);
        }
        $site = $this->site();
        $this->db()->query('INSERT IGNORE INTO site_origins (site_id, origin) VALUES (?, ?)', [$site['id'], $origin]);
        service('domains')->forget((int) $site['id']);
        service('audit')->activity('Allowed origin added', $origin);
    }

    public function removeOrigin(string $origin): void
    {
        $site = $this->site();
        service('domains')->forget((int) $site['id']);   // before the delete, so the removed origin's cache entry is cleared too
        $this->db()->table('site_origins')->where('site_id', $site['id'])->where('origin', $origin)->delete();
        service('audit')->activity('Allowed origin removed', $origin);
    }

    /* ------------------------------------------------------------------ webhooks */

    /** @return list<array<string, mixed>> */
    public function webhooks(int $siteId): array
    {
        $rows = $this->db()->table('webhooks')->where('site_id', $siteId)->where('business_id', $this->bid())->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['events'] = json_decode((string) $r['events'], true) ?: [];
        }

        return $rows;
    }

    /** @param list<string> $events */
    public function addWebhook(string $url, array $events): string
    {
        if (! $this->access()->has('devapi')) {
            throw new ModuleDisabledException('Webhooks need the Developer API & webhooks module (Subscription › Modules).');
        }
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https://#i', $url) && ! preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#i', $url)) {
            throw new ValidationException('Enter an https:// endpoint URL.', ['url' => 'Webhooks are sent over HTTPS only.']);
        }
        $events = array_values(array_intersect(self::EVENTS, $events));
        if ($events === []) {
            throw new ValidationException('Choose the events to send.', ['events' => 'Choose at least one event.']);
        }
        $site = $this->site();
        if (count($this->webhooks((int) $site['id'])) >= 10) {
            throw new BusinessRuleException('At most 10 webhook endpoints per website.');
        }
        $secret = 'whsec_' . bin2hex(random_bytes(16));
        $this->db()->table('webhooks')->insert(['tenant_id' => service('tenant')->tenantId(), 'business_id' => $this->bid(), 'site_id' => $site['id'], 'url' => mb_substr($url, 0, 500),
            'events' => json_encode($events), 'secret' => $secret, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        service('audit')->activity('Webhook added', $url);

        return $secret;
    }

    /** @return array<string, mixed> */
    private function webhook(int $id): array
    {
        $site = $this->site();

        return $this->db()->table('webhooks')->where('id', $id)->where('site_id', $site['id'])->where('business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Webhook not found.');
    }

    public function toggleWebhook(int $id): bool
    {
        $h = $this->webhook($id);
        $on = ! (bool) $h['is_active'];
        $this->db()->table('webhooks')->where('id', $id)->update(['is_active' => $on ? 1 : 0]);

        return $on;
    }

    public function deleteWebhook(int $id): void
    {
        $h = $this->webhook($id);
        $this->db()->table('webhooks')->where('id', $id)->delete();
        service('audit')->activity('Webhook removed', (string) $h['url']);
    }

    /** Queue a "ping" delivery; the API service's delivery worker sends it (signed with the secret). */
    public function testWebhook(int $id): void
    {
        $h = $this->webhook($id);
        $this->db()->table('webhook_deliveries')->insert(['webhook_id' => $h['id'], 'event' => 'ping',
            'payload' => json_encode(['event' => 'ping', 'business_id' => $this->bid(), 'sent_at' => date(DATE_ATOM)]), 'attempts' => 0, 'next_try_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function deliveriesQuery(int $siteId): BaseBuilder
    {
        return $this->db()->table('webhook_deliveries d')->select('d.id, d.event, d.status_code, d.duration_ms, d.attempts, d.next_try_at, d.delivered_at, d.created_at, w.url')
            ->join('webhooks w', 'w.id = d.webhook_id')
            ->where('w.site_id', $siteId)->where('w.business_id', $this->bid());
    }
}
