<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Http\ExceptionHandler;

/**
 * Platform staff must be signed in. The session holds only the staff id; the account and its
 * role are re-read (cached 60 s) so a disabled account or a changed role takes effect quickly.
 */
class AdminAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $id = (int) (session('admin_id') ?? 0);
        $admin = $id > 0 ? service('adminSession')->current() : null;
        if ($admin === null) {
            session()->remove('admin_id');
            if (ExceptionHandler::wantsJson($request)) {
                throw new AuthenticationException();
            }

            return redirect()->to(site_url('login'))->with('error', 'Please sign in.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
