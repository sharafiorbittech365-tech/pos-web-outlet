<?php

declare(strict_types=1);

namespace App\Modules\Buyers\Services;

use App\Modules\Buyers\Models\BuyersModel;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Marketplace buyer accounts: sign up / sign in with mobile number + password.
 * Sign-in is throttled (LoginThrottle, app "marketplace", per visitor IP + number), passwords use
 * password_hash(), and unknown number / wrong password give the same error. Instead of a session
 * the caller receives a signed token (App\Libraries\BuyerToken) to send back as X-Buyer-Token.
 */
final class BuyerAuthService
{
    public const APP = 'marketplace';
    private const PUBLIC = 'id, name, phone, email, area, city, lat, lng';

    public function __construct(private readonly BuyersModel $model = new BuyersModel())
    {
    }

    /** Bangladesh mobile in local form 01XXXXXXXXX (accepts +880 / 880 / spaces / dashes). */
    public static function normalisePhone(string $phone): string
    {
        $d = (string) preg_replace('/\D+/', '', $phone);
        if (str_starts_with($d, '880')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '1') && strlen($d) === 10) {
            $d = '0' . $d;
        }

        return $d;
    }

    public static function validPhone(string $phone): bool
    {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }

    /**
     * @param ?array{lat: float, lng: float, area: string, city: string} $loc the visitor's location, kept on the account
     * @return array<string, mixed> the new buyer
     */
    public function register(string $name, string $phone, string $password, ?string $email = null, ?array $loc = null): array
    {
        $phone = self::normalisePhone($phone);
        if (! self::validPhone($phone)) {
            throw new ValidationException('Enter a valid mobile number.', ['phone' => 'Use a mobile number like 01712345678.']);
        }
        if ($this->model->byPhone($phone) !== null) {
            throw new ConflictException('An account with this mobile number already exists. Please sign in.', ['phone' => 'Already registered.']);
        }
        $id = (int) $this->model->insert([
            'name' => mb_substr(trim($name), 0, 120), 'phone' => $phone,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'area' => ($loc['area'] ?? '') !== '' ? $loc['area'] : null, 'city' => ($loc['city'] ?? '') !== '' ? $loc['city'] : null,
            'lat' => $loc['lat'] ?? null, 'lng' => $loc['lng'] ?? null,
        ], true);

        return $this->find($id) ?? throw new \RuntimeException('The new buyer account could not be read back.');
    }

    /** @return array<string, mixed> the buyer */
    public function attempt(string $phone, string $password, string $ip): array
    {
        $phone = self::normalisePhone($phone);
        $ident = 'mk:' . $phone;
        $throttle = service('loginThrottle');
        $throttle->check(self::APP, $ident, $ip);
        $buyer = self::validPhone($phone) ? $this->model->byPhone($phone) : null;
        // verify against a dummy hash for unknown numbers so timing does not reveal accounts
        static $dummy = null;
        $dummy ??= password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $hash = $buyer['password_hash'] ?? $dummy;
        $ok = password_verify($password, (string) $hash) && $buyer !== null;
        $throttle->record(self::APP, $ident, $ip, $ok, $buyer !== null ? (int) $buyer['id'] : null);
        if (! $ok) {
            throw new AuthenticationException('Wrong mobile number or password.');
        }
        if (password_needs_rehash((string) $buyer['password_hash'], PASSWORD_DEFAULT)) {
            $this->model->update((int) $buyer['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        return $this->find((int) $buyer['id']) ?? throw new AuthenticationException('Wrong mobile number or password.');
    }

    /** @return array{id: int, name: string, phone: string, email: ?string, area: ?string, city: ?string, lat: ?float, lng: ?float}|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->model->select(self::PUBLIC)->find($id);
        if (! is_array($row)) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['lat'] = $row['lat'] !== null ? (float) $row['lat'] : null;
        $row['lng'] = $row['lng'] !== null ? (float) $row['lng'] : null;

        return $row;
    }
}
