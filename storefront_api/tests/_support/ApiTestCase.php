<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\ApiSite;
use CodeIgniter\Config\Services;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockCache;
use CodeIgniter\Test\TestResponse;
use OmniPOS\Core\Database\Seeds\PlatformSeeder;
use OmniPOS\Core\Exceptions\OmniException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Http\ExceptionHandler;

/**
 * HTTP tests against the real routes, filters and controllers on the omnipos_test_sfapi database
 * (core schema migrated once per run, PlatformSeeder for plans / modules / business types).
 *
 * api() runs one request like the web server would: fresh request-scoped services, and
 * expected errors rendered by the core ExceptionHandler into the JSON envelope.
 */
abstract class ApiTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'OmniPOS\Core';
    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = true;
    protected $seed = PlatformSeeder::class;
    protected $seedOnce = true;

    protected string $gateway = '';

    protected function setUp(): void
    {
        // shared services keep per-process memos (module access per tenant id): start clean
        Services::reset(true);
        parent::setUp();
        $this->gateway = config(\OmniPOS\Core\Config\OmniPOS::class)->storefrontGatewayToken;
    }

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $json
     */
    protected function api(string $method, string $path, array $headers = [], ?array $json = null): TestResponse
    {
        // request-scoped state, as in a fresh PHP request
        Services::resetSingle('moduleAccess');
        Services::injectMock('apiCaller', new ApiCaller());
        Services::injectMock('apiSite', new ApiSite(service('apiCaller')));
        Services::injectMock('response', new Response(config('App')));
        // never touch the real (file) cache shared with the dev server
        $this->assertInstanceOf(MockCache::class, service('cache'));
        $headers += ['Accept' => 'application/json'];
        $req = $this->withHeaders($headers);
        if ($json !== null) {
            // raw JSON body (as a real client sends it), not form globals
            $req = $this->withHeaders($headers + ['Content-Type' => 'application/json'])->withBody((string) json_encode($json));
        }

        try {
            return $req->call($method, $path);
        } catch (OmniException | PageNotFoundException $e) {
            $response = service('response');
            ob_start();
            (new ExceptionHandler(config('Exceptions')))->handle($e, service('request'), $response, $e instanceof PageNotFoundException ? 404 : 500, 1);
            ob_end_clean();

            return new TestResponse($response);
        }
    }

    /** Hosted storefront call: gateway token + the shop's host (bare slug locally). */
    protected function storefront(Fixture $f, string $method, string $path, ?array $json = null, array $headers = []): TestResponse
    {
        return $this->api($method, $path, $headers + ['Authorization' => 'Bearer ' . $this->gateway, 'X-Storefront-Host' => $f->slug], $json);
    }

    /** @return array<string, mixed> */
    protected function body(TestResponse $r): array
    {
        return json_decode((string) $r->response()->getBody(), true) ?? [];
    }

    protected function assertError(TestResponse $r, int $status, string $code): void
    {
        $this->assertSame($status, $r->response()->getStatusCode(), (string) $r->response()->getBody());
        $this->assertSame($code, $this->body($r)['error']['code'] ?? null, (string) $r->response()->getBody());
        $this->assertNotEmpty($this->body($r)['error']['request_id'] ?? null);
    }
}
