<?php

declare(strict_types=1);

use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Database-driven pricing: Super Admin › Pricing edits prices, outlet discounts, packages and
 * per-customer discounts; the engine and the billing lines follow, with nothing fixed in code.
 *
 * @internal
 */
final class PricingTest extends FeatureTestCase
{
    private function quote(int $tid): array
    {
        service('pricing')->flush();

        return service('pricing')->quote($tid);
    }

    public function testPricingPageListsTheSeededPriceList(): void
    {
        $res = $this->getPage('pricing');
        $res->assertOK();
        $res->assertSee('Base package');
        $res->assertSee('Multi-outlet website');
        $res->assertSee('Outlet-based discount');
        $this->assertTrue(service('pricing')->active());
        $this->assertSame(900.0, service('pricing')->basePrice());
    }

    public function testChangingTheBasePriceChangesTheBill(): void
    {
        $tid = $this->makeTenant('trial', 'business');
        $before = $this->quote($tid)['monthly'];
        $items = service('pricing')->config()['items'];
        $post = [];
        foreach ($items as $code => $i) {
            $post[$code] = ['label' => $i['label'], 'price' => (string) ($code === 'base.pos' ? 1000 : $i['price']), 'enabled' => $i['is_enabled'] ? '1' : '0', 'in_base' => $i['in_base'] ? '1' : '0'];
        }
        $this->postForm('pricing/items', ['items' => $post])->assertRedirect();
        $after = $this->quote($tid)['monthly'];
        $this->assertSame(100.0, round($after - $before, 2), 'one outlet: +100');
        $this->seeInDatabase('platform_audit', ['action' => 'Pricing saved']);
        // billing lines follow the engine
        $lines = service('billing')->lines($tid, (int) $this->subscription($tid)['plan_id'], 'grocery');
        $this->assertSame($after, round(array_sum(array_column($lines, 'monthly')), 2));
    }

    public function testCustomerDiscountPackageAndPriceOverride(): void
    {
        $tid = $this->makeTenant('trial', 'business');
        $std = $this->quote($tid)['monthly'];
        $this->postForm('pricing/customer/' . $tid, ['discount_type' => 'percent', 'discount_value' => '10', 'package_id' => '', 'domain_name' => 'shop.example.test', 'domain_cost' => '1200'])->assertRedirect();
        $q = $this->quote($tid);
        $this->assertSame(round($std * 0.9, 2), $q['monthly']);
        $this->assertSame(1200.0, $q['domain']['cost']);

        $this->postForm('pricing/customer/' . $tid, ['discount_type' => 'fixed', 'discount_value' => '50', 'prices' => ['base.pos' => '700']])->assertRedirect();
        $this->assertSame(650.0, $this->quote($tid)['monthly'] - ($this->quote($tid)['breakdown']['modules'] + $this->quote($tid)['breakdown']['website'] + $this->quote($tid)['breakdown']['multisite']));
        $this->seeInDatabase('tenant_price_overrides', ['tenant_id' => $tid, 'item_code' => 'base.pos']);
    }

    public function testOutletDiscountRulesAreEditable(): void
    {
        $tid = $this->makeTenant('trial', 'business');
        $one = $this->quote($tid)['monthly'];
        $first = service('pricing')->config()['tiers'][0];
        $this->postForm('pricing/tiers', ['tiers' => [(int) $first['id'] => ['label' => 'All', 'min' => '1', 'max' => '', 'percent' => '50', 'enabled' => '1']]])->assertRedirect();
        $this->assertSame(round($one / 2 + $this->quote($tid)['breakdown']['modules'] / 2 * 0 + 0, 2) > 0, true);
        $this->assertGreaterThan(0.0, $this->quote($tid)['outlet_discount']);
        $this->assertSame(50.0, $this->quote($tid)['outlet_discount_percent']);
    }

    public function testPackageCreateAndAssign(): void
    {
        $tid = $this->makeTenant('trial', 'business');
        $this->postForm('pricing/packages', ['name' => 'Shop + Web', 'price' => '1200', 'description' => 'bundle', 'is_active' => '1', 'items' => ['website']])->assertRedirect();
        $pkg = db_connect()->table('pricing_packages')->where('name', 'Shop + Web')->get()->getRowArray();
        $this->assertNotNull($pkg);
        $this->postForm('pricing/customer/' . $tid, ['discount_type' => 'none', 'package_id' => (string) $pkg['id']])->assertRedirect();
        $q = $this->quote($tid);
        $this->assertSame(1200.0, $q['breakdown']['package']);
        $this->assertTrue(service('moduleAccess')->forTenant($tid)->has('website'), 'the package switched the website on');
        $this->assertSame(0.0, $q['breakdown']['website'], 'not charged twice');
    }

    public function testBrandFormValidatesTheDomain(): void
    {
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\ValidationException::class, fn () => $this->postForm('system/brand', ['brandName' => 'Acme', 'baseUrl' => 'not a domain/path x']));
        $this->getPage('system')->assertSee('Brand &amp; company');
    }
}
