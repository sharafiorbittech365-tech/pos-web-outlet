<?php

declare(strict_types=1);

namespace App\Libraries;

/**
 * Signed, expiring buyer sign-in token (replaces the buyer_id the CodeIgniter marketplace kept in
 * its session). Format: "<buyer id>.<expiry unix time>.<HMAC-SHA256>" — no secret inside, nothing
 * to decrypt; the account is re-read on every request, so a removed account is signed out at once.
 *
 * The website keeps it in an httpOnly cookie and sends it back as X-Buyer-Token.
 */
final class BuyerToken
{
    public function __construct(private readonly string $key, private readonly int $days = 30)
    {
    }

    /** @return array{token: string, expires_at: int} */
    public function issue(int $buyerId, ?int $now = null): array
    {
        $exp = ($now ?? time()) + $this->days * 86400;
        $body = $buyerId . '.' . $exp;

        return ['token' => $body . '.' . $this->sign($body), 'expires_at' => $exp];
    }

    /** The buyer id of a valid, unexpired token; null otherwise. */
    public function verify(string $token, ?int $now = null): ?int
    {
        if (preg_match('/^([1-9]\d{0,18})\.(\d{9,11})\.([a-f0-9]{64})$/', trim($token), $m) !== 1) {
            return null;
        }
        if (! hash_equals($this->sign($m[1] . '.' . $m[2]), $m[3])) {
            return null;
        }
        if ((int) $m[2] < ($now ?? time())) {
            return null;
        }

        return (int) $m[1];
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', 'buyer|' . $body, $this->key);
    }
}
