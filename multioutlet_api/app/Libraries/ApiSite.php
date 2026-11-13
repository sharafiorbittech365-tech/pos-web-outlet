<?php

declare(strict_types=1);

namespace App\Libraries;

use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Pricing\TaxPolicy;

/**
 * The site (and its outlet) behind the current API key, loaded once per request.
 *
 *   site()      sites row with brand / sections / checkout / seo decoded
 *   business()  businesses row with settings decoded, plus type_name / store_kind / booking_kind
 *   taxPolicy() the outlet's VAT policy (prices shown = what the customer pays)
 */
final class ApiSite
{
    /** @var array<int, array{site: array<string, mixed>, business: array<string, mixed>}> */
    private array $memo = [];

    public function __construct(private readonly ApiCaller $caller)
    {
    }

    /** @return array{site: array<string, mixed>, business: array<string, mixed>} */
    private function load(): array
    {
        $siteId = (int) ($this->caller->key()['site_id'] ?? 0);
        if (isset($this->memo[$siteId])) {
            return $this->memo[$siteId];
        }
        $db = db_connect();
        $site = $db->table('sites')
            ->select('id, tenant_id, business_id, mode, slug, theme, brand, sections, checkout, seo, product_page, marketplace_listed, cache_api_sec, published_at')
            ->where('id', $siteId)->get()->getRowArray() ?? throw new NotFoundException('Site not found.');
        foreach (['brand', 'sections', 'checkout', 'seo', 'product_page'] as $col) {
            $site[$col] = json_decode((string) ($site[$col] ?? ''), true) ?: [];
        }
        $biz = $db->table('businesses b')
            ->select('b.*, bt.name AS type_name, bt.store_kind, bt.booking_kind')
            ->join('business_types bt', 'bt.code = b.business_type', 'left')
            ->where('b.id', $site['business_id'])->where('b.tenant_id', $site['tenant_id'])
            ->get()->getRowArray() ?? throw new NotFoundException('Business not found.');
        $biz['settings'] = json_decode((string) ($biz['settings'] ?? ''), true) ?: [];

        return $this->memo[$siteId] = ['site' => $site, 'business' => $biz];
    }

    /** @return array<string, mixed> */
    public function site(): array
    {
        return $this->load()['site'];
    }

    /** @return array<string, mixed> */
    public function business(): array
    {
        return $this->load()['business'];
    }

    public function taxPolicy(): TaxPolicy
    {
        return TaxPolicy::fromBusiness($this->business());
    }

    /** Checkout settings with defaults (cod, bkash, card, pickup, delivery, freeAbove). */
    public function checkout(): array
    {
        $c = $this->site()['checkout'];

        return [
            'cod' => (bool) ($c['cod'] ?? true),
            'bkash' => (bool) ($c['bkash'] ?? false),
            'card' => (bool) ($c['card'] ?? false),
            'pickup' => (bool) ($c['pickup'] ?? false),
            'delivery' => (float) ($c['delivery'] ?? 0),
            'freeAbove' => (float) ($c['freeAbove'] ?? 0),
        ];
    }

    public function cacheSeconds(): int
    {
        return max(0, (int) ($this->site()['cache_api_sec'] ?? 60));
    }

    /** Public host of the site: the active primary custom domain, else <slug>.<siteHost>. */
    public function host(): string
    {
        $site = $this->site();
        $domain = db_connect()->table('site_domains')->select('host')->where('site_id', $site['id'])->where('status', 'active')
            ->orderBy('is_primary', 'DESC')->limit(1)->get()->getRow('host');

        return $domain !== null ? (string) $domain : $site['slug'] . '.' . config(\OmniPOS\Core\Config\OmniPOS::class)->siteHost;
    }

    public function decimals(): int
    {
        return (int) ($this->business()['currency_decimals'] ?? 2);
    }
}
