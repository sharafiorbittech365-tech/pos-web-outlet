<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * The `apimodule:<code>` route filter: an endpoint of a module the tenant does not have is 403
 * module_disabled, with the reason (e.g. switched off, needs another module).
 *
 * @internal
 */
final class ModuleFilterTest extends ApiTestCase
{
    public function testInventoryEndpointNeedsTheInventoryModule(): void
    {
        $f = Fixture::create();
        $key = ['Authorization' => 'Bearer ' . $f->secret];
        $this->api('GET', 'v1/inventory', $key)->assertStatus(200);
        $f->setModule('inventory', false);
        $res = $this->api('GET', 'v1/inventory', $key);
        $this->assertError($res, 403, 'module_disabled');
        $this->assertStringContainsString('Switched off', (string) $res->getJSON());
    }
}
