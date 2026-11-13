<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\ApiSite;
use App\Libraries\IdempotencyStore;
use CodeIgniter\Config\BaseService;

/**
 * API Service services (core services such as orders, bookings, apiKeys come from OmniPOS\Core\Config\Services).
 */
class Services extends BaseService
{
    /** The site / outlet behind the current API key. */
    public static function apiSite(bool $getShared = true): ApiSite
    {
        if ($getShared) {
            return static::getSharedInstance('apiSite');
        }

        return new ApiSite(service('apiCaller'));
    }

    /** Idempotency-Key records for POST /orders and /bookings. */
    public static function idempotency(bool $getShared = true): IdempotencyStore
    {
        if ($getShared) {
            return static::getSharedInstance('idempotency');
        }

        return new IdempotencyStore();
    }
}
