<?php

namespace Config;

use App\Libraries\AdminSession;
use CodeIgniter\Config\BaseService;

/**
 * Super Admin application services. Shared domain services (billing, servers, catalog …)
 * come from omnipos/core (OmniPOS\Core\Config\Services).
 */
class Services extends BaseService
{
    public static function adminSession(bool $getShared = true): AdminSession
    {
        return $getShared ? static::getSharedInstance('adminSession') : new AdminSession();
    }
}
