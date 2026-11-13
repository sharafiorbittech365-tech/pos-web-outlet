<?php

declare(strict_types=1);

namespace App\Modules\Buyers\Controllers;

use App\Controllers\MarketController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Buyer accounts: sign up, sign in (→ a signed token the website keeps in an httpOnly cookie and
 * sends back as X-Buyer-Token), and the signed-in buyer's orders and bookings across shops.
 * Signing out is the website forgetting the token.
 */
final class BuyersController extends MarketController
{
    /** POST /v1/market/buyers/login {phone, password} → {buyer, token, expires_at} */
    public function login(): ResponseInterface
    {
        $in = $this->check($this->input(), [
            'phone' => 'required|max_length[20]',
            'password' => 'required|max_length[128]',
        ]);
        $buyer = service('buyers')->attempt((string) $in['phone'], (string) $in['password'], $this->visitorIp());

        return $this->ok(['buyer' => $buyer] + service('buyerTokens')->issue((int) $buyer['id']));
    }

    /** POST /v1/market/buyers {name, phone, email?, password, password_confirm, location?} → 201 {buyer, token, expires_at} */
    public function register(): ResponseInterface
    {
        $in = $this->check($this->input(), [
            'name' => 'required|min_length[2]|max_length[120]',
            'phone' => 'required|max_length[20]',
            'email' => 'permit_empty|valid_email|max_length[160]',
            'password' => 'required|min_length[8]|max_length[128]',
            'password_confirm' => 'required|matches[password]',
        ], ['password_confirm' => ['matches' => 'The passwords do not match.']]);
        $buyer = service('buyers')->register((string) $in['name'], (string) $in['phone'], (string) $in['password'], ($in['email'] ?? '') !== '' ? (string) $in['email'] : null, $this->location());

        return $this->ok(['buyer' => $buyer] + service('buyerTokens')->issue((int) $buyer['id']), [], 201);
    }

    /** GET /v1/market/me → the signed-in buyer (401 when the token is missing, expired or the account is gone). */
    public function me(): ResponseInterface
    {
        return $this->ok($this->requireBuyer());
    }

    /** GET /v1/market/me/orders?page= → {rows, total, page, last_page} */
    public function orders(): ResponseInterface
    {
        $buyer = $this->requireBuyer();

        return $this->ok(service('buyerHistory')->orders((int) $buyer['id'], max(1, (int) ($this->query()['page'] ?? 1))));
    }

    /** GET /v1/market/me/orders/{id} → one of the buyer's orders with its lines */
    public function order(string $id): ResponseInterface
    {
        $buyer = $this->requireBuyer();

        return $this->ok(service('buyerHistory')->order((int) $buyer['id'], (int) $id));
    }

    /** GET /v1/market/me/bookings */
    public function bookings(): ResponseInterface
    {
        $buyer = $this->requireBuyer();

        return $this->ok(service('buyerHistory')->bookings((int) $buyer['id']));
    }
}
