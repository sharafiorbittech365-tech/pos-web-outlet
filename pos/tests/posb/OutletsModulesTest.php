<?php

declare(strict_types=1);

use App\Modules\Auth\Services\SignupService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * Outlet management (limits, details, recommended modules, cross-outlet report) and the module
 * dependency / compatibility rules on the owner's side (Subscription add / remove, module filter reason).
 *
 * @internal
 */
final class OutletsModulesTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        return match ($this->name()) {
            'testOutletLimitIsEnforcedOnTheBackend' => ['limit_outlets' => 1],
            'testNewOutletOnTrialGetsRecommendedModules' => ['status' => 'trial', 'limit_outlets' => 3],
            default => ['limit_outlets' => 3],
        };
    }

    protected function tearDown(): void
    {
        // module rules are platform rows shared by every test of the suite: put them back
        db_connect()->table('modules')->update(['conflicts' => null]);
        foreach (\OmniPOS\Core\Database\Seeds\PlatformSeeder::MODULES as [$code, , , , , , $needs, , $optIn]) {
            db_connect()->table('modules')->where('code', $code)->update(['needs' => json_encode($needs), 'is_opt_in' => (int) $optIn]);
        }
        service('catalog')->flush();
        parent::tearDown();
    }

    private function access(): \OmniPOS\Core\Services\TenantAccess
    {
        \Config\Services::resetSingle('moduleAccess');

        return service('moduleAccess')->forTenant($this->fx['tenant_id']);
    }

    public function testSignupSwitchesOnRecommendedModules(): void
    {
        $r = (new SignupService())->register(['account' => 'Rec Grocery', 'owner' => 'Rina', 'email' => 'rina' . bin2hex(random_bytes(3)) . '@example.test', 'phone' => '',
            'password' => 'secret1234', 'type' => 'grocery']);
        // trial on the Business plan: everything but opt-in modules is in the plan; purchases (opt-in, recommended for grocery) is added
        $this->assertSame(1, db_connect()->table('tenant_addons')->where('tenant_id', $r['tenant_id'])->where('module_code', 'purchases')->countAllResults());
        \Config\Services::resetSingle('moduleAccess');
        $a = service('moduleAccess')->forTenant($r['tenant_id']);
        $this->assertTrue($a->has('purchases'));
        $this->assertTrue($a->has('inventory'));
        $this->assertSame(1, db_connect()->table('platform_audit')->where('tenant_id', $r['tenant_id'])->where('action', 'Recommended modules added')->countAllResults());
    }

    public function testRecommendedModulesAreOnlySuggestedOnPaidPlans(): void
    {
        $res = (new \OmniPOS\Core\Services\RecommendedModules(service('catalog'), service('moduleAccess')))->apply($this->fx['tenant_id'], 'grocery');
        $this->assertSame([], $res['added'], 'nothing is bought automatically on a paid subscription');
        $this->assertContains('purchases', $res['suggested']);
        $this->assertSame(0, db_connect()->table('tenant_addons')->where('tenant_id', $this->fx['tenant_id'])->countAllResults());
    }

    public function testNewOutletOnTrialGetsRecommendedModules(): void
    {
        $this->postForm('settings/outlets', ['name' => 'Grocery Corner', 'type' => 'grocery', 'copy_team' => '1'])->assertRedirect();
        $this->assertSame(1, db_connect()->table('tenant_addons')->where('tenant_id', $this->fx['tenant_id'])->where('module_code', 'purchases')->countAllResults());
        $this->assertTrue($this->access()->has('purchases'));
        $this->getPage('settings/outlets')->assertSee('Grocery Corner');
    }

    public function testOutletLimitIsEnforcedOnTheBackend(): void
    {
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('settings/outlets', ['name' => 'One Too Many', 'type' => 'grocery']));
        $this->assertStringContainsString('1 outlet', $e->getMessage());
        $this->assertSame(0, db_connect()->table('businesses')->where('name', 'One Too Many')->countAllResults());

        // the core policy refuses too (any caller), and a closed outlet cannot be re-opened past the limit
        $this->bind();
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => \OmniPOS\Core\Database\Tx::run(fn () => (new \OmniPOS\Core\Services\OutletPolicy())->assertCanAdd($this->fx['tenant_id'])));
        $now = date('Y-m-d H:i:s');
        db_connect()->table('businesses')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_type' => 'grocery', 'name' => 'Closed Branch', 'slug' => 'closed-' . bin2hex(random_bytes(3)),
            'settings' => '{}', 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $closed = (int) db_connect()->insertID();
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('settings/outlets/' . $closed . '/open'));
        $this->assertSame(0, (int) db_connect()->table('businesses')->select('is_active')->where('id', $closed)->get()->getRow('is_active'));
    }

    public function testEditOutletDetails(): void
    {
        $this->postForm('settings/outlets/' . $this->fx['business_id'], ['name' => 'Main Branch', 'phone' => '01711-000000', 'area' => 'Banani', 'city' => 'Dhaka', 'address' => 'Road 11'])->assertRedirect();
        $b = db_connect()->table('businesses')->where('id', $this->fx['business_id'])->get()->getRowArray();
        $this->assertSame('Main Branch', $b['name']);
        $this->assertSame('Banani', $b['area']);
        $this->assertSame('Road 11', $b['address']);

        // another account's outlet is never found; staff without settings.manage cannot edit
        $other = \Tests\Support\PosB\Fixture::workspace();
        $this->assertThrowsOmni(NotFoundException::class, fn () => $this->postForm('settings/outlets/' . $other['business_id'], ['name' => 'Hijack']));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('settings/outlets/' . $this->fx['business_id'], ['name' => 'X']));
        $this->assertSame('Main Branch', db_connect()->table('businesses')->select('name')->where('id', $this->fx['business_id'])->get()->getRow('name'));
    }

    public function testCrossOutletReportRespectsOutletPermissions(): void
    {
        $this->postForm('settings/outlets', ['name' => 'Second Branch', 'type' => 'grocery', 'copy_team' => '0'])->assertRedirect();
        $second = (int) db_connect()->table('businesses')->select('id')->where('tenant_id', $this->fx['tenant_id'])->where('name', 'Second Branch')->get()->getRow('id');
        $now = date('Y-m-d H:i:s');
        // the template outlet has its own currency: align it, so the report can add the outlets up
        $first = db_connect()->table('businesses')->select('currency_symbol, currency_decimals')->where('id', $this->fx['business_id'])->get()->getRowArray();
        db_connect()->table('businesses')->where('id', $second)->update($first);
        \App\Libraries\PosSession::forgetBusiness($second);
        db_connect()->table('expenses')->insertBatch([
            ['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'category' => 'Rent', 'amount' => 1500, 'spent_at' => $now, 'created_at' => $now],
            ['tenant_id' => $this->fx['tenant_id'], 'business_id' => $second, 'category' => 'Rent', 'amount' => 700, 'spent_at' => $now, 'created_at' => $now],
        ]);

        $res = $this->getPage('reports/outlets', ['range' => 'today']);
        $res->assertOK();
        $res->assertSee('Second Branch');
        $res->assertSee('2,200');   // combined expenses (same currency)
        db_connect()->table('businesses')->where('id', $second)->update(['currency_symbol' => 'X$']);
        $this->getPage('reports/outlets', ['range' => 'today'])->assertSee('different currencies');
        db_connect()->table('businesses')->where('id', $second)->update($first);
        $one = $this->getPage('reports/outlets', ['range' => 'today', 'outlet' => (string) $second]);
        $one->assertSee('700');
        $one->assertDontSee('1,500');
        $csv = $this->getPage('reports/outlets', ['range' => 'today', 'csv' => '1']);
        $this->assertStringContainsString('Second Branch', $csv->getBody());

        // a manager with reports.view here and no reports in the second outlet only sees this outlet
        $db = db_connect();
        $db->table('roles')->insert(['tenant_id' => $this->fx['tenant_id'], 'code' => 'mgr', 'name' => 'Manager', 'is_locked' => 0, 'permissions' => json_encode(['reports.view', 'orders.view']), 'home' => 'dashboard', 'created_at' => $now, 'updated_at' => $now]);
        $mgr = (int) $db->insertID();
        $db->table('user_businesses')->where('user_id', $this->fx['users']['cashier'])->where('business_id', $this->fx['business_id'])->update(['role_id' => $mgr]);
        $db->table('user_businesses')->insert(['user_id' => $this->fx['users']['cashier'], 'business_id' => $second, 'role_id' => $this->fx['roles']['cashier']]);
        \App\Libraries\PosSession::forgetUser($this->fx['users']['cashier'], $this->fx['business_id']);
        $m = $this->as('cashier')->getPage('reports/outlets', ['range' => 'today']);
        $m->assertOK();
        $m->assertDontSee('Second Branch');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->getPage('reports/outlets', ['outlet' => (string) $second]));
    }

    public function testModuleFilterExplainsWhyAModuleIsOff(): void
    {
        $now = date('Y-m-d H:i:s');
        // floor + tableqr switched on (not offered to this business type otherwise), website off
        db_connect()->table('tenant_module_overrides')->insertBatch([
            ['tenant_id' => $this->fx['tenant_id'], 'module_code' => 'website', 'is_enabled' => 0, 'updated_at' => $now],
            ['tenant_id' => $this->fx['tenant_id'], 'module_code' => 'floor', 'is_enabled' => 1, 'updated_at' => $now],
            ['tenant_id' => $this->fx['tenant_id'], 'module_code' => 'tableqr', 'is_enabled' => 1, 'updated_at' => $now],
        ]);
        $this->assertFalse($this->access()->has('tableqr'));
        $e = $this->assertThrowsOmni(ModuleDisabledException::class, fn () => $this->getPage('floor/qr'));
        $this->assertStringContainsString('Needs Website', $e->getMessage());
    }

    public function testAddonMustBeCompatible(): void
    {
        // purchases (opt-in add-on) cannot run together with expenses (in the plan)
        db_connect()->table('modules')->where('code', 'purchases')->update(['conflicts' => json_encode(['expenses'])]);
        service('catalog')->flush();
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('subscription/modules/purchases/add'));
        $this->assertStringContainsString('Expenses', $e->getMessage());
        $this->assertSame(0, db_connect()->table('tenant_addons')->where('tenant_id', $this->fx['tenant_id'])->countAllResults());
    }

    public function testAddonThatOthersNeedCannotBeRemoved(): void
    {
        // loyalty becomes an opt-in add-on that needs purchases
        db_connect()->table('modules')->where('code', 'loyalty')->update(['is_opt_in' => 1, 'needs' => json_encode(['customers', 'purchases'])]);
        service('catalog')->flush();
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('subscription/modules/loyalty/add'));
        $this->assertStringContainsString('Add that module first', $e->getMessage());

        $this->postForm('subscription/modules/purchases/add')->assertRedirect();
        $this->postForm('subscription/modules/loyalty/add')->assertRedirect();
        $this->assertTrue($this->access()->has('loyalty'));
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('subscription/modules/purchases/remove'));
        $this->assertStringContainsString('Remove it first', $e->getMessage());
        $this->postForm('subscription/modules/loyalty/remove')->assertRedirect();
        $this->postForm('subscription/modules/purchases/remove')->assertRedirect();
        $this->assertFalse($this->access()->has('purchases'));
    }
}
