<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Super Admin side of flexible module selection: feature catalogue & prices, self-select flags and
 * per-customer locks, customer switches kept, invoice lines on the billing invoice.
 *
 * @internal
 */
final class ModuleSelectionAdminTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        db_connect()->table('module_features')->where('code', 'invoicing.recurring')->delete();
        db_connect()->table('modules')->update(['self_select' => 1]);
        service('catalog')->flush();
        parent::tearDown();
    }

    public function testFeatureCatalogueAndPrices(): void
    {
        $this->getPage('modules', ['type' => 'grocery'])->assertSee('Features &amp; feature prices');
        $this->postForm('modules/features', ['code' => 'invoicing.recurring', 'module_code' => 'invoicing', 'name' => 'Recurring invoices', 'price' => '150',
            'self_select' => '1', 'is_default' => '0', 'type' => 'grocery', 'type_price' => '90'])->assertRedirect();
        service('catalog')->flush();
        $this->assertEqualsWithDelta(90.0, service('catalog')->featurePrice('invoicing.recurring', 'grocery'), 0.01, 'per-type price');
        $this->assertEqualsWithDelta(150.0, service('catalog')->featurePrice('invoicing.recurring', 'salon'), 0.01, 'flat price');
        $this->postForm('modules/features/invoicing.recurring', ['module_code' => 'invoicing', 'name' => 'Recurring invoices', 'price' => '150', 'type' => 'salon', 'not_offered' => '1'])->assertRedirect();
        service('catalog')->flush();
        $this->assertNull(service('catalog')->featurePrice('invoicing.recurring', 'salon'));
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/features', ['code' => 'website.recurring', 'module_code' => 'invoicing', 'name' => 'X', 'price' => '1']));
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/features', ['code' => 'invoicing.recurring', 'module_code' => 'invoicing', 'name' => 'Dup', 'price' => '1']));
        $this->postForm('modules/features/invoicing.recurring/delete')->assertRedirect();
        $this->assertSame(0, db_connect()->table('module_features')->where('code', 'invoicing.recurring')->countAllResults());

        // module self-select flag from the edit dialog
        $this->postForm('modules/purchases', ['name' => 'Purchases & suppliers', 'is_opt_in' => '1', 'self_select' => '0'])->assertRedirect();
        $this->assertSame(0, (int) db_connect()->table('modules')->select('self_select')->where('code', 'purchases')->get()->getRow('self_select'));
    }

    public function testLocksAndCustomerSwitchesOnTheTenantModulesTab(): void
    {
        $tid = $this->makeTenant('trial', 'business', 'electronics');
        // the customer switched invoicing off themselves
        db_connect()->table('tenant_module_overrides')->insert(['tenant_id' => $tid, 'module_code' => 'invoicing', 'is_enabled' => 0, 'set_by_owner' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->getPage('tenants/' . $tid, ['tab' => 'modules'])->assertSee('Switched off by the customer');

        $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['invoicing' => 'plan', 'website' => 'off', 'devapi' => 'off', 'marketplace' => 'off', 'multisite' => 'off'], 'lock' => ['pos' => 'lock', 'purchases' => 'allow']])->assertRedirect();
        $rows = array_column(db_connect()->table('tenant_module_overrides')->where('tenant_id', $tid)->get()->getResultArray(), null, 'module_code');
        $this->assertSame(1, (int) $rows['invoicing']['set_by_owner'], 'the customer switch is kept');
        $this->assertSame(0, (int) $rows['website']['set_by_owner']);
        $locks = array_column(db_connect()->table('tenant_module_locks')->where('tenant_id', $tid)->get()->getResultArray(), 'self_select', 'module_code');
        $this->assertSame(['pos' => 0, 'purchases' => 1], array_map('intval', $locks));
        $this->assertGreaterThan(0, db_connect()->table('platform_audit')->where('tenant_id', $tid)->like('detail', 'pos self-select lock')->countAllResults());

        // back to the default
        $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['invoicing' => 'plan'], 'lock' => ['pos' => 'default', 'purchases' => 'default']])->assertRedirect();
        $this->assertSame(0, db_connect()->table('tenant_module_locks')->where('tenant_id', $tid)->countAllResults());
    }

    public function testBillingInvoiceShowsLines(): void
    {
        $tid = $this->makeTenant("trial", "business", "grocery");   // grocery trial: purchases is added as a recommended add-on
        $inv = service('billing')->start($tid, $this->planId('business'), 'monthly');
        $lines = service('billing')->invoiceLines((int) $inv['id']);
        $this->assertSame(['plan', 'module', 'module'], array_column($lines, 'kind'), 'package, website, purchases');
        $res = $this->getPage('billing/invoices/' . $inv['id']);
        $res->assertOK();
        $res->assertSee('Module: Purchases');
    }
}
