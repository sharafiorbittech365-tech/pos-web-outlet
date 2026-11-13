<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use OmniPOS\Core\Exceptions\AuthenticationException;

/**
 * Platform staff sign-in: email + password, throttled (LoginThrottle), audited.
 */
final class AdminAuthService
{
    /** @return array<string, mixed> the staff row */
    public function attempt(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $throttle = service('loginThrottle');
        $throttle->check('superadmin', $email, $ip);
        $db = db_connect();
        $admin = $db->table('platform_admins')->where('email', $email)->get()->getRowArray();
        $ok = $admin !== null && (bool) $admin['is_active'] && password_verify($password, (string) $admin['password_hash']);
        $throttle->record('superadmin', $email, $ip, $ok, isset($admin['id']) ? (int) $admin['id'] : null);
        if (! $ok) {
            // same message for unknown email and wrong password
            throw new AuthenticationException('Wrong email or password.');
        }
        if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
            $db->table('platform_admins')->where('id', $admin['id'])->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $db->table('platform_admins')->where('id', $admin['id'])->update(['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip, 'failed_logins' => 0]);
        service('audit')->platform('Signed in', $admin['email'], null, ['name' => $admin['name'], 'role' => 'Staff', 'id' => (int) $admin['id']]);

        return $admin;
    }
}
