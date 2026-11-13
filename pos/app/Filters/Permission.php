<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * Role permission on a route: ['filter' => 'can:products.manage'] (all listed are required).
 * Permission keys: app/Config/PosPermissions.php.
 */
class Permission implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $s = service('posSession');
        foreach ((array) $arguments as $perm) {
            if (! $s->can((string) $perm)) {
                throw new PermissionDeniedException('Your role cannot do this. Ask the owner or a manager.');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
