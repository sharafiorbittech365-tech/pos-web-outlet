<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\PosAuthService;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\RateLimitException;
use OmniPOS\Core\Exceptions\TenantAccessDeniedException;

final class AuthController extends BaseController
{
    private function auth(): PosAuthService
    {
        return new PosAuthService();
    }

    /** "/" — the signed-in user's home, else sign-in. */
    public function home(): RedirectResponse
    {
        $s = service('posSession');
        if ($s->signedIn()) {
            return redirect()->to(site_url(pos_home($s->user()['home'] ?? null)));
        }
        if ($s->tenantId() > 0 && $s->businessId() > 0) {
            return redirect()->to(site_url('switch-user'));
        }

        return redirect()->to(site_url('login'));
    }

    public function login(): string|RedirectResponse
    {
        if (service('posSession')->signedIn()) {
            return redirect()->to(site_url('/'));
        }
        $moved = (string) $this->request->getGet('server') === 'moved';

        return view('App\Views\ws_layout', [
            'title' => 'Sign in', 'tab' => 'signin',
            'content' => view('App\Modules\Auth\Views\login', [
                'moved' => $moved, 'email' => (string) ($this->request->getGet('email') ?? ''),
                'server' => config(OmniPOS::class)->serverCode,
            ]),
        ]);
    }

    public function attempt(): RedirectResponse
    {
        $data = $this->validated([
            'email' => 'required|valid_email|max_length[160]',
            'password' => 'required|max_length[200]',
        ]);
        try {
            $r = $this->auth()->attempt($data['email'], $data['password'], $this->request->getIPAddress());
        } catch (AuthenticationException|RateLimitException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (TenantAccessDeniedException $e) {
            $url = (string) ($e->details()['server_url'] ?? '');
            if ($url !== '') {
                return redirect()->to(rtrim($url, '/') . '/login?' . http_build_query(['server' => 'moved', 'email' => mb_strtolower(trim($data['email']))]))
                    ->with('error', $e->getMessage() . ' Please sign in there.');
            }

            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
        $u = $r['user'];
        $this->auth()->start((int) $u['tenant_id'], $r['business_id'], (int) $u['id']);
        $s = service('posSession');
        $me = $s->user();
        if (($s->business()['settings']['setup'] ?? true) === false && $s->can('settings.manage')) {
            // outlet created empty (e.g. by Super Admin): a short set-up step first
            return redirect()->to(site_url('welcome'));
        }

        return redirect()->to(site_url(pos_home($me['home'] ?? null)))->with('success', 'Welcome, ' . strtok((string) $u['name'], ' ') . '.');
    }

    /** Set-up step for an outlet created without a template (settings.setup = false). */
    public function welcome(): string|RedirectResponse
    {
        $s = service('posSession');
        $b = $s->business();
        if (($b['settings']['setup'] ?? true) !== false) {
            return redirect()->to(site_url('dashboard'));
        }

        return $this->page('App\Modules\Auth\Views\welcome', [
            'title' => 'Set up your outlet', 'business' => $b, 'types' => (new \App\Modules\Auth\Services\SignupService())->businessTypes(),
            'hasPin' => db_connect()->table('users')->where('id', $s->userId())->where('pin_hash IS NOT NULL', null, false)->countAllResults() > 0,
        ]);
    }

    public function setup(): RedirectResponse
    {
        $data = $this->validated(['type' => 'required|alpha_dash|max_length[32]', 'samples' => 'permit_empty|in_list[0,1]', 'pin' => 'permit_empty|regex_match[/^\d{4}$/]'], ['pin' => ['regex_match' => 'The PIN must be 4 digits.']]);
        $s = service('posSession');
        if (! isset((new \App\Modules\Auth\Services\SignupService())->businessTypes()[$data['type']])) {
            throw new \OmniPOS\Core\Exceptions\ValidationException('Choose a business type.', ['type' => 'Unknown']);
        }
        $tid = $s->tenantId();
        $bid = $s->businessId();
        $uid = $s->userId();
        \OmniPOS\Core\Database\Tx::run(static function ($db) use ($data, $tid, $bid, $uid): void {
            (new \OmniPOS\Core\Services\OutletProvisioner())->applyTemplate($tid, $bid, $data['type'], ($data['samples'] ?? '') === '1');
            if (($data['pin'] ?? '') !== '') {
                $db->table('users')->where('id', $uid)->where('tenant_id', $tid)->update(['pin_hash' => password_hash((string) $data['pin'], PASSWORD_DEFAULT), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        });
        \App\Libraries\PosSession::forgetBusiness($bid);
        $s->reset();
        service('audit')->activity('Outlet set up', $data['type']);

        return redirect()->to(site_url('dashboard'))->with('success', 'Your outlet is ready.');
    }

    public function logout(): RedirectResponse
    {
        $this->auth()->logout();

        return redirect()->to(site_url('login'))->with('success', 'Signed out.');
    }

    /** PIN lock screen: choose your profile, enter your PIN. */
    public function switchUser(): string
    {
        $s = service('posSession');
        $b = $s->business();
        $today = db_connect()->table('orders')->where('business_id', $s->businessId())->where('created_at >=', date('Y-m-d 00:00:00'))
            ->whereNotIn('status', ['cancelled', 'held'])->countAllResults();

        return view('App\Modules\Auth\Views\switch_user', [
            'title' => 'Who\'s working today?',
            'business' => $b,
            'staff' => $this->auth()->staff($s->tenantId(), $s->businessId()),
            'current' => $s->userId(),
            'today' => $today,
            'demo' => ENVIRONMENT !== 'production',
        ]);
    }

    public function pin(): RedirectResponse
    {
        $data = $this->validated(['user_id' => 'required|is_natural_no_zero', 'pin' => 'required|regex_match[/^\d{4,6}$/]'], ['pin' => ['regex_match' => 'Enter your 4-digit PIN.']]);
        $s = service('posSession');
        try {
            $u = $this->auth()->switchTo($s->tenantId(), $s->businessId(), (int) $data['user_id'], (string) $data['pin'], $this->request->getIPAddress());
        } catch (AuthenticationException|RateLimitException $e) {
            return redirect()->to(site_url('switch-user') . '?u=' . (int) $data['user_id'])->with('error', $e->getMessage());
        }

        return redirect()->to(site_url(pos_home($u['home'] ?? null)))->with('success', 'Welcome, ' . strtok((string) $u['name'], ' ') . '.');
    }

    public function lock(): RedirectResponse
    {
        $this->auth()->lock();

        return redirect()->to(site_url('switch-user'));
    }

    public function outlets(): string
    {
        $s = service('posSession');

        return $this->page('App\Modules\Auth\Views\outlets', ['title' => 'Outlets', 'outlets' => $s->outlets(), 'current' => $s->businessId()]);
    }

    public function switchOutlet(): RedirectResponse
    {
        $data = $this->validated(['business_id' => 'required|is_natural_no_zero']);
        $o = $this->auth()->switchOutlet((int) $data['business_id']);
        $s = service('posSession');
        if (! $s->signedIn()) {
            // the person has no role in that outlet (cannot happen through outlets(), but stay safe)
            return redirect()->to(site_url('switch-user'));
        }

        return redirect()->to(site_url(pos_home($s->user()['home'] ?? null)))->with('success', 'Now in ' . $o['name'] . '.');
    }
}
