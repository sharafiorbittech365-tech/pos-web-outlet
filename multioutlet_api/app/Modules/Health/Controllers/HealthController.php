<?php

declare(strict_types=1);

namespace App\Modules\Health\Controllers;

use App\Controllers\ApiController;
use CodeIgniter\CodeIgniter;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Http\ApiEnvelope;
use Throwable;

/** GET /v1/health (monitoring) and the CORS preflight fallback for every /v1 path. */
final class HealthController extends ApiController
{
    public const VERSION = '1.0.0';

    public function index(): ResponseInterface
    {
        $started = microtime(true);
        $db = ['ok' => false, 'latency_ms' => null];
        try {
            $db['ok'] = (int) db_connect()->query('SELECT 1 AS ok')->getRow('ok') === 1;
            $db['latency_ms'] = round((microtime(true) - $started) * 1000, 1);
        } catch (Throwable $e) {
            log_message('error', '[{rid}] health: database ping failed: {msg}', ['rid' => ApiEnvelope::requestId(), 'msg' => $e->getMessage()]);
        }

        return $this->ok([
            'status' => $db['ok'] ? 'ok' : 'degraded',
            'api' => 'v1',
            'version' => self::VERSION,
            'framework' => CodeIgniter::CI_VERSION,
            'php' => PHP_VERSION,
            'environment' => ENVIRONMENT,
            'database' => $db,
            'time' => date('c'),
        ], [], $db['ok'] ? 200 : 503);
    }

    /** OPTIONS /v1/* — the apicors filter answers it; this only runs when it did not. */
    public function preflight(): ResponseInterface
    {
        return $this->response->setStatusCode(204);
    }
}
