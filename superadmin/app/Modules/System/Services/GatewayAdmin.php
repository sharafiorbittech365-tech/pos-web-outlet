<?php

declare(strict_types=1);

namespace App\Modules\System\Services;

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Services\StorefrontGateway;

/**
 * Super Admin › System › Storefront gateway: generate / rotate / disable the hosted Storefront's
 * gateway token (Core\Services\StorefrontGateway). Only the hash is stored; the token itself is
 * returned once, to be pasted into apps/storefront/.env. A rotation keeps the old token working
 * for a grace period so the storefront servers can be updated without the shops going down.
 */
final class GatewayAdmin
{
    /** Grace periods offered for the old token (hours => label). */
    public const GRACE = [0 => 'Stop the old token now', 1 => '1 hour', 24 => '24 hours', 72 => '3 days', 168 => '7 days'];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor = [])
    {
    }

    /** New token (shown once); the previous one keeps working for $graceHours. */
    public function rotate(int $graceHours): string
    {
        if (! array_key_exists($graceHours, self::GRACE)) {
            throw new BusinessRuleException('Choose how long the old token keeps working.');
        }
        $gw = service('storefrontGateway');
        $old = $gw->record();
        $token = StorefrontGateway::generate();
        $until = $graceHours > 0 ? date('Y-m-d H:i:s', time() + $graceHours * 3600) : null;
        $previous = null;
        $envUntil = null;
        if ($until !== null && $old !== null && empty($old['disabled'])) {
            $previous = ['hash' => $old['hash'], 'prefix' => $old['prefix'], 'last4' => $old['last4'], 'valid_until' => $until];
        } elseif ($until !== null && $old === null) {
            // first token from Super Admin: the token in each API server's .env (which Super Admin cannot
            // see) keeps working for the grace period, so live storefronts can be switched over
            $envUntil = $until;
        }
        $this->store([
            'hash' => StorefrontGateway::hash($token), 'prefix' => substr($token, 0, 8), 'last4' => substr($token, -4),
            'created_at' => date('Y-m-d H:i:s'), 'created_by' => (string) ($this->actor['name'] ?? 'System'), 'disabled' => false,
            'previous' => $previous, 'env_valid_until' => $envUntil,
        ]);
        $oldNote = match (true) {
            $previous !== null => ' · old token …' . $previous['last4'] . ' valid until ' . $until,
            $envUntil !== null => ' · the .env token valid until ' . $until,
            default => ' · old token stopped',
        };
        service('audit')->platform($old === null ? 'Storefront gateway token generated' : 'Storefront gateway token rotated', '…' . substr($token, -4) . $oldNote, null, $this->actor);

        return $token;
    }

    /** Hosted storefronts stop at once (until a new token is generated). */
    public function disable(): void
    {
        $r = service('storefrontGateway')->record() ?? throw new BusinessRuleException('Generate a token first — the gateway is not managed here yet.');
        $r['disabled'] = true;
        $r['previous'] = null;
        $r['env_valid_until'] = null;
        $this->store($r);
        service('audit')->platform('Storefront gateway disabled', 'token …' . $r['last4'] . ' · hosted storefronts stopped', null, $this->actor);
    }

    /** @param array<string, mixed> $value */
    private function store(array $value): void
    {
        db_connect()->query('INSERT INTO platform_settings (setting_key, value, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [StorefrontGateway::SETTING, json_encode($value), date('Y-m-d H:i:s')]);
        service('catalog')->flush();
    }
}
