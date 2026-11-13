<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\SubscriptionInactiveException;
use OmniPOS\Core\Http\ExceptionHandler;

/**
 * Signed-in workspace required. Binds the tenant context for every model and service.
 * A locked subscription (suspended / expired / cancelled) only reaches the Subscription pages
 * and sign-out, so the owner can pay and come back — like the design build.
 *
 * Use together with 'tenantserver' (this POS server must serve the tenant):
 *   ['filter' => ['posauth', 'tenantserver']]
 */
class PosAuth implements FilterInterface
{
    private const ALLOWED_WHEN_LOCKED = ['subscription', 'logout', 'lock', 'switch-user'];

    public function before(RequestInterface $request, $arguments = null)
    {
        $s = service('posSession');
        if (! $s->signedIn()) {
            session()->remove('pos');
            if (ExceptionHandler::wantsJson($request)) {
                throw new AuthenticationException();
            }

            return redirect()->to(site_url('login'));
        }
        $s->bindContext();

        $access = $s->access();
        if ($access->isLocked()) {
            $first = explode('/', trim($request->getUri()->getPath(), '/'))[0] ?? '';
            if (! in_array($first, self::ALLOWED_WHEN_LOCKED, true)) {
                if (ExceptionHandler::wantsJson($request)) {
                    $access->assertActive();
                }

                return redirect()->to(site_url('subscription'))->with('error', (new SubscriptionInactiveException(match ($access->status) {
                    'expired' => 'Your free trial has ended. Choose a plan to keep selling.',
                    'cancelled' => 'This subscription was cancelled. Restart it to keep selling.',
                    default => 'This account is suspended. Pay the open invoice to continue.',
                }))->getMessage());
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
