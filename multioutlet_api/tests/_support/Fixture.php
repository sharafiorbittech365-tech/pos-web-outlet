<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A small, self-contained workspace per test: tenant + subscription, one outlet with VAT
 * settings and booking staff, categories, products (exclusive / inclusive / service / hidden),
 * a published storefront site and API keys (created through ApiKeyService, as in production).
 *
 * Every call uses a unique suffix, so tests never share rows and need no cleanup.
 */
final class Fixture
{
    public int $tenantId;
    public int $businessId;
    public int $siteId;
    public string $slug;
    /** @var array<string, int> */
    public array $products = [];
    public int $categoryId;
    public string $publishable;
    public string $secret;
    public string $productsOnly;

    /**
     * @param array<string, mixed> $o status, plan, lat, lng, delivery, freeAbove, type, origins
     */
    public static function create(array $o = []): self
    {
        $f = new self();
        $db = db_connect();
        $u = substr(bin2hex(random_bytes(4)), 0, 7);
        $now = date('Y-m-d H:i:s');
        $type = (string) ($o['type'] ?? 'electronics');
        $planId = (int) $db->table('plans')->select('id')->where('code', $o['plan'] ?? 'business')->get()->getRow('id');

        $db->table('tenants')->insert(['code' => 't_' . $u, 'name' => 'Test ' . $u, 'owner_name' => 'Owner', 'owner_email' => $u . '@test.local',
            'business_type' => $type, 'created_at' => $now, 'updated_at' => $now]);
        $f->tenantId = (int) $db->insertID();
        $db->table('subscriptions')->insert(['tenant_id' => $f->tenantId, 'plan_id' => $planId, 'status' => $o['status'] ?? 'active',
            'period_start' => $now, 'period_end' => date('Y-m-d H:i:s', strtotime('+30 days')), 'created_at' => $now, 'updated_at' => $now]);

        $settings = ['hours' => ['open' => '10:00', 'close' => '18:00', 'step' => 30], 'serviceStaff' => ['Rahim', 'Karim'], 'logoIcon' => 'smartphone',
            'features' => ['discount' => true]];
        $db->table('businesses')->insert([
            'tenant_id' => $f->tenantId, 'business_type' => $type, 'name' => 'Shop ' . $u, 'slug' => 'shop-' . $u, 'phone' => '01700000000',
            'city' => 'Dhaka', 'area' => 'Gulshan', 'lat' => $o['lat'] ?? 23.7925, 'lng' => $o['lng'] ?? 90.4078, 'delivery_km' => 10,
            'currency_code' => 'BDT', 'currency_symbol' => '৳', 'currency_decimals' => 2, 'tax_label' => 'VAT', 'tax_rate' => 5, 'tax_inclusive' => 0,
            'stock_enabled' => 1, 'settings' => json_encode($settings), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $f->businessId = (int) $db->insertID();

        $db->table('categories')->insert(['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'name' => 'Gadgets', 'icon' => 'smartphone', 'sort_order' => 0, 'created_at' => $now]);
        $f->categoryId = (int) $db->insertID();

        $products = [
            // VAT added on top (business default: exclusive 5%) -> customer pays 105
            'charger' => ['name' => 'Fast Charger ' . $u, 'brand' => 'Volt', 'price' => 100, 'stock_type' => 'simple', 'stock_qty' => 5],
            // own rule: 10% inside the price -> customer pays 200
            'speaker' => ['name' => 'Speaker ' . $u, 'brand' => 'Boom', 'price' => 200, 'tax_mode' => 'inclusive', 'tax_rate' => 10, 'stock_type' => 'simple', 'stock_qty' => 2, 'is_featured' => 1],
            'repair' => ['name' => 'Screen Repair ' . $u, 'price' => 500, 'stock_type' => 'none', 'stock_qty' => 0, 'duration_min' => 60],
            'cable' => ['name' => 'Cable ' . $u, 'price' => 50, 'stock_type' => 'simple', 'stock_qty' => 0],
            'hidden' => ['name' => 'Hidden ' . $u, 'price' => 10, 'stock_type' => 'none', 'is_online' => 0],
            'deleted' => ['name' => 'Deleted ' . $u, 'price' => 10, 'stock_type' => 'none', 'deleted_at' => $now],
        ];
        foreach ($products as $key => $p) {
            $db->table('products')->insert($p + ['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'category_id' => $f->categoryId,
                'tax_mode' => 'default', 'is_online' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $f->products[$key] = (int) $db->insertID();
        }

        $f->slug = 'sf' . $u;
        $db->table('sites')->insert([
            'tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'mode' => $o['mode'] ?? 'storefront', 'slug' => $f->slug, 'theme' => 'fresh',
            'brand' => json_encode(['hero' => 'Hi', 'sub' => 'Sub']), 'sections' => json_encode(['hero' => true]),
            'checkout' => json_encode(['cod' => true, 'bkash' => true, 'card' => false, 'pickup' => true, 'delivery' => $o['delivery'] ?? 60, 'freeAbove' => $o['freeAbove'] ?? 1000]),
            'seo' => json_encode(['title' => 'Shop']), 'marketplace_listed' => $o['listed'] ?? 1, 'cache_api_sec' => 45, 'published_at' => $now, 'created_at' => $now,
        ]);
        $f->siteId = (int) $db->insertID();
        foreach ((array) ($o['origins'] ?? ['https://shop.example']) as $origin) {
            $db->table('site_origins')->insert(['site_id' => $f->siteId, 'origin' => $origin]);
        }

        $keys = service('apiKeys');
        $f->publishable = $keys->create($f->siteId, 'publishable', 'Web', \OmniPOS\Core\Services\ApiKeyService::publishableScopes())['key'];
        $f->secret = $keys->create($f->siteId, 'secret', 'Server', array_keys(\OmniPOS\Core\Services\ApiKeyService::SCOPES))['key'];
        $f->productsOnly = $keys->create($f->siteId, 'secret', 'Read only', ['products:read'])['key'];

        return $f;
    }

    public function setStatus(string $status): void
    {
        db_connect()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['status' => $status]);
        service('moduleAccess')->invalidate($this->tenantId);
    }

    public function setModule(string $module, bool $on): void
    {
        db_connect()->query('REPLACE INTO tenant_module_overrides (tenant_id, module_code, is_enabled) VALUES (?, ?, ?)', [$this->tenantId, $module, $on ? 1 : 0]);
        service('moduleAccess')->invalidate($this->tenantId);
        cache()->delete('omnipos_mkt_tenants');
    }

    public function stock(string $product): float
    {
        return (float) db_connect()->table('products')->select('stock_qty')->where('id', $this->products[$product])->get()->getRow('stock_qty');
    }
}
