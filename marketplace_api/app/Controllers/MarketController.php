<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Location\Services\LocationStore;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\AuthenticationException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiEnvelope;

/**
 * Base for the /v1/market controllers: JSON envelope, request body, the visitor's location and
 * the signed-in buyer. The caller is always the Marketplace website's server (see MarketAuth),
 * which forwards per-visitor state the old CodeIgniter app kept in its session:
 *
 *   location   GET: lat, lng, area, city, src query parameters · POST: "location" object in the body
 *   buyer      X-Buyer-Token header (issued by POST /v1/market/buyers/login or /buyers)
 *   visitor IP X-Visitor-IP header (sign-in throttling per visitor, not per website server)
 */
abstract class MarketController extends BaseController
{
    /** @var list<string> */
    protected $helpers = [];

    /** @var array<string, mixed>|null */
    private ?array $body = null;

    /** @param array<string, mixed> $meta */
    protected function ok(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(ApiEnvelope::data(self::scrub($data), $meta));
    }

    /** @return array<string, mixed> JSON request body (empty for GET) */
    protected function input(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }
        $raw = trim((string) $this->request->getBody());
        if ($raw === '') {
            return $this->body = [];
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new ValidationException('The request body must be a JSON object.', ['body' => 'Invalid JSON.']);
        }

        return $this->body = $data;
    }

    /** @return array<string, mixed> query string, strings trimmed */
    protected function query(): array
    {
        return array_map(static fn ($v) => is_string($v) ? trim($v) : $v, (array) ($this->request->getGet() ?? []));
    }

    /**
     * Validate an input array with CodeIgniter rules; 422 with field errors when it fails.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @return array<string, mixed> the validated fields
     */
    protected function check(array $data, array $rules, array $messages = []): array
    {
        $v = service('validation');
        $v->reset();
        if (! $v->setRules($rules, $messages)->run($data)) {
            throw new ValidationException('Please check the highlighted fields.', $v->getErrors());
        }

        return $v->getValidated();
    }

    /** @return array{lat: float, lng: float, area: string, city: string, source: string}|null the visitor's location */
    protected function location(): ?array
    {
        if (strtoupper($this->request->getMethod()) !== 'GET') {
            $l = $this->input()['location'] ?? null;

            return is_array($l) ? LocationStore::clean($l) : null;
        }
        $q = $this->query();
        if (! isset($q['lat'], $q['lng'])) {
            return null;
        }

        return LocationStore::clean(['lat' => $q['lat'], 'lng' => $q['lng'], 'area' => $q['area'] ?? '', 'city' => $q['city'] ?? '', 'source' => $q['src'] ?? 'manual']);
    }

    /** @return array<string, mixed>|null the signed-in buyer (X-Buyer-Token), or null for a guest / bad token */
    protected function buyer(): ?array
    {
        $token = $this->request->getHeaderLine('X-Buyer-Token');
        if ($token === '') {
            return null;
        }
        $id = service('buyerTokens')->verify($token);

        return $id !== null ? service('buyers')->find($id) : null;
    }

    /** @return array<string, mixed> the signed-in buyer; 401 when there is none */
    protected function requireBuyer(): array
    {
        return $this->buyer() ?? throw new AuthenticationException('Please sign in to continue.');
    }

    /** The visitor's IP as forwarded by the website server (falls back to the caller's address). */
    protected function visitorIp(): string
    {
        $ip = trim($this->request->getHeaderLine('X-Visitor-IP'));

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : (string) $this->request->getIPAddress();
    }

    /** Drop internal keys (tenant ids) from everything this API returns. */
    protected static function scrub(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }
        unset($data['tenant_id']);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }

        return $data;
    }
}
