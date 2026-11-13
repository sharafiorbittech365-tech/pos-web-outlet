<?php

declare(strict_types=1);

namespace Tests\Support\PosA;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use OmniPOS\Core\Database\Seeds\PlatformSeeder;

/**
 * POS-A tests on omnipos_test_posa: schema migrated once, platform catalogue seeded once, a fresh
 * workspace per test (Fixture). act('cashier') switches the person at the till.
 */
abstract class TestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'OmniPOS\Core';
    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = false;
    protected $seed = PlatformSeeder::class;
    protected $seedOnce = true;
    protected $setUpMethods = ['resetServices', 'resetFactories', 'mockCache', 'mockEmail', 'mockSession'];

    /** @var array<string, mixed> */
    protected array $fx = [];
    protected string $who = 'owner';

    protected function setUp(): void
    {
        parent::setUp();
        $sec = config('Security');
        $sec->csrfProtection = 'session';
        $sec->tokenRandomize = false;
        service('catalog')->flush();
        // sign-in throttling counts per IP; every test run starts clean (test database only)
        db_connect()->table('auth_logins')->truncate();
        $this->fx = Fixture::workspace($this->businessType());
        $this->act('owner');
    }

    protected function businessType(): string
    {
        return 'grocery';
    }

    /** Sign the till in as owner / manager / cashier for direct service calls. */
    protected function act(string $who): void
    {
        $this->who = $who;
        foreach (['posSession', 'tenant', 'moduleAccess', 'orders', 'stock', 'ledger'] as $svc) {
            \Config\Services::resetSingle($svc);
        }
        $_SESSION['pos'] = $this->sessionPos();
        service('posSession')->reset();
        service('posSession')->bindContext();
        helper(['ui', 'pos']);
    }

    protected function sessionPos(): array
    {
        return ['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'user_id' => $this->fx['users'][$this->who]];
    }

    /** First active product of a stock type in the outlet. */
    protected function product(string $stockType = 'simple', float $minStock = 5): array
    {
        return db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('stock_type', $stockType)
            ->where('stock_qty >=', $minStock)->where('is_active', 1)->orderBy('id')->get()->getRowArray();
    }

    protected function namedCustomer(): array
    {
        return db_connect()->table('customers')->where('business_id', $this->fx['business_id'])->where('is_walk_in', 0)->orderBy('id')->get()->getRowArray();
    }

    protected function postForm(string $uri, array $data = [], ?array $pos = null): TestResponse
    {
        foreach (['security', 'posSession', 'moduleAccess', 'tenant'] as $svc) {
            \Config\Services::resetSingle($svc);
        }
        array_walk_recursive($data, static function (&$v): void {
            $v = (string) $v;
        });
        $session = ['csrf_test_name' => 'posa-test-token'];
        if ($pos !== []) {
            $session['pos'] = $pos ?? $this->sessionPos();
        }

        return $this->withSession($session)->call('post', $uri, $data + ['csrf_test_name' => 'posa-test-token']);
    }

    protected function getPage(string $uri): TestResponse
    {
        foreach (['security', 'posSession', 'moduleAccess', 'tenant'] as $svc) {
            \Config\Services::resetSingle($svc);
        }

        return $this->withSession(['pos' => $this->sessionPos(), 'csrf_test_name' => 'posa-test-token'])->call('get', $uri);
    }

    /**
     * @template T of \Throwable
     * @param class-string<T> $class
     */
    protected function assertThrowsOmni(string $class, callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, $e::class . ': ' . $e->getMessage());

            return $e;
        }
        $this->fail('Expected ' . $class . ' to be thrown.');
    }
}
