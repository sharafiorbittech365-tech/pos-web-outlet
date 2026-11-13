<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Market;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * Authentication of /v1/market/*: the Marketplace website's server only.
 *
 *   Authorization: Bearer <market.apiToken>     (MARKETPLACE_API_TOKEN in apps/marketplace_next)
 *
 * Any browser Origin header is refused (403): the token must never reach a browser. With no token
 * configured every request is refused, so a fresh install is closed until it is set up.
 */
final class MarketAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if ($request->getHeaderLine('Origin') !== '') {
            throw new PermissionDeniedException('The Marketplace API is server-side only. Call it from the Marketplace website\'s server.');
        }
        $expected = config(Market::class)->apiToken;
        if ($expected === '') {
            log_message('critical', 'Marketplace API: market.apiToken (MARKETPLACE_API_TOKEN) is not set — every request is refused.');

            throw new AuthenticationException('This API is not configured yet.');
        }
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $request->getHeaderLine('Authorization'), $m) === 1 ? $m[1] : '';
        if ($bearer === '' || ! hash_equals($expected, $bearer)) {
            throw new AuthenticationException('Invalid or missing API token. Send "Authorization: Bearer <token>".');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // per-visitor data (cart, buyer): never cached by anything in between
        $response->setHeader('Cache-Control', 'no-store');

        return $response;
    }
}
