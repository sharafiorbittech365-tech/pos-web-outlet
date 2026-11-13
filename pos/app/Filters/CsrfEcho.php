<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CSRF tokens are regenerated after every verified POST. AJAX callers (the till, the kitchen
 * board) read the fresh token from this response header and send it with their next POST.
 */
class CsrfEcho implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (strtoupper($request->getMethod()) === 'POST' || $request->getHeaderLine('X-Requested-With') !== '') {
            $response->setHeader('X-CSRF-TOKEN', csrf_hash());
        }

        return null;
    }
}
