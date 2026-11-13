<?php

declare(strict_types=1);

namespace Tests\Support\PosB;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use OmniPOS\Core\Database\Seeds\PlatformSeeder;

/**
 * Back-office feature tests: the central schema migrated once into omnipos_test_posb, the platform
 * catalogue seeded, and a fresh workspace (tenant, outlet, owner + cashier, category) per test.
 * Requests run as the owner unless as('cashier') is used.
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'OmniPOS\Core';
    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = true;
    protected $seed = PlatformSeeder::class;
    protected $seedOnce = true;

    /** fresh shared services per test (security token, tenant context, module access memo) */
    protected $setUpMethods = ['resetServices', 'resetFactories', 'mockCache', 'mockEmail', 'mockSession'];

    /** @var array<string, mixed> */
    protected array $fx = [];
    private string $who = 'owner';

    protected function setUp(): void
    {
        parent::setUp();
        // a plain (non-randomised) session token, posted back by postForm()
        $sec = config('Security');
        $sec->csrfProtection = 'session';
        $sec->tokenRandomize = false;
        service('catalog')->flush();
        $this->fx = Fixture::workspace($this->fixtureOptions());
        $this->who = 'owner';
    }

    /** @return array<string, mixed> overrides for Fixture::workspace() */
    protected function fixtureOptions(): array
    {
        return [];
    }

    protected function as(string $who): static
    {
        $this->who = $who;

        return $this;
    }

    /** Bind the tenant context like the PosAuth filter does (for direct service calls). */
    protected function bind(?string $who = null): void
    {
        $u = $this->fx['users'][$who ?? $this->who];
        service('tenant')->set($this->fx['tenant_id'], $this->fx['business_id'], $u, ucfirst($who ?? $this->who));
    }

    private function sessionFor(): array
    {
        // every call is a new request: fresh CSRF token holder, session profile and module access
        foreach (["security", "posSession", "moduleAccess", "tenant"] as $svc) {
            \Config\Services::resetSingle($svc);
        }

        return ['pos' => ['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'user_id' => $this->fx['users'][$this->who]], 'csrf_test_name' => 'posb-test-token'];
    }

    /**
     * Expected business errors propagate in feature tests (the app's exception handler turns them
     * into a redirect / JSON envelope in real requests). Returns the exception for more checks.
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
        // real form posts carry strings only
        array_walk_recursive($data, static function (&$v): void {
            $v = (string) $v;
        });

        return $this->withSession($this->sessionFor())->call('post', $uri, $data + ['csrf_test_name' => 'posb-test-token']);
    }
}
