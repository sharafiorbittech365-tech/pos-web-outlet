<?php

declare(strict_types=1);

use OmniPOS\Core\Database\Seeds\PlatformSeeder;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Super Admin › Modules: editing dependency & compatibility rules and per-type prices, recommended
 * modules per business type (applied on provisioning), and validated module assignment per tenant.
 *
 * @internal
 */
final class ModuleRulesAdminTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        $db = db_connect();
        $db->table('modules')->update(['conflicts' => null, 'requires_types' => null]);
        foreach (PlatformSeeder::MODULES as [$code, $name, , , $base, , $needs]) {
            $db->table('modules')->where('code', $code)->update(['name' => $name, 'needs' => json_encode($needs), 'base_price' => $base]);
        }
        foreach (PlatformSeeder::RECOMMENDED as $type => $list) {
            $db->table('business_types')->where('code', $type)->update(['recommended_modules' => json_encode($list)]);
        }
        service('catalog')->flush();
        parent::tearDown();
    }

    private function module(string $code): array
    {
        return db_connect()->table('modules')->where('code', $code)->get()->getRowArray();
    }

    public function testEditModuleRulesAndPrices(): void
    {
        $this->getPage('modules', ['type' => 'restaurant'])->assertSee('Dependencies &amp; compatibility');

        $this->postForm('modules/tasks', ['name' => 'Team tasks', 'description' => 'Tasks', 'base_price' => '120', 'rules' => '1',
            'needs' => ['customers'], 'conflicts' => ['loyalty'], 'types' => ['agency', 'printing'], 'tprices' => ['agency' => '150', 'salon' => '']])->assertRedirect();
        $m = $this->module('tasks');
        $this->assertSame(['customers'], json_decode((string) $m['needs'], true));
        $this->assertSame(['loyalty'], json_decode((string) $m['conflicts'], true));
        $this->assertSame(['agency', 'printing'], json_decode((string) $m['requires_types'], true));
        $this->assertEqualsWithDelta(120.0, (float) $m['base_price'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) db_connect()->table('module_prices')->where('module_code', 'tasks')->where('business_type', 'agency')->get()->getRow('monthly_price'), 0.001);
        $this->assertNull(db_connect()->table('module_prices')->where('module_code', 'tasks')->where('business_type', 'salon')->get()->getRow('monthly_price'));
        $this->assertGreaterThanOrEqual(1, db_connect()->table('platform_audit')->where('action', 'Module changed')->like('detail', 'tasks')->countAllResults());

        // conflicts are symmetric: unticking on the other side removes the pair
        $this->postForm('modules/loyalty', ['name' => 'Loyalty points', 'rules' => '1', 'needs' => ['customers'], 'conflicts' => []])->assertRedirect();
        $this->assertSame([], json_decode((string) $this->module('tasks')['conflicts'], true));

        // invalid rules are refused: loop, need + conflict, conflict with a core module, unknown code
        $loop = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/website', ['name' => 'Website', 'rules' => '1', 'needs' => ['products', 'marketplace']]));
        $this->assertStringContainsString('loop', $loop->details()['needs']);
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/tasks', ['name' => 'Team tasks', 'rules' => '1', 'needs' => ['customers'], 'conflicts' => ['customers']]));
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/tasks', ['name' => 'Team tasks', 'rules' => '1', 'conflicts' => ['products']]));
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/tasks', ['name' => 'Team tasks', 'rules' => '1', 'needs' => ['nosuchmodule']]));
        $this->assertSame(['customers'], json_decode((string) $this->module('tasks')['needs'], true), 'nothing saved on error');

        // the support role cannot edit the catalogue
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\PermissionDeniedException::class, fn () => $this->as('support')->postForm('modules/tasks', ['name' => 'X']));
    }

    public function testRecommendedModulesAreSavedAndAppliedOnProvisioning(): void
    {
        // tableqr needs floor + website: both come with it
        $this->postForm('modules/recommended', ['type' => 'salon', 'recommended' => ['tableqr', 'purchases']])->assertRedirect();
        $list = json_decode((string) db_connect()->table('business_types')->where('code', 'salon')->get()->getRow('recommended_modules'), true);
        $this->assertSame(['website', 'floor', 'tableqr', 'purchases'], $list);

        // a new trial account of the type gets the recommended module the plan lacks (purchases is opt-in)
        $tid = $this->makeTenant('trial', 'business', 'salon');
        $this->assertSame(['purchases'], array_column(db_connect()->table('tenant_addons')->where('tenant_id', $tid)->get()->getResultArray(), 'module_code'));
        $this->assertTrue(service('moduleAccess')->forTenant($tid)->has('purchases'));

        // conflicting recommendations are refused
        db_connect()->table('modules')->where('code', 'purchases')->update(['conflicts' => json_encode(['tableqr'])]);
        service('catalog')->flush();
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('modules/recommended', ['type' => 'salon', 'recommended' => ['tableqr', 'purchases']]));
    }

    public function testTenantModuleAssignmentIsValidated(): void
    {
        $tid = $this->makeTenant('trial', 'starter', 'grocery');
        // Starter lists Tables, but Tables is not offered for a grocery: off unless Super Admin switches it on
        $this->assertFalse(service('moduleAccess')->forTenant($tid)->has('floor'));
        $this->assertSame('Not offered for this business type.', service('moduleAccess')->forTenant($tid)->reason('floor'));
        // tableqr needs website, which the Starter plan does not include
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['tableqr' => 'on', 'floor' => 'on']]));
        $this->assertStringContainsString('Needs Website', $e->details()['mod']);
        $this->assertSame(0, db_connect()->table('tenant_module_overrides')->where('tenant_id', $tid)->countAllResults());

        // with its needs on, it is accepted
        $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['tableqr' => 'on', 'floor' => 'on', 'website' => 'on']])->assertRedirect();
        \Config\Services::resetSingle('moduleAccess');
        $this->assertTrue(service('moduleAccess')->forTenant($tid)->has('tableqr'));

        // conflicting modules cannot both be switched on; the tab shows why a module is off
        db_connect()->table('modules')->where('code', 'tasks')->update(['conflicts' => json_encode(['expenses'])]);
        service('catalog')->flush();
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['tableqr' => 'on', 'floor' => 'on', 'website' => 'on', 'tasks' => 'on', 'expenses' => 'on']]));
        $this->postForm('tenants/' . $tid . '/modules', ['mod' => ['tableqr' => 'on', 'floor' => 'on', 'website' => 'on', 'tasks' => 'on']])->assertRedirect();
        $this->getPage('tenants/' . $tid, ['tab' => 'modules'])->assertSee('Cannot run with');

        // a plan never bundles two conflicting modules
        $this->assertThrowsOmni(ValidationException::class, fn () => (new \App\Modules\Plans\Services\PlanAdmin($this->actor()))->save(null, ['name' => 'Bad', 'color' => '#111111', 'limit_outlets' => 1, 'limit_users' => 1, 'all_modules' => '0'], ['tasks', 'expenses'], []));
    }

    public function testSystemHealthPage(): void
    {
        $res = $this->getPage('system/health');
        $res->assertOK();
        $res->assertSee('Largest tables');
        $res->assertSee('Migrations');
        $json = json_decode($this->getPage('system/health', ['format' => 'json'])->getJSON(), true);
        $this->assertArrayHasKey('database', $json['data']['checks']);
        $this->assertSame('ok', $json['data']['checks']['database']['status']);
        $this->assertSame([], $json['data']['migrations']['pending']);
        $this->assertNotEmpty($json['data']['tables']);
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\PermissionDeniedException::class, fn () => $this->as('sales')->getPage('system/health'));
    }
}
