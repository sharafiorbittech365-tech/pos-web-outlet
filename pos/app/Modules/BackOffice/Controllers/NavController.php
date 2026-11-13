<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Controllers;

use App\Controllers\BaseController;
use App\Modules\BackOffice\Support\NavCounts;
use CodeIgniter\HTTP\ResponseInterface;

/** GET nav/counts — the menu's unread badges (see NavCounts), polled by assets/pos/badges.js. */
final class NavController extends BaseController
{
    public function counts(): ResponseInterface
    {
        return $this->json(NavCounts::forCurrentUser())->setHeader('Cache-Control', 'no-store');
    }
}
