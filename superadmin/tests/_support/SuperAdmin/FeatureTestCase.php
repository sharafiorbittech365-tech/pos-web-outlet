<?php

declare(strict_types=1);

namespace Tests\Support\SuperAdmin;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use OmniPOS\Core\Database\Seeds\PlatformSeeder;

/**
 * Super Admin feature tests on omnipos_test_superadmin: the central schema is migrated once, the
 * platform catalogue (plans, modules, staff, POS servers) seeded once. Every test creates its own
 * accounts, so tests never depend on each other's data.
 *
 * Requests run as the seeded Super Admin (owner@omnipos.test) unless as('support'|'finance'|'sales').
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected const TOKEN = 'sa-test-token';

    protected $namespace = 'OmniPOS\Core';
    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = true;
    protected $seed = PlatformSeeder::class;
    protected $seedOnce = true;

    protected $setUpMethods = ['resetServices', 'resetFactories', 'mockCache', 'mockEmail', 'mockSession'];

    private string $who = 'owner';

    protected function setUp(): void
    {
        parent::setUp();
        $sec = config('Security');
        $sec->csrfProtection = 'session';
        $sec->tokenRandomize = false;
        service('catalog')->flush();
        $this->who = 'owner';
    }

    protected function as(string $who): static
    {
        $this->who = $who;

        return $this;
    }

    protected function adminId(string $who): int
    {
        return (int) db_connect()->table('platform_admins')->select('id')->where('email', $who . '@omnipos.test')->get()->getRow('id');
    }

    /** @return array{name: string, role: string, id: int} */
    protected function actor(string $who = 'owner'): array
    {
        return ['name' => ucfirst($who), 'role' => 'Test', 'id' => $this->adminId($who)];
    }

    private function sessionFor(): array
    {
        // every call is a new request: fresh CSRF holder, staff profile and module access memo
        foreach (['security', 'adminSession', 'moduleAccess', 'catalog'] as $svc) {
            \Config\Services::resetSingle($svc);
        }

        return ['admin_id' => $this->adminId($this->who), 'csrf_test_name' => self::TOKEN];
    }

    /**
     * Expected business errors propagate in feature tests (the exception handler turns them into
     * a redirect with a flash message in real requests).
     *
     * @template T of \Throwable
     * @param class-string<T> $class
     * @return T
     */
    protected function assertThrowsOmni(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e->getMessage());

            return $e;
        }
        $this->fail('Expected ' . $class . ' to be thrown.');
    }

    protected function getPage(string $uri, array $query = []): TestResponse
    {
        return $this->withSession($this->sessionFor())->call('get', $uri, $query);
    }

    protected function postForm(string $uri, array $data = []): TestResponse
    {
        array_walk_recursive($data, static function (&$v): void {
            $v = (string) $v;
        });

        return $this->withSession($this->sessionFor())->call('post', $uri, $data + ['csrf_test_name' => self::TOKEN]);
    }

    protected function planId(string $code): int
    {
        return (int) db_connect()->table('plans')->select('id')->where('code', $code)->get()->getRow('id');
    }

    /** Id of a POS server by code; a server other than the seeded pos-1 is created for the test (not taking new sign-ups). */
    protected function serverId(string $code): int
    {
        $db = db_connect();
        $id = $db->table('pos_servers')->select('id')->where('code', $code)->get()->getRow('id');
        if ($id === null) {
            $now = date('Y-m-d H:i:s');
            $db->table('pos_servers')->insert(['code' => $code, 'name' => strtoupper($code), 'base_url' => 'http://localhost:8092/', 'region' => 'dhaka',
                'capacity' => 500, 'status' => 'active', 'accepts_new' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $id = $db->insertID();
        }

        return (int) $id;
    }

    /** Create an account through the provisioning service. @return int tenant id */
    protected function makeTenant(string $start = 'trial', string $plan = 'business', string $type = 'grocery'): int
    {
        static $n = 0;
        $n++;
        $r = (new \App\Modules\Tenants\Services\TenantProvisioner())->create([
            'name' => 'Test Shop ' . $n . ' ' . bin2hex(random_bytes(2)), 'owner_name' => 'Owner ' . $n, 'owner_email' => 'owner' . $n . '.' . bin2hex(random_bytes(3)) . '@example.test',
            'owner_phone' => '01700-0000' . $n, 'business_type' => $type, 'plan_id' => $this->planId($plan), 'billing_cycle' => 'monthly', 'start' => $start,
        ], $this->actor());

        return $r['tenant_id'];
    }

    /** @return array<string, mixed> */
    protected function subscription(int $tenantId): array
    {
        return db_connect()->table('subscriptions')->where('tenant_id', $tenantId)->get()->getRowArray();
    }
}
