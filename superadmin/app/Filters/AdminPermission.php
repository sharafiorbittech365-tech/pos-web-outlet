<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * RBAC for platform staff: ['filter' => 'can:billing.manage'] — every listed permission is required.
 */
class AdminPermission implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = service('adminSession');
        foreach ((array) $arguments as $perm) {
            if (! $session->can((string) $perm)) {
                throw new PermissionDeniedException('Your role cannot do this (' . $perm . ').');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
