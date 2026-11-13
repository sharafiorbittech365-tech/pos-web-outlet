<?php

declare(strict_types=1);

namespace App\Modules\Shops\Controllers;

use App\Controllers\MarketController;
use App\Modules\Discovery\Services\DiscoveryService;
use App\Modules\Shops\Services\ListedShops;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;

/** Listed shops near the location, and one shop's profile & listings. */
final class ShopsController extends MarketController
{
    /** GET /v1/market/shops?radius=&limit= — nearest first (all, by name, without a location). */
    public function index(): ResponseInterface
    {
        $loc = $this->location();
        $filters = DiscoveryService::filters($this->query(), $loc);
        $limit = max(1, min(500, (int) ($this->query()['limit'] ?? 500)));

        return $this->ok(['filters' => $filters, 'location' => $loc, 'shops' => service('listedShops')->nearby($loc, $filters['radius'], $limit)]);
    }

    /** GET /v1/market/shops/{slug} — the shop as seen from the location, and its listings (never cut by distance). */
    public function show(string $slug): ResponseInterface
    {
        $shop = service('listedShops')->bySlug($slug) ?? throw new NotFoundException('This shop is not on the marketplace.');
        $loc = $this->location();
        $get = $this->query();
        $filters = DiscoveryService::filters($get, $loc);
        $filters['radius'] = 0.0;
        $filters['sort'] = in_array($get['sort'] ?? '', ['price', '-price'], true) ? $get['sort'] : 'distance';

        return $this->ok([
            'shop' => ListedShops::at($shop, $loc),
            'filters' => $filters,
            'location' => $loc,
            'result' => service('discovery')->search($filters, $loc, [$shop['id']]),
        ]);
    }
}
