<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Controllers\BaseController;
use App\Modules\Auth\Services\PosAuthService;
use App\Modules\Auth\Services\SignupService;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Config\OmniPOS;

final class SignupController extends BaseController
{
    public function form(): string|RedirectResponse
    {
        if (service('posSession')->signedIn()) {
            return redirect()->to(site_url('/'));
        }
        $catalog = service('catalog');
        $plan = $catalog->planByCode((string) $catalog->setting('trialPlan', 'business'));

        return view('App\Views\ws_layout', [
            'title' => 'Free trial', 'tab' => 'signup',
            'content' => view('App\Modules\Auth\Views\signup', [
                'types' => (new SignupService())->businessTypes(),
                'trialDays' => (int) $catalog->setting('trialDays', 14),
                'planName' => (string) ($plan['name'] ?? 'Business'),
                'open' => (bool) $catalog->setting('signup', true),
            ]),
        ]);
    }

    public function create(): string|RedirectResponse
    {
        $data = $this->validated([
            'account' => 'required|min_length[2]|max_length[160]',
            'owner' => 'required|min_length[2]|max_length[100]',
            'email' => 'required|valid_email|max_length[160]',
            'phone' => 'permit_empty|regex_match[/^[0-9+\-\s]{6,20}$/]',
            'password' => 'required|min_length[6]|max_length[200]',
            'type' => 'required|alpha_dash|max_length[32]',
        ], ['phone' => ['regex_match' => 'Enter a valid mobile number.']]);
        $data['phone'] = trim((string) ($data['phone'] ?? ''));
        // same throttle as sign-in, so the form cannot be used to probe or flood
        service('loginThrottle')->check('pos', 'signup:' . $this->request->getIPAddress(), $this->request->getIPAddress());
        $r = (new SignupService())->register($data);
        service('loginThrottle')->record('pos', 'signup:' . $this->request->getIPAddress(), $this->request->getIPAddress(), true, $r['user_id']);

        $srv = $r['server'];
        if ($srv['code'] !== config(OmniPOS::class)->serverCode) {
            // the account was placed on another POS server: sign in there
            return view('App\Views\ws_layout', ['title' => 'Workspace ready', 'tab' => '', 'content' => view('App\Modules\Auth\Views\signup_done', [
                'server' => $srv, 'email' => mb_strtolower(trim($data['email'])), 'pin' => SignupService::FIRST_PIN,
            ])]);
        }
        (new PosAuthService())->start($r['tenant_id'], $r['business_id'], $r['user_id']);

        return redirect()->to(site_url('dashboard'))->with('success', 'Your workspace is ready. Your till PIN is ' . SignupService::FIRST_PIN . ' — change it in Users & roles.');
    }
}
