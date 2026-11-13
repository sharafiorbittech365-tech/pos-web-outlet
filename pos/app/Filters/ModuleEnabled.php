<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Module must be enabled for the account (plan, add-on or Super Admin switch, with its needed
 * modules on): ['filter' => 'module:inventory'].
 */
class ModuleEnabled implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $access = service('posSession')->access();
        foreach ((array) $arguments as $module) {
            $access->assertModule((string) $module, (string) (service('catalog')->modules()[$module]['name'] ?? $module));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
