<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * Flexible module combinations (POS / Invoicing / Website), customer self-selection within Super
 * Admin's locks, feature-based pricing, live quotes, prorated charges and invoice lines.
 *
 * @internal
 */
final class ModuleSelectionTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        return $this->name() === 'testTrialChangesAreFreeUntilConversion' ? ['status' => 'trial'] : [];
    }

    protected function tearDown(): void
    {
        db_connect()->table('module_features')->where('code', 'purchases.reorder')->delete();
        db_connect()->table('modules')->update(['self_select' => 1]);
        service('catalog')->flush();
        parent::tearDown();
    }

    private function tid(): int
    {
        return $this->fx['tenant_id'];
    }

    private function access(): \OmniPOS\Core\Services\TenantAccess
    {
        \Config\Services::resetSingle('moduleAccess');

        return service('moduleAccess')->forTenant($this->tid());
    }

    /** Owner switches modules off / on through the Subscription selection form. */
    private function choose(array $modules = [], array $features = []): \CodeIgniter\Test\TestResponse
    {
        return $this->postForm('subscription/selection', ['modules' => array_map(static fn ($v): string => $v ? '1' : '0', $modules), 'features' => array_map(static fn ($v): string => $v ? '1' : '0', $features)]);
    }

    /** Menu paths the owner sees (PosNav through pos_nav_items()). */
    private function menu(): array
    {
        $s = session();
        $s->set('pos', ['tenant_id' => $this->tid(), 'business_id' => $this->fx['business_id'], 'user_id' => $this->fx['users']['owner']]);
        \Config\Services::resetSingle('posSession');
        \Config\Services::resetSingle('moduleAccess');
        helper('pos');

        return array_column(pos_nav_items(), 'path');
    }

    private function feature(int $price = 120, bool $default = false, bool $self = true): void
    {
        $now = date('Y-m-d H:i:s');
        db_connect()->table('module_features')->insert(['code' => 'purchases.reorder', 'module_code' => 'purchases', 'name' => 'Auto reorder', 'price' => $price,
            'is_default' => $default ? 1 : 0, 'self_select' => $self ? 1 : 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        service('catalog')->flush();
    }

    /** @return array<string, bool> route => reachable */
    private function reach(): array
    {
        $out = [];
        foreach (['sell' => 'sell', 'invoices' => 'invoices', 'website' => 'website', 'register' => 'register', 'orders' => 'orders'] as $k => $uri) {
            try {
                $this->getPage($uri)->assertOK();
                $out[$k] = true;
            } catch (ModuleDisabledException) {
                $out[$k] = false;
            }
        }

        return $out;
    }

    public function testEveryCombinationOfPosInvoicingAndWebsite(): void
    {
        $combos = [
            'pos only' => ['pos' => true, 'invoicing' => false, 'website' => false],
            'invoice only' => ['pos' => false, 'invoicing' => true, 'website' => false],
            'website only' => ['pos' => false, 'invoicing' => false, 'website' => true],
            'pos + website' => ['pos' => true, 'invoicing' => false, 'website' => true],
            'website + invoice' => ['pos' => false, 'invoicing' => true, 'website' => true],
            'pos + invoice' => ['pos' => true, 'invoicing' => true, 'website' => false],
            'all three' => ['pos' => true, 'invoicing' => true, 'website' => true],
        ];
        foreach ($combos as $name => $want) {
            // dependants of website first off (devapi, marketplace … need it) so the combination is valid
            $this->choose(['devapi' => $want['website'], 'marketplace' => $want['website'], 'multisite' => $want['website'], 'tableqr' => false] + $want)->assertRedirect();
            $a = $this->access();
            foreach ($want as $m => $on) {
                $this->assertSame($on, $a->has($m), $name . ': ' . $m);
            }
            $r = $this->reach();
            $this->assertSame($want['pos'], $r['sell'], $name . ': till route');
            $this->assertSame($want['pos'], $r['register'], $name . ': the register needs the till');
            $this->assertSame($want['invoicing'], $r['invoices'], $name . ': invoices route');
            $this->assertSame($want['website'], $r['website'], $name . ': website route');
            $this->assertTrue($r['orders'], $name . ': orders (website orders are processed there)');
            $menu = $this->menu();
            $this->assertSame($want['pos'], in_array('sell', $menu, true), $name . ': POS menu item');
            $this->assertSame($want['invoicing'], in_array('invoices', $menu, true), $name . ': Invoices menu item');
            $this->assertSame($want['website'], in_array('website', $menu, true), $name . ': Website menu item');
            // landing page after sign-in always exists: a role whose home is the till falls back to the dashboard
            $this->assertSame($want['pos'] ? 'sell' : 'dashboard', pos_home('pos'), $name . ': home');
        }
        // plan modules switched off by the customer cost nothing extra and nothing less (plan price)
        // (website is a priced item now, so switching it back on can bill the rest of the period — no invoice assertion)
        $this->assertGreaterThan(0, db_connect()->table('tenant_module_overrides')->where('tenant_id', $this->tid())->where('set_by_owner', 1)->countAllResults());
    }

    public function testQuoteAndAddModuleWithProration(): void
    {
        $price = (float) service('catalog')->modulePrice('purchases', 'electronics');
        $before = service('billing')->monthlyPrice($this->tid(), $this->fx['plan_id'], 'electronics');
        $q = json_decode($this->getPage('subscription/quote', ['modules' => ['purchases' => '1']])->getJSON(), true)['data'];
        $this->assertTrue($q['ok']);
        $this->assertEqualsWithDelta($before + $price, $q['monthly'], 0.01);
        $this->assertEqualsWithDelta($price, $q['delta'], 0.01);
        // fixture period: started 5 days ago, ends in 25 → about 83 % left
        $this->assertEqualsWithDelta($price * 25 / 30, $q['due_now'], 2);
        $this->assertSame(0, db_connect()->table('tenant_addons')->where('tenant_id', $this->tid())->countAllResults(), 'a quote saves nothing');

        $this->choose(['purchases' => true])->assertRedirect();
        $this->assertTrue($this->access()->has('purchases'));
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $this->tid())->where('status', 'open')->get()->getRowArray();
        $this->assertNotNull($inv);
        $this->assertEqualsWithDelta(round($price * 25 / 30), (float) $inv['amount'], 2);
        $lines = service('billing')->invoiceLines((int) $inv['id']);
        $this->assertCount(1, $lines);
        $this->assertSame('change', $lines[0]['kind']);
        $this->assertSame('purchases', $lines[0]['code']);
        $this->assertStringContainsString('Purchases', $lines[0]['label']);
        $this->assertSame(1, db_connect()->table('platform_audit')->where('tenant_id', $this->tid())->where('action', 'Modules selected')->countAllResults());
        $this->getPage('subscription/invoices/' . $inv['id'])->assertSee('Module: Purchases');

        // removing it again: the credit goes on the same open invoice of this period (never below zero)
        $this->choose(['purchases' => false])->assertRedirect();
        $inv2 = db_connect()->table('platform_invoices')->where('id', $inv['id'])->get()->getRowArray();
        $this->assertEqualsWithDelta(0.0, (float) $inv2['amount'], 0.01);
        $this->assertCount(2, service('billing')->invoiceLines((int) $inv['id']));
        $this->assertEqualsWithDelta($before, service('billing')->monthlyPrice($this->tid(), $this->fx['plan_id'], 'electronics'), 0.01);
    }

    public function testFeaturePricingAndRenewalLines(): void
    {
        $this->feature(120);
        // a feature needs its module
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->choose([], ['purchases.reorder' => true]));
        $this->assertStringContainsString('Add that module first', $e->getMessage());

        $q = json_decode($this->getPage('subscription/quote', ['modules' => ['purchases' => '1'], 'features' => ['purchases.reorder' => '1']])->getJSON(), true)['data'];
        $mod = (float) service('catalog')->modulePrice('purchases', 'electronics');
        $this->assertEqualsWithDelta($mod + 120, $q['delta'], 0.01);
        $this->assertContains('Feature: Auto reorder', array_column($q['lines'], 'label'));

        $this->choose(['purchases' => true], ['purchases.reorder' => true])->assertRedirect();
        $this->assertTrue($this->access()->hasFeature('purchases.reorder'));
        $this->assertSame(['purchases.reorder'], service('billing')->paidFeatures($this->tid(), $this->fx['plan_id'], 'electronics'));
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $this->tid())->where('status', 'open')->get()->getRowArray();
        $this->assertSame(['purchases', 'purchases.reorder'], array_column(service('billing')->invoiceLines((int) $inv['id']), 'code'));
        $this->getPage('subscription')->assertSee('Feature: Auto reorder');

        // renewal invoice: one line per plan, module and feature
        db_connect()->table('platform_invoices')->where('tenant_id', $this->tid())->update(['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('subscriptions')->where('tenant_id', $this->tid())->update(['period_start' => date('Y-m-d H:i:s', strtotime('-31 days')), 'period_end' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        service('billing')->run();
        $ren = db_connect()->table('platform_invoices')->where('tenant_id', $this->tid())->where('note', 'Renewal')->get()->getRowArray();
        $lines = service('billing')->invoiceLines((int) $ren['id']);
        $this->assertSame(['plan', 'module', 'module', 'module', 'feature'], array_column($lines, 'kind'), 'package, website, multi-outlet website, purchases, feature');
        $this->assertEqualsWithDelta((float) $ren['amount'], array_sum(array_map('floatval', array_column($lines, 'amount'))), 0.01);
    }

    public function testSelfSelectionLocks(): void
    {
        // catalogue: customers may not change the module
        db_connect()->table('modules')->where('code', 'purchases')->update(['self_select' => 0]);
        service('catalog')->flush();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->choose(['purchases' => true]));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->postForm('subscription/modules/purchases/add'));
        // Super Admin allows it for this customer only
        db_connect()->table('tenant_module_locks')->insert(['tenant_id' => $this->tid(), 'module_code' => 'purchases', 'self_select' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->choose(['purchases' => true])->assertRedirect();
        $this->assertTrue($this->access()->has('purchases'));
        // … and locks another one
        db_connect()->table('tenant_module_locks')->insert(['tenant_id' => $this->tid(), 'module_code' => 'invoicing', 'self_select' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->choose(['invoicing' => false]));
        $this->assertTrue($this->access()->has('invoicing'));
        // a Super Admin switch locks the module too
        db_connect()->table('tenant_module_overrides')->insert(['tenant_id' => $this->tid(), 'module_code' => 'website', 'is_enabled' => 0, 'set_by_owner' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->choose(['website' => true]));
        // a feature that only support may change
        $this->feature(50, false, false);
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->choose([], ['purchases.reorder' => true]));
        // the cashier cannot touch the subscription at all
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('subscription/selection', ['modules' => ['pos' => '0']]));
    }

    public function testDefaultFeatureCanBeSwitchedOffAndIsEnforced(): void
    {
        $this->assertTrue($this->access()->hasFeature('invoicing.mushak'));
        $this->getPage('mushak/settings')->assertOK();
        $this->choose([], ['invoicing.mushak' => false])->assertRedirect();
        $this->assertFalse($this->access()->hasFeature('invoicing.mushak'));
        $this->assertThrowsOmni(ModuleDisabledException::class, fn () => $this->getPage('mushak/settings'));
        $this->choose([], ['invoicing.mushak' => true])->assertRedirect();
        $this->assertSame(0, db_connect()->table('tenant_features')->where('tenant_id', $this->tid())->countAllResults(), 'back to the default');
    }

    public function testTrialChangesAreFreeUntilConversion(): void
    {
        $this->choose(['purchases' => true])->assertRedirect();
        $this->assertTrue($this->access()->has('purchases'));
        $this->assertSame(0, db_connect()->table('platform_invoices')->where('tenant_id', $this->tid())->countAllResults());
        // converting the trial bills the add-on as its own line
        $inv = service('billing')->start($this->tid(), $this->fx['plan_id'], 'monthly');
        $lines = service('billing')->invoiceLines((int) $inv['id']);
        $this->assertSame('plan', $lines[0]['kind'], 'the base package comes first');
        $this->assertContains('purchases', array_column($lines, 'code'));
    }
}
