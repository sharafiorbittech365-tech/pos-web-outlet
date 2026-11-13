<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Controllers\MarketController;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;

/** Book a service of a listed shop, and read a booking back by its form token (confirmation page). */
final class BookingController extends MarketController
{
    /**
     * POST /v1/market/bookings
     * {token, business_id, product_id, name, phone, starts_at?, staff?, address?, note?} + X-Buyer-Token?
     * → {reference, starts_at, kind, shop, shop_phone, service, staff, shop_slug, token}
     */
    public function create(): ResponseInterface
    {
        $in = $this->check($this->input(), [
            'token' => 'required|exact_length[32]|alpha_numeric',
            'business_id' => 'required|is_natural_no_zero',
            'product_id' => 'required|is_natural_no_zero',
            'name' => 'required|min_length[2]|max_length[120]',
            'phone' => 'required|max_length[20]',
            'starts_at' => 'permit_empty|max_length[19]',
            'staff' => 'permit_empty|max_length[100]',
            'address' => 'permit_empty|max_length[255]',
            'note' => 'permit_empty|max_length[300]',
        ]);
        $in['token'] = strtolower((string) $in['token']);

        return $this->ok(service('marketBookings')->book((int) $in['business_id'], (int) $in['product_id'], $in, $this->buyer()), [], 201);
    }

    /** GET /v1/market/bookings/{token} */
    public function show(string $token): ResponseInterface
    {
        return $this->ok(service('marketBookings')->result(strtolower($token)) ?? throw new NotFoundException('This booking was not found.'));
    }
}
