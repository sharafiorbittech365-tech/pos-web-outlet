<?php

declare(strict_types=1);

namespace App\Modules\Listing\Controllers;

use App\Controllers\MarketController;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /v1/market/listings/{business}/{product}?date= — the product / service, and the booking form data for services. */
final class ListingController extends MarketController
{
    public function show(string $businessId, string $productId): ResponseInterface
    {
        $loc = $this->location();
        $item = service('listing')->find((int) $businessId, (int) $productId, $loc);
        $booking = null;
        if ($item['kind'] === 'service' && $item['shop']['takes_bookings']) {
            $booking = service('marketBookings')->form($item, (string) ($this->query()['date'] ?? ''));
        }

        return $this->ok(['item' => $item, 'booking' => $booking, 'location' => $loc]);
    }
}
