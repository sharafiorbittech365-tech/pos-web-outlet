<?php

declare(strict_types=1);

use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Every Super Admin page renders for the Super Admin, including filters and CSV exports, and the
 * catalogue editors write what they show.
 *
 * @internal
 */
final class PagesTest extends FeatureTestCase
{
    public function testEveryPageLoads(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $inv = (int) db_connect()->table('platform_invoices')->where('tenant_id', $tid)->get()->getRow('id');
        foreach (['overview', 'tenants', 'tenants/new', 'subscriptions', 'plans', 'plans/new', 'plans/' . $this->planId('business') . '/edit', 'modules', 'billing',
            'billing/payments', 'billing/invoices/' . $inv, 'servers', 'websites', 'announcements', 'staff', 'staff/roles', 'audit', 'system'] as $uri) {
            $this->getPage($uri)->assertOK();
        }
        foreach ([['tenants', ['status' => 'past_due', 'tenants_export' => 'csv']], ['subscriptions', ['due' => '30', 'subs_export' => 'csv']], ['billing', ['status' => 'all', 'invoices_export' => 'csv']],
            ['audit', ['audit_export' => 'csv']], ['websites', ['mode' => 'pending']], ['plans', ['type' => 'salon']], ['modules', ['type' => 'salon']]] as [$uri, $q]) {
            $this->getPage($uri, $q)->assertOK();
        }
    }

    public function testInvoicePrintViewShowsTheInvoice(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $tid)->get()->getRowArray();
        $res = $this->getPage('billing/invoices/' . $inv['id']);
        $res->assertOK();
        $res->assertSee($inv['number']);
        $res->assertSee('Billed to');
    }

    public function testPlanCrudAndPriceList(): void
    {
        $this->postForm('plans', ['code' => 'trial_test', 'name' => 'Trial Test', 'color' => '#123456', 'limit_outlets' => '2', 'limit_users' => '3', 'all_modules' => '0',
            'modules' => ['reports'], 'features' => ['csv'], 'is_active' => '1', 'is_popular' => '0'])->assertRedirect();
        $id = $this->planId('trial_test');
        $this->assertGreaterThan(0, $id);
        $this->postForm('plans/prices', ['type' => 'salon', 'prices' => [$id => ['salon' => '777', 'cafe' => '']]])->assertRedirect();
        service('catalog')->flush();
        // the legacy per-plan price list is still stored, but billing now uses Super Admin › Pricing (one package price)
        $this->assertSame(777.0, (float) db_connect()->table('plan_prices')->where('plan_id', $id)->where('business_type', 'salon')->get()->getRow('monthly_price'));
        $this->assertNull(db_connect()->table('plan_prices')->where('plan_id', $id)->where('business_type', 'cafe')->get()->getRow('monthly_price'));
        $this->assertSame(service('pricing')->basePrice(), service('catalog')->planPrice($id, 'salon'));
        $this->postForm('plans/' . $id . '/delete')->assertRedirect();
        $this->dontSeeInDatabase('plans', ['id' => $id]);
    }

    public function testSettingsAndAnnouncement(): void
    {
        $this->postForm('system/settings', ['name' => 'OmniPOS Cloud', 'supportEmail' => 'help@example.test', 'supportPhone' => '0961', 'trialDays' => '21', 'trialPlan' => 'business',
            'graceDays' => '5', 'vat' => '7.5', 'signup' => '1', 'yearlyMonths' => '10'])->assertRedirect();
        service('catalog')->flush();
        $this->assertSame(21, service('catalog')->setting('trialDays'));
        $this->assertSame(7.5, service('catalog')->setting('vat'));

        $this->postForm('announcements', ['title' => 'Hello tenants', 'body' => 'New feature', 'level' => 'info', 'audience' => 'plan:business', 'is_active' => '1'])->assertRedirect();
        $this->seeInDatabase('announcements', ['title' => 'Hello tenants', 'audience' => 'plan:business', 'is_active' => 1]);
        $res = $this->getPage('announcements');
        $res->assertOK();
        $res->assertSee('Hello tenants');
    }
}
