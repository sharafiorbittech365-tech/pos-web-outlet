<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Controllers;

use App\Controllers\MarketController;
use App\Modules\Checkout\Services\CartService;
use CodeIgniter\HTTP\ResponseInterface;

/** The cart the website keeps (ids and quantities only): checking an item before it is added, and pricing it. */
final class CartController extends MarketController
{
    /** POST /v1/market/cart/check {business_id, product_id, variant_id?, qty} → the line to store {business_id, key, qty, name}. */
    public function checkItem(): ResponseInterface
    {
        $in = $this->check($this->input(), [
            'business_id' => 'required|is_natural_no_zero',
            'product_id' => 'required|is_natural_no_zero',
            'variant_id' => 'permit_empty|is_natural_no_zero',
            'qty' => 'required|decimal|greater_than[0]|less_than_equal_to[99]',
        ]);
        $vid = isset($in['variant_id']) && (string) $in['variant_id'] !== '' ? (int) $in['variant_id'] : null;

        return $this->ok(service('cart')->check((int) $in['business_id'], (int) $in['product_id'], $vid, (float) $in['qty']));
    }

    /**
     * POST /v1/market/cart/quote {cart, fulfilment?: {business id: delivery|pickup}, location?}
     * → {groups (one per shop, priced), count, cart (cleaned)}.
     */
    public function quote(): ResponseInterface
    {
        $in = $this->input();
        $cart = CartService::normalise($in['cart'] ?? []);
        $ful = [];
        foreach ((array) ($in['fulfilment'] ?? []) as $bid => $mode) {
            if (ctype_digit((string) $bid) && in_array($mode, ['delivery', 'pickup'], true)) {
                $ful[(int) $bid] = $mode;
            }
        }

        return $this->ok(['groups' => service('cart')->summary($cart, $this->location(), $ful), 'count' => CartService::count($cart), 'cart' => $cart]);
    }
}
