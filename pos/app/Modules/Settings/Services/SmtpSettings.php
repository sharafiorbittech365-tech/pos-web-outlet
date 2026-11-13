<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Invoices\Services\SettingsStore;

/**
 * The outlet's outgoing e-mail (SMTP): businesses.settings.smtp. The password is stored encrypted
 * (CodeIgniter encrypter, app key) and never printed back into a form — an empty password field
 * keeps the stored one. The e-mail buttons of the POS (invoice → "Email") only show when this is set up.
 */
final class SmtpSettings
{
    public const SECURITY = ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL / TLS (port 465)', 'none' => 'None (port 25 — not recommended)'];
    public const DEFAULTS = ['host' => '', 'port' => 587, 'security' => 'tls', 'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => '', 'reply_to' => ''];

    /** @return array<string, mixed> with the password decrypted (for sending only) */
    public static function get(array $business): array
    {
        $cur = array_merge(self::DEFAULTS, array_intersect_key((array) ($business['settings']['smtp'] ?? []), self::DEFAULTS));
        $cur['pass'] = self::decrypt((string) $cur['pass']);

        return $cur;
    }

    /** Settings for a form: no password, only whether one is stored. */
    public static function forForm(array $business): array
    {
        $c = self::get($business);
        $has = $c['pass'] !== '';
        $c['pass'] = '';
        $c['has_pass'] = $has;

        return $c;
    }

    public static function configured(?array $business = null): bool
    {
        $business ??= service('posSession')->business();
        $c = self::get($business);

        return $c['host'] !== '' && filter_var($c['from_email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @param array<string, mixed> $in       the Setup form (smtp_* fields)
     * @param array<string, mixed> $business current business (an empty password keeps the stored one)
     *
     * @return array<string, mixed> settings to store (password encrypted), or to test with (plain) when $forStore is false
     */
    public static function fromForm(array $in, array $business, bool $forStore = true): array
    {
        $old = self::get($business);
        $s = static fn (string $k, int $max): string => mb_substr(trim((string) ($in['smtp_' . $k] ?? '')), 0, $max);
        $security = array_key_exists((string) ($in['smtp_security'] ?? ''), self::SECURITY) ? (string) $in['smtp_security'] : 'tls';
        $port = (int) ($in['smtp_port'] ?? 0);
        $pass = (string) ($in['smtp_pass'] ?? '');
        $out = [
            'host' => $s('host', 190), 'port' => $port > 0 && $port < 65536 ? $port : ($security === 'ssl' ? 465 : 587), 'security' => $security,
            'user' => $s('user', 190), 'pass' => $pass !== '' ? $pass : $old['pass'],
            'from_email' => $s('from_email', 160), 'from_name' => $s('from_name', 120), 'reply_to' => $s('reply_to', 160),
        ];

        return $forStore ? ['pass' => self::encrypt($out['pass'])] + $out : $out;
    }

    public static function save(array $in): void
    {
        if (empty($in['smtp_present'])) {
            return;
        }
        $b = service('posSession')->business();
        SettingsStore::put((int) $b['tenant_id'], (int) $b['id'], 'smtp', self::fromForm($in, $b));
    }

    private static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        try {
            return 'enc:' . base64_encode((string) service('encrypter')->encrypt($plain));
        } catch (\Throwable) {
            return 'b64:' . base64_encode($plain);
        }
    }

    private static function decrypt(string $stored): string
    {
        try {
            if (str_starts_with($stored, 'enc:')) {
                return (string) service('encrypter')->decrypt((string) base64_decode(substr($stored, 4), true));
            }
            if (str_starts_with($stored, 'b64:')) {
                return (string) base64_decode(substr($stored, 4), true);
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }
}
