<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Controllers;

use App\Controllers\MarketController;
use App\Modules\Checkout\Services\CartService;
use CodeIgniter\HTTP\ResponseInterface;

/** Checkout: one order per shop; the result is kept under the checkout token (confirmation page, safe resubmits). */
final class CheckoutController extends MarketController
{
    /**
     * POST /v1/market/checkout
     * {token, name, phone, address?, note?, shops: {business id: {fulfilment, payment, trx}}, cart, location?} + X-Buyer-Token?
     * → {token, results: [{ok, shop, business_id, reference, doc_no, total, currency, fulfilment, phone} | {ok: false, shop, business_id, error}]}
     */
    public function place(): ResponseInterface
    {
        $body = $this->input();
        $in = $this->check($body, [
            'token' => 'required|exact_length[32]|alpha_numeric',
            'name' => 'required|min_length[2]|max_length[120]',
            'phone' => 'required|max_length[20]',
            'address' => 'permit_empty|max_length[255]',
            'note' => 'permit_empty|max_length[300]',
            'shops.*.fulfilment' => 'permit_empty|in_list[delivery,pickup]',
            'shops.*.payment' => 'permit_empty|in_list[cod,bkash]',
            'shops.*.trx' => 'permit_empty|alpha_numeric|max_length[20]',
        ]);
        $perShop = [];
        foreach ((array) ($body['shops'] ?? []) as $bid => $s) {
            if (ctype_digit((string) $bid) && is_array($s)) {
                $perShop[(int) $bid] = ['fulfilment' => (string) ($s['fulfilment'] ?? ''), 'payment' => (string) ($s['payment'] ?? 'cod'), 'trx' => (string) ($s['trx'] ?? '')];
            }
        }
        $token = strtolower((string) $in['token']);
        $results = service('checkout')->place($token, [
            'name' => (string) $in['name'], 'phone' => (string) $in['phone'], 'address' => (string) ($in['address'] ?? ''), 'note' => (string) ($in['note'] ?? ''),
        ], $perShop, CartService::normalise($body['cart'] ?? []), $this->buyer(), $this->location());

        return $this->ok(['token' => $token, 'results' => $results], [], 201);
    }

    /** GET /v1/market/checkout/{token} — the result of a placed checkout. */
    public function show(string $token): ResponseInterface
    {
        return $this->ok(['token' => strtolower($token), 'results' => service('checkout')->resultOrFail(strtolower($token))]);
    }
}
