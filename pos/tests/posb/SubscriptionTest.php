<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\ModuleDisabledException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class SubscriptionTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        return $this->name() === 'testTrialConversionActivates' ? ['status' => 'trial'] : [];
    }

    public function testAddonChangesModuleAccess(): void
    {
        // purchases is opt-in: off on the Business plan until bought
        $this->assertFalse(service('moduleAccess')->forTenant($this->fx['tenant_id'])->has('purchases'));
        $this->assertThrowsOmni(ModuleDisabledException::class, fn () => $this->getPage('purchases'));

        $this->postForm('subscription/modules/purchases/add')->assertRedirect();
        $this->assertSame(1, db_connect()->table('tenant_addons')->where('tenant_id', $this->fx['tenant_id'])->where('module_code', 'purchases')->countAllResults());
        \Config\Services::resetSingle('moduleAccess');
        $this->assertTrue(service('moduleAccess')->forTenant($this->fx['tenant_id'])->has('purchases'));
        // prorated invoice for the rest of the period
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $this->fx['tenant_id'])->where('status', 'open')->get()->getRowArray();
        $this->assertNotNull($inv);
        $this->assertStringContainsString('Purchases', (string) $inv['note']);
        $this->getPage('purchases')->assertOK();

        $this->postForm('subscription/modules/purchases/remove')->assertRedirect();
        \Config\Services::resetSingle('moduleAccess');
        $this->assertFalse(service('moduleAccess')->forTenant($this->fx['tenant_id'])->has('purchases'));

        $this->getPage('subscription')->assertOK();
        $this->getPage('subscription/invoices/' . $inv['id'])->assertSee($inv['number']);
    }

    public function testTrialConversionActivates(): void
    {
        $plan = (int) db_connect()->table('plans')->where('code', 'starter')->get()->getRow('id');
        $this->postForm('subscription/plan', ['plan_id' => (string) $plan, 'cycle' => 'monthly'])->assertRedirect();
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $this->fx['tenant_id'])->where('status', 'open')->get()->getRowArray();
        $this->assertSame('Trial converted', $inv['note']);
        $this->getPage('subscription/pay', ['ids' => (string) $inv['id']])->assertOK();
        $this->postForm('subscription/pay', ['ids' => [(string) $inv['id']], 'method' => 'bKash', 'reference' => 'TRX12345', 'save' => '1'])->assertRedirect();
        $sub = db_connect()->table('subscriptions')->where('tenant_id', $this->fx['tenant_id'])->get()->getRowArray();
        $this->assertSame('active', $sub['status']);
        $this->assertSame($plan, (int) $sub['plan_id']);
        $this->assertSame('bKash', $sub['pay_method']);
        $this->assertSame('paid', db_connect()->table('platform_invoices')->where('id', $inv['id'])->get()->getRow('status'));
    }

    public function testDowngradeIsScheduledAndCancelAtPeriodEnd(): void
    {
        $starter = (int) db_connect()->table('plans')->where('code', 'starter')->get()->getRow('id');
        $this->postForm('subscription/plan', ['plan_id' => (string) $starter, 'cycle' => 'monthly'])->assertRedirect();
        $sub = db_connect()->table('subscriptions')->where('tenant_id', $this->fx['tenant_id'])->get()->getRowArray();
        $this->assertSame($starter, (int) $sub['next_plan_id']);
        $this->assertSame($this->fx['plan_id'], (int) $sub['plan_id']);
        $this->postForm('subscription/cancel')->assertRedirect();
        $this->assertSame($sub['period_end'], db_connect()->table('subscriptions')->where('tenant_id', $this->fx['tenant_id'])->get()->getRow('cancel_at'));
    }
}
