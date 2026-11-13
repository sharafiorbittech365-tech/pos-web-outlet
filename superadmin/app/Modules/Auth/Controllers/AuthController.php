<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Libraries\AdminSession;
use App\Modules\Auth\Services\AdminAuthService;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\RateLimitException;

final class AuthController extends BaseController
{
    public function login(): string|RedirectResponse
    {
        if (service('adminSession')->current() !== null) {
            return redirect()->to(site_url('overview'));
        }

        return view('App\Views\auth_layout', [
            'title' => 'Staff sign-in',
            'content' => view('App\Modules\Auth\Views\login'),
        ]);
    }

    public function attempt(): RedirectResponse
    {
        $data = $this->validated([
            'email' => 'required|valid_email|max_length[160]',
            'password' => 'required|max_length[200]',
        ]);
        try {
            $admin = (new AdminAuthService())->attempt($data['email'], $data['password'], $this->request->getIPAddress());
        } catch (AuthenticationException|RateLimitException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
        session()->regenerate(true);
        session()->set('admin_id', (int) $admin['id']);
        AdminSession::forget((int) $admin['id']);

        return redirect()->to(site_url('overview'));
    }

    public function logout(): RedirectResponse
    {
        $id = (int) (session('admin_id') ?? 0);
        if ($id > 0) {
            AdminSession::forget($id);
        }
        session()->destroy();

        return redirect()->to(site_url('login'))->with('success', 'Signed out.');
    }
}
