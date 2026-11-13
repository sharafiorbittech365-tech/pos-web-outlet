<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Http\ExceptionHandler;

/**
 * The device is signed in to a workspace and an outlet (owner email + password), but nobody has
 * to be at the till: the PIN lock / switch-user screen. Binds the tenant context without a user.
 *
 *   ['filter' => ['posdevice', 'tenantserver']]
 */
class PosDevice implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $s = service('posSession');
        if ($s->tenantId() === 0 || $s->businessId() === 0 || $s->business() === []) {
            session()->remove('pos');
            if (ExceptionHandler::wantsJson($request)) {
                throw new AuthenticationException();
            }

            return redirect()->to(site_url('login'));
        }
        service('tenant')->set($s->tenantId(), $s->businessId(), null, 'POS');

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
