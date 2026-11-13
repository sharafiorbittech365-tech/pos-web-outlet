<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\ApiSite;
use App\Libraries\IdempotencyStore;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Http\ApiEnvelope;
use Throwable;

/**
 * Base for every JSON controller of the API Service.
 *
 *   ok()          envelope response {data, meta}
 *   cached()      same, with ETag + Cache-Control (304 when If-None-Match matches)
 *   paging()      page / per_page from the query (per_page <= 100)
 *   input()       JSON (or form) body as an array
 *   idempotent()  runs a write once per Idempotency-Key (replays the stored response)
 */
abstract class ApiController extends BaseController
{
    public const MAX_PER_PAGE = 100;

    /** @var list<string> */
    protected $helpers = [];

    protected function caller(): ApiCaller
    {
        return service('apiCaller');
    }

    protected function site(): ApiSite
    {
        return service('apiSite');
    }

    /** @param array<string, mixed> $meta */
    protected function ok(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        $this->publicCors();

        return $this->response->setStatusCode($status)->setJSON(ApiEnvelope::data($data, $meta));
    }

    /**
     * Catalogue response with an ETag; answers 304 Not Modified when the client already has it.
     *
     * @param array<string, mixed> $meta
     */
    protected function cached(mixed $data, array $meta = [], ?int $maxAge = null): ResponseInterface
    {
        $this->publicCors();
        $body = (string) json_encode(ApiEnvelope::data($data, $meta), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = 'W/"' . substr(hash('sha256', $body), 0, 32) . '"';
        $private = $this->caller()->has();
        $maxAge ??= $private ? $this->site()->cacheSeconds() : 60;
        // the default "no-store, no-cache" header is an array value: replace it, do not append
        $this->response->removeHeader('Cache-Control');
        $this->response->setHeader('ETag', $etag)
            ->setHeader('Cache-Control', ($private ? 'private' : 'public') . ', max-age=' . $maxAge)
            ->setHeader('Vary', 'Authorization, X-Storefront-Host, Origin');
        if ($this->etagMatches($etag)) {
            return $this->response->setStatusCode(304)->setBody('');
        }

        return $this->response->setStatusCode(200)->setContentType('application/json')->setBody($body);
    }

    private function etagMatches(string $etag): bool
    {
        $header = $this->request->getHeaderLine('If-None-Match');
        if ($header === '') {
            return false;
        }
        $strip = static fn (string $t): string => (string) preg_replace('#^W/#', '', trim($t));
        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === '*' || $strip($candidate) === $strip($etag)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: int, 1: int} page, per_page */
    protected function paging(int $default = 24): array
    {
        $page = max(1, (int) ($this->request->getGet('page') ?? 1));
        $per = (int) ($this->request->getGet('per_page') ?? $default);

        return [$page, $per < 1 ? $default : min(self::MAX_PER_PAGE, $per)];
    }

    /** @return array<string, mixed> query string, strings trimmed */
    protected function query(): array
    {
        return array_map(static fn ($v) => is_string($v) ? trim($v) : $v, (array) ($this->request->getGet() ?? []));
    }

    /** @return array<string, mixed> request body (JSON, or form fields) */
    protected function input(): array
    {
        $raw = (string) $this->request->getBody();
        $type = $this->request->getHeaderLine('Content-Type');
        if (trim($raw) === '' || ($type !== '' && ! str_contains($type, 'json'))) {
            return (array) ($this->request->getPost() ?? []);
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new ValidationException('The request body must be a JSON object.', ['body' => 'Invalid JSON.']);
        }

        return $data;
    }

    /**
     * Run a write once per Idempotency-Key. Without the header the work simply runs.
     * Same key + same request -> the stored response is replayed (header Idempotent-Replayed: true);
     * same key + different request -> 409 conflict.
     *
     * @param array<string, mixed>            $body the request body (part of the request hash)
     * @param callable(): array<string, mixed> $work returns the resource for "data"
     */
    protected function idempotent(array $body, callable $work, int $status = 201): ResponseInterface
    {
        $key = trim($this->request->getHeaderLine('Idempotency-Key'));
        $keyId = (int) $this->caller()->keyId();
        if ($key === '' || $keyId === 0) {
            return $this->ok($work(), [], $status);
        }
        $store = service('idempotency');
        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');
        $replay = $store->begin($keyId, $key, IdempotencyStore::hashRequest($this->request->getMethod(), $path, $body));
        if ($replay !== null) {
            $this->publicCors();

            return $this->response->setStatusCode($replay['status'])->setHeader('Idempotent-Replayed', 'true')->setJSON($replay['body']);
        }

        try {
            $envelope = ApiEnvelope::data($work());
        } catch (Throwable $e) {
            $store->abandon($keyId, $key);

            throw $e;
        }
        $store->complete($keyId, $key, $status, $envelope);
        $this->publicCors();

        return $this->response->setStatusCode($status)->setJSON($envelope);
    }

    /** Public (keyless) routes may be read by any browser origin. */
    private function publicCors(): void
    {
        if (! $this->caller()->has() && $this->request->getHeaderLine('Origin') !== '') {
            $this->response->setHeader('Access-Control-Allow-Origin', '*');
        }
    }
}
