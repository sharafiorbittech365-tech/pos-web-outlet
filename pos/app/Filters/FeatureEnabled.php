<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A priced module feature (module_features) must be on for the account: its module is on and the
 * feature is included by default or selected under Subscription. ['filter' => 'feature:invoicing.mushak'].
 * (CodeIgniter keeps the dot inside the argument; only a second colon would split it.)
 */
class FeatureEnabled implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $access = service('posSession')->access();
        foreach ((array) $arguments as $code) {
            $access->assertFeature((string) $code, (string) (service('catalog')->features()[$code]['name'] ?? $code));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
