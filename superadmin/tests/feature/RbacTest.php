<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Route filters (can:<permission>) enforce the platform roles.
 *
 * @internal
 */
final class RbacTest extends FeatureTestCase
{
    public function testSalesCannotOpenRestrictedPages(): void
    {
        foreach (['plans', 'modules', 'billing', 'subscriptions', 'servers', 'staff', 'system', 'audit', 'announcements'] as $uri) {
            $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('sales')->getPage($uri));
        }
        $this->as('sales')->getPage('tenants')->assertOK();
        $this->as('sales')->getPage('tenants/new')->assertOK();
    }

    public function testSalesCannotChangeAnAccount(): void
    {
        $tid = $this->makeTenant();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('sales')->postForm('tenants/' . $tid . '/suspend', ['reason' => 'Other']));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('sales')->postForm('tenants/' . $tid . '/delete', ['confirm' => 'x']));
        $this->assertSame('trial', $this->subscription($tid)['status']);
        // but may add a support note
        $this->as('sales')->postForm('tenants/' . $tid . '/notes', ['body' => 'Called the owner'])->assertRedirect();
        $this->seeInDatabase('tenant_notes', ['tenant_id' => $tid, 'body' => 'Called the owner']);
    }

    public function testFinanceCanBillButNotMoveServersOrManageStaff(): void
    {
        $tid = $this->makeTenant();
        $this->as('finance')->getPage('billing')->assertOK();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('finance')->postForm('tenants/' . $tid . '/server', ['server_id' => $this->serverId('pos-2'), 'reason' => 'x']));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('finance')->postForm('staff', ['name' => 'X', 'email' => 'x@example.test', 'role_id' => 1, 'password' => 'abcdefghijkl']));
    }

    public function testSupportCannotRunBilling(): void
    {
        $this->as('support')->getPage('servers')->assertOK();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('support')->postForm('billing/run'));
    }

    public function testNavigationShowsOnlyAllowedSections(): void
    {
        $res = $this->as('sales')->getPage('overview');
        $res->assertOK();
        $res->assertSee('Customers &amp; tenants');
        $res->assertDontSee('href="' . site_url('billing') . '"');
        $res->assertDontSee('Staff &amp; roles');
    }

    public function testLockedRoleCannotBeEdited(): void
    {
        $super = (int) db_connect()->table('platform_roles')->where('is_locked', 1)->get()->getRow('id');
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\BusinessRuleException::class, fn () => $this->postForm('staff/roles/' . $super, ['name' => 'Hacked', 'color' => '#000000']));
        $this->assertSame('Super Admin', db_connect()->table('platform_roles')->where('id', $super)->get()->getRow('name'));
    }

    public function testPermissionMatrixChangeAppliesToTheRole(): void
    {
        $support = (int) db_connect()->table('platform_roles')->where('code', 'support')->get()->getRow('id');
        $before = db_connect()->table('platform_roles')->where('id', $support)->get()->getRow('permissions');
        try {
            $this->postForm('staff/roles/permissions', ['perm' => [$support => ['overview.view', 'tenants.view', 'billing.view']]])->assertRedirect();
            $this->as('support')->getPage('billing')->assertOK();
            $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('support')->getPage('servers'));
        } finally {
            db_connect()->table('platform_roles')->where('id', $support)->update(['permissions' => $before]);
        }
    }
}
