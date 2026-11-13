<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Libraries\PosSession;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\TenantAccessDeniedException;

/**
 * Workspace sign-in on this POS server (owner / manager email + password), the till's PIN lock
 * (switch user) and the outlet switcher. Session keys are documented in App\Libraries\PosSession.
 */
final class PosAuthService
{
    private const APP = 'pos';

    /**
     * Check email + password and that this POS server serves the account.
     *
     * @return array{user: array<string, mixed>, business_id: int}
     *
     * @throws AuthenticationException     wrong email / password (same message for both)
     * @throws TenantAccessDeniedException the account is on another POS server (details.server_url)
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $throttle = service('loginThrottle');
        $throttle->check(self::APP, $email, $ip);
        $db = db_connect();
        $u = $db->table('users u')->select('u.id, u.tenant_id, u.name, u.password_hash, u.is_active, u.locked_until')
            ->join('tenants t', 't.id = u.tenant_id')
            ->where('u.email', $email)->where('t.deleted_at', null)->get()->getRowArray();
        $ok = $u !== null && (bool) $u['is_active'] && $u['password_hash'] !== null
            && ($u['locked_until'] === null || strtotime((string) $u['locked_until']) < time())
            && password_verify($password, (string) $u['password_hash']);
        if (! $ok) {
            // constant-ish time for unknown users
            if ($u === null) {
                password_verify($password, '$2y$10$usesomesillystringforsalt$');
            }
            $throttle->record(self::APP, $email, $ip, false, isset($u['id']) ? (int) $u['id'] : null);

            throw new AuthenticationException('Wrong email or password.');
        }
        $tenantId = (int) $u['tenant_id'];
        service('servers')->assertServes($tenantId, config(OmniPOS::class)->serverCode);

        $bid = $this->firstOutlet((int) $u['id'], $tenantId);
        if ($bid === 0) {
            $throttle->record(self::APP, $email, $ip, false, (int) $u['id']);

            throw new AuthenticationException('Your user is not linked to any open outlet. Ask the owner to add you.');
        }
        $throttle->record(self::APP, $email, $ip, true, (int) $u['id']);
        $db->table('users')->where('id', $u['id'])->update(['last_login_at' => date('Y-m-d H:i:s'), 'failed_logins' => 0]);

        return ['user' => $u, 'business_id' => $bid];
    }

    /** First active outlet the user belongs to (0 = none). */
    public function firstOutlet(int $userId, int $tenantId): int
    {
        $row = db_connect()->table('user_businesses ub')->select('b.id')
            ->join('businesses b', 'b.id = ub.business_id')
            ->where('ub.user_id', $userId)->where('b.tenant_id', $tenantId)->where('b.is_active', 1)
            ->orderBy('b.id')->limit(1)->get()->getRowArray();

        return (int) ($row['id'] ?? 0);
    }

    /** Start the session (call after attempt()). */
    public function start(int $tenantId, int $businessId, int $userId): void
    {
        session()->regenerate(true);
        session()->set('pos', ['tenant_id' => $tenantId, 'business_id' => $businessId, 'user_id' => $userId]);
        PosSession::forgetUser($userId, $businessId);
        service('posSession')->reset();
        service('posSession')->bindContext();
        service('audit')->activity('Sign in', 'Workspace sign-in');
    }

    /** Lock the till: the device stays signed in, the person must enter a PIN. */
    public function lock(): void
    {
        $s = service('posSession');
        if ($s->userId() > 0) {
            service('audit')->activity('Lock', 'Till locked');
        }
        $this->put('user_id', 0);
    }

    /**
     * Staff who can unlock the till in the current outlet (PIN set, active).
     *
     * @return list<array<string, mixed>>
     */
    public function staff(int $tenantId, int $businessId): array
    {
        return db_connect()->table('users u')
            ->select('u.id, u.name, u.is_owner, (u.pin_hash IS NOT NULL) AS has_pin, r.name AS role, r.color, r.icon, r.home')
            ->join('user_businesses ub', 'ub.user_id = u.id')
            ->join('roles r', 'r.id = ub.role_id')
            ->where('ub.business_id', $businessId)->where('u.tenant_id', $tenantId)->where('u.is_active', 1)
            ->orderBy('r.is_locked', 'DESC')->orderBy('u.id')->get()->getResultArray();
    }

    /**
     * Switch the person at the till after checking their PIN (throttled per user and IP).
     *
     * @return array<string, mixed> the user (with role home)
     */
    public function switchTo(int $tenantId, int $businessId, int $userId, string $pin, string $ip): array
    {
        $ident = 'pin:' . $tenantId . ':' . $userId;
        $throttle = service('loginThrottle');
        $throttle->check(self::APP, $ident, $ip);
        $u = null;
        foreach ($this->staff($tenantId, $businessId) as $s) {
            if ((int) $s['id'] === $userId) {
                $u = $s;
            }
        }
        $hash = $u !== null ? db_connect()->table('users')->select('pin_hash')->where('id', $userId)->where('tenant_id', $tenantId)->get()->getRow('pin_hash') : null;
        if ($u === null || $hash === null || ! preg_match('/^\d{4,6}$/', $pin) || ! password_verify($pin, (string) $hash)) {
            $throttle->record(self::APP, $ident, $ip, false, $u ? $userId : null);

            throw new AuthenticationException('Wrong PIN.');
        }
        $throttle->record(self::APP, $ident, $ip, true, $userId);
        session()->regenerate(false);
        $this->put('user_id', $userId);
        service('posSession')->reset();
        PosSession::forgetUser($userId, $businessId);
        service('posSession')->bindContext();
        service('audit')->activity('Switch user', (string) $u['role']);

        return $u;
    }

    /** Open another outlet of the account (only outlets the user belongs to). */
    public function switchOutlet(int $businessId): array
    {
        $s = service('posSession');
        $ok = null;
        foreach ($s->outlets() as $o) {
            if ((int) $o['id'] === $businessId) {
                $ok = $o;
            }
        }
        if ($ok === null) {
            throw new NotFoundException('Outlet not found.');
        }
        if ($businessId === $s->businessId()) {
            throw new BusinessRuleException('You are already in ' . $ok['name'] . '.');
        }
        service('audit')->activity('Switch outlet', 'To ' . $ok['name']);
        $this->put('business_id', $businessId);
        $s->reset();
        PosSession::forgetBusiness($businessId);
        PosSession::forgetUser($s->userId(), $businessId);

        return $ok;
    }

    /** Change one key of the 'pos' session array (CodeIgniter's set() has no dot notation). */
    private function put(string $key, int $value): void
    {
        $pos = (array) (session('pos') ?? []);
        $pos[$key] = $value;
        session()->set('pos', $pos);
    }

    public function logout(): void
    {
        $s = service('posSession');
        if ($s->signedIn()) {
            $s->bindContext();
            service('audit')->activity('Sign out', '');
        }
        session()->remove('pos');
        session()->destroy();
    }
}
