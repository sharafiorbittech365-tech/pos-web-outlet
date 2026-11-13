<?php

declare(strict_types=1);

namespace App\Modules\Discovery\Controllers;

use App\Controllers\MarketController;
use App\Modules\Discovery\Services\DiscoveryService;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /v1/market/listings — home & search (every filter is a query-string parameter). */
final class DiscoveryController extends MarketController
{
    public function index(): ResponseInterface
    {
        $loc = $this->location();
        $filters = DiscoveryService::filters($this->query(), $loc);

        return $this->ok(['filters' => $filters, 'location' => $loc, 'result' => service('discovery')->search($filters, $loc)]);
    }
}
