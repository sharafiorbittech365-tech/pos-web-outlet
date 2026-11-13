<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * @internal
 */
final class TenantsTest extends FeatureTestCase
{
    public function testCreateTenantProvisionsAccountOutletOwnerAndServer(): void
    {
        $email = 'new.owner.' . bin2hex(random_bytes(3)) . '@example.test';
        $res = $this->postForm('tenants', [
            'name' => 'Rahim Grocery', 'owner_name' => 'Rahim Uddin', 'owner_email' => $email, 'owner_phone' => '01811-223344',
            'business_type' => 'grocery', 'plan_id' => $this->planId('business'), 'billing_cycle' => 'monthly', 'start' => 'trial',
        ]);
        $res->assertRedirect();
        $db = db_connect();
        $t = $db->table('tenants')->where('owner_email', $email)->get()->getRowArray();
        $this->assertNotNull($t);
        $tid = (int) $t['id'];
        $sub = $this->subscription($tid);
        $this->assertSame('trial', $sub['status']);
        $this->assertSame($this->planId('business'), (int) $sub['plan_id']);
        $this->assertNotNull($sub['trial_ends_at']);
        // owner role + owner user with a working one-time password
        $owner = $db->table('users')->where('tenant_id', $tid)->where('is_owner', 1)->get()->getRowArray();
        $otp = session('otp');
        $this->assertIsArray($otp);
        $this->assertSame($email, $otp['email']);
        $this->assertTrue(password_verify($otp['password'], (string) $owner['password_hash']));
        $this->assertSame(1, $db->table('roles')->where('tenant_id', $tid)->where('is_locked', 1)->countAllResults());
        // first outlet linked to the owner
        $biz = $db->table('businesses')->where('tenant_id', $tid)->get()->getResultArray();
        $this->assertCount(1, $biz);
        $this->assertSame(1, $db->table('user_businesses')->where('user_id', $owner['id'])->where('business_id', $biz[0]['id'])->countAllResults());
        // placed on a POS server, with history and audit
        $this->assertSame(1, $db->table('tenant_server_assignments')->where('tenant_id', $tid)->countAllResults());
        $this->seeInDatabase('server_assignment_history', ['tenant_id' => $tid, 'from_server_id' => null]);
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'Tenant created']);
        // the detail page shows the password once
        $res = $this->getPage('tenants/' . $tid);
        $res->assertOK();
        $res->assertSee('Rahim Grocery');
    }

    public function testDuplicateEmailIsRejectedAndNothingIsWritten(): void
    {
        $tid = $this->makeTenant();
        $email = db_connect()->table('tenants')->where('id', $tid)->get()->getRow('owner_email');
        $before = db_connect()->table('tenants')->countAllResults();
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('tenants', [
            'name' => 'Copy', 'owner_name' => 'Copy', 'owner_email' => $email, 'business_type' => 'grocery', 'plan_id' => $this->planId('starter'), 'billing_cycle' => 'monthly', 'start' => 'trial',
        ]));
        $this->assertSame($before, db_connect()->table('tenants')->countAllResults());
    }

    public function testNoServerCapacityRollsBackTheWholeAccount(): void
    {
        $db = db_connect();
        $db->table('pos_servers')->update(['accepts_new' => 0]);
        $before = $db->table('tenants')->countAllResults();
        try {
            $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->makeTenant());
            $this->assertSame($before, $db->table('tenants')->countAllResults());
        } finally {
            $db->table('pos_servers')->update(['accepts_new' => 1]);
        }
    }

    public function testStartAsPaidIssuesTheFirstInvoice(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $this->assertSame('past_due', $this->subscription($tid)['status']);
        $this->assertSame(1, db_connect()->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->countAllResults());
    }

    public function testEveryTabRenders(): void
    {
        $tid = $this->makeTenant();
        foreach (['overview', 'subscription', 'limits', 'modules', 'invoices', 'notes', 'server', 'actions'] as $tab) {
            $this->getPage('tenants/' . $tid, ['tab' => $tab])->assertOK();
        }
        $res = $this->getPage('tenants');
        $res->assertOK();
        $res->assertSee('Customers &amp; tenants');
    }

    public function testSuspendReactivateAndResetPassword(): void
    {
        $tid = $this->makeTenant();
        $this->postForm('tenants/' . $tid . '/suspend', ['reason' => 'Owner request', 'note' => 'Asked by phone'])->assertRedirect();
        $this->assertSame('suspended', $this->subscription($tid)['status']);
        $this->seeInDatabase('tenant_notes', ['tenant_id' => $tid, 'body' => 'Suspended: Asked by phone']);
        $this->postForm('tenants/' . $tid . '/reactivate')->assertRedirect();
        $this->assertSame('trial', $this->subscription($tid)['status'], 'a never-paid account goes back to its trial');

        $this->postForm('tenants/' . $tid . '/reset-password')->assertRedirect();
        $otp = session('otp');
        $hash = db_connect()->table('users')->where('tenant_id', $tid)->where('is_owner', 1)->get()->getRow('password_hash');
        $this->assertTrue(password_verify($otp['password'], (string) $hash));
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'Owner password reset']);
    }

    public function testLimitsAndModuleOverrides(): void
    {
        $tid = $this->makeTenant('trial', 'starter');
        $this->postForm('tenants/' . $tid . '/limits', ['limit_outlets' => '4', 'limit_users' => '', 'feature_csv' => 'on', 'feature_addons' => 'plan'])->assertRedirect();
        $t = db_connect()->table('tenants')->where('id', $tid)->get()->getRowArray();
        $this->assertSame(4, (int) $t['limit_outlets']);
        $this->assertNull($t['limit_users']);
        $this->assertSame(['csv' => true], json_decode((string) $t['feature_overrides'], true));

        $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['purchases' => 'on', 'reports' => 'off'], 'addons' => ['loyalty', 'customers']])->assertRedirect();
        $access = service('moduleAccess')->forTenant($tid);
        $this->assertTrue($access->has('purchases'));
        $this->assertFalse($access->has('reports'));
        $this->assertTrue($access->has('loyalty'), 'bought add-on');
        $this->assertSame(4, $access->limitOutlets);
        $this->assertTrue($access->feature('csv'));
    }

    public function testDeleteNeedsTheNameAndVoidsOpenInvoices(): void
    {
        $tid = $this->makeTenant('invoice', 'starter');
        $name = (string) db_connect()->table('tenants')->where('id', $tid)->get()->getRow('name');
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('tenants/' . $tid . '/delete', ['confirm' => 'wrong']));
        $this->postForm('tenants/' . $tid . '/delete', ['confirm' => $name])->assertRedirectTo(site_url('tenants'));
        $this->assertNotNull(db_connect()->table('tenants')->where('id', $tid)->get()->getRow('deleted_at'));
        $this->assertSame(0, db_connect()->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->countAllResults());
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'Tenant deleted']);
    }
}
