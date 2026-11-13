<?php

declare(strict_types=1);

use App\Modules\Subscriptions\Services\MrrCalculator;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Plan changes, trial conversion, payments and MRR.
 *
 * @internal
 */
final class SubscriptionTest extends FeatureTestCase
{
    public function testPlanChangeAppliesImmediatelyAndIsAudited(): void
    {
        $tid = $this->makeTenant('trial', 'starter');
        $this->postForm('tenants/' . $tid . '/subscription', [
            'plan_id' => $this->planId('enterprise'), 'business_type' => 'grocery', 'billing_cycle' => 'yearly', 'ends_on' => date('Y-m-d', strtotime('+30 days')), 'next_plan_id' => '',
        ])->assertRedirect();
        $sub = $this->subscription($tid);
        $this->assertSame($this->planId('enterprise'), (int) $sub['plan_id']);
        $this->assertSame('yearly', $sub['billing_cycle']);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), substr((string) $sub['trial_ends_at'], 0, 10));
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'Subscription changed']);
        $this->assertSame('Enterprise', service('moduleAccess')->forTenant($tid)->plan['name']);
    }

    public function testPlanNotOfferedForTheTypeIsRefused(): void
    {
        $tid = $this->makeTenant('trial', 'starter');
        // this is the legacy per-plan price list: it applies while the pricing engine has no base package row
        $db = db_connect();
        $base = $db->table('pricing_items')->where('code', 'base.pos')->get()->getRowArray();
        $db->table('pricing_items')->where('code', 'base.pos')->delete();
        service('pricing')->flush();
        $db->table('plan_prices')->where('plan_id', $this->planId('enterprise'))->where('business_type', 'grocery')->update(['monthly_price' => null]);
        service('catalog')->flush();
        try {
            $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('tenants/' . $tid . '/subscription', [
                'plan_id' => $this->planId('enterprise'), 'business_type' => 'grocery', 'billing_cycle' => 'monthly',
            ]));
            $this->assertSame($this->planId('starter'), (int) $this->subscription($tid)['plan_id']);
        } finally {
            db_connect()->table('plan_prices')->where('plan_id', $this->planId('enterprise'))->where('business_type', 'grocery')->update(['monthly_price' => 4000]);
            db_connect()->table('pricing_items')->insert($base);
            service('pricing')->flush();
        }
    }

    public function testConvertTrialThenRecordPaymentActivates(): void
    {
        $tid = $this->makeTenant('trial', 'business');
        $this->postForm('tenants/' . $tid . '/convert', ['plan_id' => $this->planId('business'), 'billing_cycle' => 'monthly', 'pay_now' => '0'])->assertRedirect();
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->get()->getRowArray();
        $this->assertNotNull($inv);
        $this->assertSame('Trial converted', $inv['note']);
        $this->assertSame('trial', $this->subscription($tid)['status'], 'not active until paid');

        $this->postForm('billing/invoices/' . $inv['id'] . '/pay', ['method' => 'bKash', 'reference' => 'TRX-1'])->assertRedirect();
        $sub = $this->subscription($tid);
        $this->assertSame('active', $sub['status']);
        $this->assertNull($sub['trial_ends_at']);
        $this->assertSame((string) $inv['period_to'], (string) $sub['period_end']);
        $this->seeInDatabase('platform_payments', ['invoice_id' => $inv['id'], 'method' => 'bKash', 'reference' => 'TRX-1']);
        $this->seeInDatabase('platform_invoices', ['id' => $inv['id'], 'status' => 'paid']);
    }

    public function testPayingTheOpenInvoiceClearsPastDue(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->get()->getRowArray();
        $this->postForm('billing/invoices/' . $inv['id'] . '/pay', ['method' => 'Cash'])->assertRedirect();
        $this->assertSame('active', $this->subscription($tid)['status']);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('billing/invoices/' . $inv['id'] . '/pay', ['method' => 'Cash']));
    }

    public function testVoidLastOpenInvoiceMakesAccountActive(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $inv = db_connect()->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->get()->getRowArray();
        $this->postForm('billing/invoices/' . $inv['id'] . '/void')->assertRedirect();
        $this->seeInDatabase('platform_invoices', ['id' => $inv['id'], 'status' => 'void']);
        $this->assertSame('active', $this->subscription($tid)['status']);
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'Invoice voided']);
    }

    public function testBatchMrrMatchesBillingService(): void
    {
        $a = $this->makeTenant('invoice', 'starter');
        $b = $this->makeTenant('invoice', 'business', 'salon');
        db_connect()->table('tenant_addons')->insert(['tenant_id' => $a, 'module_code' => 'website', 'added_at' => date('Y-m-d H:i:s')]);
        db_connect()->table('subscriptions')->where('tenant_id', $b)->update(['billing_cycle' => 'yearly']);
        $mrr = (new MrrCalculator())->forTenants([$a, $b]);
        $billing = service('billing');
        foreach ([$a => 'grocery', $b => 'salon'] as $tid => $type) {
            $this->assertEqualsWithDelta($billing->mrr($this->subscription($tid), $type), $mrr[$tid], 0.001);
        }
        $this->assertGreaterThan(0, $mrr[$a]);
    }

    public function testBillingRunIsIdempotent(): void
    {
        $tid = $this->makeTenant('trial');
        db_connect()->table('subscriptions')->where('tenant_id', $tid)->update(['trial_ends_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->postForm('billing/run')->assertRedirect();
        $this->assertSame('expired', $this->subscription($tid)['status']);
        $count = db_connect()->table('platform_invoices')->countAllResults();
        $this->postForm('billing/run')->assertRedirect();
        $this->assertSame($count, db_connect()->table('platform_invoices')->countAllResults());
        $this->seeInDatabase('platform_audit', ['action' => 'Billing run']);
    }
}
