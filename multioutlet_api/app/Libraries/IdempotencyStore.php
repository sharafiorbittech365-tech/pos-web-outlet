<?php

declare(strict_types=1);

namespace App\Libraries;

use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Idempotency-Key records (table idempotency_keys, primary key api_key_id + idem_key).
 *
 *   begin()     claims the key with a "processing" row (status_code 0) — INSERT IGNORE, so two
 *               concurrent requests with the same key cannot both run; returns the stored response
 *               when the same request was already answered, 409 conflict when the key was used for
 *               a different request (or is still being processed)
 *   complete()  stores the final status + envelope for replay
 *   abandon()   releases the claim when the request failed, so the client may retry
 *
 * Records are honoured for 24 hours.
 */
final class IdempotencyStore
{
    public const TTL_HOURS = 24;

    /**
     * Claim the key.
     *
     * @return array{status: int, body: array<string, mixed>}|null null = proceed, else the stored response
     */
    public function begin(int $apiKeyId, string $key, string $requestHash): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $key)) {
            throw new ValidationException('Invalid Idempotency-Key header.', ['idempotency_key' => 'Use 1-64 letters, digits, "-", "_", ":" or ".".']);
        }
        $db = db_connect();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $db->query(
                'INSERT IGNORE INTO idempotency_keys (api_key_id, idem_key, request_hash, status_code, response, created_at) VALUES (?, ?, ?, 0, ?, ?)',
                [$apiKeyId, $key, $requestHash, '{}', date('Y-m-d H:i:s')]
            );
            if ($db->affectedRows() === 1) {
                return null;
            }
            $row = $db->table('idempotency_keys')->select('request_hash, status_code, response, created_at')
                ->where('api_key_id', $apiKeyId)->where('idem_key', $key)->get()->getRowArray();
            if ($row === null) {
                continue;   // released between our insert and read: try once more
            }
            if (strtotime((string) $row['created_at']) < time() - self::TTL_HOURS * 3600) {
                $db->table('idempotency_keys')->where('api_key_id', $apiKeyId)->where('idem_key', $key)->delete();
                continue;
            }
            if (! hash_equals((string) $row['request_hash'], $requestHash)) {
                throw new ConflictException('This Idempotency-Key was already used for a different request.');
            }
            if ((int) $row['status_code'] === 0) {
                throw new ConflictException('A request with this Idempotency-Key is still being processed.');
            }

            return ['status' => (int) $row['status_code'], 'body' => json_decode((string) $row['response'], true) ?: []];
        }

        throw new ConflictException('This Idempotency-Key could not be claimed. Please retry.');
    }

    /** @param array<string, mixed> $body */
    public function complete(int $apiKeyId, string $key, int $status, array $body): void
    {
        db_connect()->table('idempotency_keys')->where('api_key_id', $apiKeyId)->where('idem_key', $key)
            ->update(['status_code' => $status, 'response' => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    }

    public function abandon(int $apiKeyId, string $key): void
    {
        db_connect()->table('idempotency_keys')->where('api_key_id', $apiKeyId)->where('idem_key', $key)->where('status_code', 0)->delete();
    }

    /** Stable hash of a request: method, path and the body with object keys sorted. */
    public static function hashRequest(string $method, string $path, array $body): string
    {
        $sort = static function (array $a) use (&$sort): array {
            if (! array_is_list($a)) {
                ksort($a);
            }
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $a[$k] = $sort($v);
                }
            }

            return $a;
        };

        return hash('sha256', strtoupper($method) . ' ' . $path . "\n" . json_encode($sort($body), JSON_UNESCAPED_UNICODE));
    }
}
