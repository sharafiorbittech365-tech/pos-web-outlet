<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Marketplace API settings. Short name "market", so every property can be set from .env as
 * market.<property> (market.apiToken, market.buyerTokenSecret, …).
 *
 * This API is called only by the Marketplace website's server (apps/marketplace_next), never by
 * browsers: every /v1/market request must carry "Authorization: Bearer <apiToken>".
 */
class Market extends BaseConfig
{
    /**
     * Shared secret between this API and the Marketplace website (MARKETPLACE_API_TOKEN there).
     * Generate with: php -r "echo 'mkt_' . bin2hex(random_bytes(24));"  — also MARKETPLACE_API_TOKEN.
     */
    public string $apiToken = '';

    /**
     * Key that signs buyer sign-in tokens. Empty = derived from $apiToken (rotating the API token
     * then signs every buyer out). Set your own long random value to keep them independent.
     */
    public string $buyerTokenSecret = '';

    /** Days a buyer stays signed in. */
    public int $buyerTokenDays = 30;

    /** Days a placed checkout / booking result can be read back by its token (confirmation pages). */
    public int $resultDays = 7;

    public function __construct()
    {
        parent::__construct();
        $v = env('MARKETPLACE_API_TOKEN');
        if (is_string($v) && trim($v) !== '' && trim($this->apiToken) === '') {
            $this->apiToken = trim($v);
        }
        $this->apiToken = trim($this->apiToken);
        $this->buyerTokenDays = max(1, min(365, $this->buyerTokenDays));
        $this->resultDays = max(1, min(90, $this->resultDays));
    }

    /** The key buyer tokens are signed with. */
    public function buyerKey(): string
    {
        $own = trim($this->buyerTokenSecret);

        return $own !== '' ? $own : hash_hmac('sha256', 'marketplace-buyer-token', $this->apiToken);
    }
}
