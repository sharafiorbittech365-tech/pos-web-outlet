<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Buyers\Services\BuyerAuthService;
use App\Modules\Shops\Services\ListedShops;
use Config\Market;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\OmniException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Places the cart: one order per shop, each written by OrderService::create() inside that
 * shop's tenant context (channel marketplace, buyer_id, geo, shipping). OrderService re-prices
 * everything from the catalogue and takes stock with row locks.
 *
 * Idempotency: the website sends a one-time checkout token (32 hex characters, made when the
 * checkout form is shown). The result is kept under that token, so a resubmitted token returns the
 * earlier result instead of ordering again; on top of that each shop's order reference
 * (orders.web_ref, unique) is derived from the token, so even two racing requests cannot create
 * the same shop order twice.
 */
final class CheckoutService
{
    private const CACHE = 'mk_checkout_';

    public function __construct(private readonly ListedShops $shops, private readonly CartService $cart)
    {
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    /** @return list<array<string, mixed>>|null the result of a token already placed */
    public function result(string $token): ?array
    {
        if (! self::validToken($token)) {
            return null;
        }
        $r = cache(self::CACHE . $token);

        return is_array($r) ? $r : null;
    }

    /** @return list<array<string, mixed>> */
    public function resultOrFail(string $token): array
    {
        return $this->result($token) ?? throw new NotFoundException('This checkout was not found.');
    }

    public static function reference(string $token, int $businessId): string
    {
        return 'M' . strtoupper(substr(hash('sha256', $token . '|' . $businessId), 0, 9));
    }

    /**
     * @param array{name: string, phone: string, address?: string, note?: string} $buyerIn
     * @param array<int, array{fulfilment?: string, payment?: string, trx?: string}> $perShop
     * @param array<int, array<string, float>> $cart CartService::normalise()d
     * @return list<array<string, mixed>> placed orders (and shops that failed, with the reason)
     */
    public function place(string $token, array $buyerIn, array $perShop, array $cart, ?array $buyer, ?array $loc): array
    {
        if (! self::validToken($token)) {
            throw new ValidationException('This checkout has expired. Please review your cart and try again.', ['token' => 'Invalid checkout token.']);
        }
        if (($prev = $this->result($token)) !== null) {
            return $prev;
        }
        $phone = BuyerAuthService::normalisePhone($buyerIn['phone']);
        if (! BuyerAuthService::validPhone($phone)) {
            throw new ValidationException('Enter a valid mobile number.', ['phone' => 'Use a mobile number like 01712345678.']);
        }
        $summary = $this->cart->summary($cart, $loc, array_map(static fn (array $s): string => (string) ($s['fulfilment'] ?? ''), $perShop));
        if ($summary === []) {
            throw new BusinessRuleException('Your cart is empty.');
        }
        $address = trim((string) ($buyerIn['address'] ?? ''));
        $errors = [];
        foreach ($summary as $s) {
            $bid = $s['business_id'];
            if ($s['shop'] === null || $s['error'] !== null) {
                continue;
            }
            $pay = (string) ($perShop[$bid]['payment'] ?? 'cod');
            if (! in_array($pay, self::payments($s['shop']), true)) {
                $errors["shop.{$bid}.payment"] = $s['shop']['name'] . ': choose a payment method the shop accepts.';
            } elseif ($pay === 'bkash' && ! preg_match('/^[A-Za-z0-9]{6,20}$/', trim((string) ($perShop[$bid]['trx'] ?? '')))) {
                $errors["shop.{$bid}.trx"] = $s['shop']['name'] . ': enter the bKash transaction id.';
            }
            if ($s['fulfilment'] === 'delivery' && mb_strlen($address) < 5) {
                $errors['address'] = 'The delivery address is required.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException(reset($errors), $errors);
        }

        $customer = ['name' => mb_substr(trim($buyerIn['name']), 0, 120), 'phone' => $phone, 'address' => $address !== '' ? mb_substr($address, 0, 255) : null];
        $geo = $loc !== null ? ['lat' => $loc['lat'], 'lng' => $loc['lng'], 'area' => $loc['area'] !== '' ? $loc['area'] : null] : null;
        $results = [];
        foreach ($summary as $s) {
            if ($s['shop'] === null || $s['error'] !== null) {
                $results[] = ['ok' => false, 'shop' => $s['shop']['name'] ?? 'Shop', 'business_id' => $s['business_id'], 'error' => $s['error'] ?? 'Not available.'];
                continue;
            }
            $results[] = $this->placeOne($token, $s, $perShop[$s['business_id']] ?? [], $cart[$s['business_id']] ?? [], $customer, $geo, $buyer, trim((string) ($buyerIn['note'] ?? '')));
        }
        // the website removes the shops that were placed from its cart; a shop that failed stays there
        cache()->save(self::CACHE . $token, $results, config(Market::class)->resultDays * 86400);

        return $results;
    }

    /** @return list<string> */
    public static function payments(array $shop): array
    {
        $p = [];
        if ($shop['cod']) {
            $p[] = 'cod';
        }
        if ($shop['bkash']) {
            $p[] = 'bkash';
        }

        return $p !== [] ? $p : ['cod'];
    }

    /**
     * @param array<string, float> $rawLines this shop's cart lines
     * @return array<string, mixed>
     */
    private function placeOne(string $token, array $s, array $choice, array $rawLines, array $customer, ?array $geo, ?array $buyer, string $note): array
    {
        $shop = $s['shop'];
        $bid = (int) $shop['id'];
        $ref = self::reference($token, $bid);
        $db = db_connect();
        // already written (double submit that raced past the result cache)
        $existing = $db->table('orders')->select('id, doc_no, web_ref, total, status')->where('web_ref', $ref)->get()->getRowArray();
        if ($existing !== null) {
            return ['ok' => true, 'shop' => $shop['name'], 'business_id' => $bid, 'order_id' => (int) $existing['id'], 'doc_no' => $existing['doc_no'],
                'reference' => $existing['web_ref'], 'total' => (float) $existing['total'], 'currency' => $shop['currency'], 'fulfilment' => $s['fulfilment'], 'phone' => $shop['phone']];
        }
        $mode = (string) $s['fulfilment'];
        $payment = (string) ($choice['payment'] ?? 'cod');
        $lines = $this->cart->lines($bid, $rawLines);
        try {
            $order = service('tenant')->runAs($shop['tenant_id'], $bid, function () use ($lines, $mode, $payment, $choice, $customer, $geo, $buyer, $note, $ref, $shop): array {
                service('tenant')->set($shop['tenant_id'], $shop['id'], null, 'Marketplace');
                $orders = service('orders');
                $goods = $orders->quote(['channel' => 'marketplace', 'lines' => $lines])['totals']->total;
                $draft = [
                    'channel' => 'marketplace',
                    'lines' => $lines,
                    'customer' => $customer,
                    'shipping' => [
                        'charge' => CartService::shipping($shop, $mode, $goods),
                        'address' => $mode === 'delivery' ? $customer['address'] : null,
                        'status' => $mode === 'delivery' ? 'ordered' : 'pickup',
                    ],
                    'order_type' => $mode === 'delivery' ? 'Delivery' : 'Pickup',
                    'buyer_id' => $buyer['id'] ?? null,
                    'geo' => $geo,
                    'web_ref' => $ref,
                    'note' => mb_substr(trim('Ordered on ' . \OmniPOS\Ui\Brand::name() . ' Market. ' . ($payment === 'bkash' ? 'bKash TRX ' . trim((string) $choice['trx']) . '. ' : 'Cash on delivery. ') . $note), 0, 500),
                    // menu businesses send online orders to the kitchen screen when they have it
                    'kitchen' => $shop['store_kind'] === 'menu' && service('moduleAccess')->forTenant($shop['tenant_id'])->has('kitchen'),
                ];
                if ($payment === 'bkash') {
                    $total = $orders->quote($draft)['totals']->total;
                    $draft['payments'] = [['method' => 'bkash', 'amount' => $total, 'reference' => trim((string) $choice['trx'])]];
                }

                return Tx::run(static fn (): array => $orders->create($draft));
            });
        } catch (OmniException $e) {
            return ['ok' => false, 'shop' => $shop['name'], 'business_id' => $bid, 'error' => $e->getMessage()];
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            // unique web_ref: the same checkout was written by a parallel request
            $existing = $db->table('orders')->select('id, doc_no, web_ref, total')->where('web_ref', $ref)->get()->getRowArray();
            if ($existing === null) {
                throw $e;
            }
            $order = $existing;
        }

        return ['ok' => true, 'shop' => $shop['name'], 'business_id' => $bid, 'order_id' => (int) $order['id'], 'doc_no' => (string) $order['doc_no'],
            'reference' => (string) $order['web_ref'], 'total' => (float) $order['total'], 'currency' => $shop['currency'], 'fulfilment' => $mode, 'phone' => $shop['phone']];
    }
}
