<?php

namespace Config;

use App\Libraries\PosSession;
use CodeIgniter\Config\BaseService;

/**
 * POS application services. Shared domain services (orders, stock, ledger, bookings, billing,
 * servers …) come from omnipos/core (OmniPOS\Core\Config\Services).
 */
class Services extends BaseService
{
    public static function posSession(bool $getShared = true): PosSession
    {
        return $getShared ? static::getSharedInstance('posSession') : new PosSession();
    }
}
