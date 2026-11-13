<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Shops\Services\ListedShops;
use OmniPOS\Core\Exceptions\ValidationException;
use Throwable;

/**
 * The buyer's cart, grouped by shop (each shop becomes its own order). The website keeps only ids
 * and quantities (cookie) and sends them with every request:
 *
 *   {"<business id>": {"<product id>:<variant id or 0>": qty, …}, …}
 *
 * Every view re-prices through OrderService::quote() in that shop's tenant context, so prices,
 * VAT and availability always come from the shop's catalogue — nothing the client sends is trusted
 * beyond ids and quantities.
 */
final class CartService
{
    public const MAX_LINES = 40;
    public const MAX_QTY = 99;

    public function __construct(private readonly ListedShops $shops)
    {
    }

    public static function key(int $productId, ?int $variantId): string
    {
        return $productId . ':' . ($variantId ?? 0);
    }

    /**
     * The client's cart, cleaned: integer shop ids, "pid:vid" keys, quantities 0 < q ≤ 99, at most 40 lines.
     *
     * @return array<int, array<string, float>>
     */
    public static function normalise(mixed $raw): array
    {
        $out = [];
        $n = 0;
        foreach (is_array($raw) ? $raw : [] as $bid => $lines) {
            if (! ctype_digit((string) $bid) || (int) $bid < 1 || ! is_array($lines)) {
                continue;
            }
            foreach ($lines as $k => $qty) {
                if ($n >= self::MAX_LINES || preg_match('/^[1-9]\d{0,18}:\d{1,19}$/', (string) $k) !== 1 || ! is_numeric($qty)) {
                    continue;
                }
                $q = round((float) $qty, 3);
                if ($q <= 0) {
                    continue;
                }
                $out[(int) $bid][(string) $k] = min((float) self::MAX_QTY, $q);
                $n++;
            }
        }

        return $out;
    }

    /** Units in the cart (for the header badge). */
    public static function count(array $cart): int
    {
        $n = 0;
        foreach ($cart as $lines) {
            $n += (int) ceil(array_sum($lines));
        }

        return $n;
    }

    /**
     * Check one "add to cart" against the shop's live catalogue; returns the line to store.
     *
     * @return array{business_id: int, key: string, qty: float, name: string}
     */
    public function check(int $businessId, int $productId, ?int $variantId, float $qty): array
    {
        $shop = $this->shops->getOrFail($businessId);
        $db = db_connect();
        $p = $db->table('products')->select('id, name, stock_type')->where('business_id', $shop['id'])->where('id', $productId)
            ->where('is_online', 1)->where('is_active', 1)->where('deleted_at', null)->get()->getRowArray();
        if ($p === null) {
            throw new ValidationException('This item is no longer available.', ['product_id' => 'Not available.']);
        }
        $variants = array_map('intval', array_column($db->table('product_variants')->select('id')->where('business_id', $shop['id'])->where('product_id', $productId)->get()->getResultArray(), 'id'));
        if ($variants !== [] && ($variantId === null || ! in_array($variantId, $variants, true))) {
            throw new ValidationException('Choose an option for ' . $p['name'] . '.', ['variant_id' => 'Choose an option.']);
        }
        if ($variants === []) {
            $variantId = null;
        }
        $qty = $p['stock_type'] === 'serial' ? max(1.0, round($qty)) : round($qty, 3);
        if ($qty <= 0 || $qty > self::MAX_QTY) {
            throw new ValidationException('Choose a quantity between 1 and ' . self::MAX_QTY . '.', ['qty' => 'Invalid quantity.']);
        }

        return ['business_id' => (int) $shop['id'], 'key' => self::key($productId, $variantId), 'qty' => $qty, 'name' => (string) $p['name']];
    }

    /**
     * Order lines for one shop. Serial-tracked items (IMEI) are one unit per line.
     *
     * @param array<string, float> $raw "pid:vid" => qty
     * @return list<array{product_id: int, variant_id: ?int, qty: float}>
     */
    public function lines(int $businessId, array $raw): array
    {
        if ($raw === []) {
            return [];
        }
        $ids = array_map(static fn (string $k): int => (int) explode(':', $k)[0], array_keys($raw));
        $serial = array_flip(array_map('intval', array_column(db_connect()->table('products')->select('id')->where('business_id', $businessId)->whereIn('id', $ids)->where('stock_type', 'serial')->get()->getResultArray(), 'id')));
        $out = [];
        foreach ($raw as $k => $qty) {
            [$pid, $vid] = array_map('intval', explode(':', (string) $k) + [1 => 0]);
            if (isset($serial[$pid])) {
                for ($i = 0; $i < (int) $qty; $i++) {
                    $out[] = ['product_id' => $pid, 'variant_id' => $vid ?: null, 'qty' => 1.0];
                }
                continue;
            }
            $out[] = ['product_id' => $pid, 'variant_id' => $vid ?: null, 'qty' => (float) $qty];
        }

        return $out;
    }

    /**
     * Priced cart per shop (OrderService::quote in each shop's tenant context).
     *
     * @param array<int, array<string, float>> $cart       normalise()d cart
     * @param array<int, string>               $fulfilment business id => delivery|pickup (chosen at checkout)
     * @return list<array<string, mixed>>
     */
    public function summary(array $cart, ?array $loc, array $fulfilment = []): array
    {
        $out = [];
        foreach ($cart as $bid => $rawLines) {
            $bid = (int) $bid;
            $shop = $this->shops->get($bid);
            if ($shop === null) {
                $out[] = ['business_id' => $bid, 'shop' => null, 'error' => 'This shop is no longer taking marketplace orders.', 'lines' => [], 'keys' => array_keys($rawLines)];
                continue;
            }
            $shop = ListedShops::at($shop, $loc);
            $options = self::fulfilmentOptions($shop);
            $mode = in_array($fulfilment[$bid] ?? '', $options, true) ? $fulfilment[$bid] : ($options[0] ?? null);
            $row = ['business_id' => $bid, 'shop' => $shop, 'options' => $options, 'fulfilment' => $mode, 'error' => null, 'lines' => [], 'keys' => array_keys($rawLines),
                'payments' => CheckoutService::payments($shop)];
            try {
                $q = service('tenant')->runAs($shop['tenant_id'], $bid, fn (): array => service('orders')->quote(['channel' => 'marketplace', 'lines' => $this->lines($bid, $rawLines)]));
                $totals = $q['totals'];
                $goods = $totals->total;
                $row['lines'] = $this->displayLines($q['lines'], $totals->lineTaxes);
                $row['goods'] = $goods;
                $row['tax'] = $totals->tax;
                $row['tax_added'] = $totals->taxAdded;
                $row['shipping'] = self::shipping($shop, $mode, $goods);
                $row['delivery_shipping'] = in_array('delivery', $options, true) ? self::shipping($shop, 'delivery', $goods) : null;
                $row['total'] = round($goods + $row['shipping'], 2);
            } catch (Throwable $e) {
                $row['error'] = $e instanceof \OmniPOS\Core\Exceptions\OmniException ? $e->getMessage() : 'This cart could not be priced right now.';
                if (! $e instanceof \OmniPOS\Core\Exceptions\OmniException) {
                    log_message('error', 'Cart quote failed for business {bid}: {msg}', ['bid' => $bid, 'msg' => $e->getMessage()]);
                }
            }
            if ($mode === null) {
                $row['error'] ??= $shop['name'] . ' does not deliver to your location and has no pickup.';
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<string> delivery and/or pickup, in that order */
    public static function fulfilmentOptions(array $shop): array
    {
        $o = [];
        if ($shop['delivers_here']) {
            $o[] = 'delivery';
        }
        if ($shop['pickup']) {
            $o[] = 'pickup';
        }

        return $o;
    }

    /** Delivery charge from the site checkout: free at or above freeAbove (goods total incl. VAT), 0 for pickup. */
    public static function shipping(array $shop, ?string $mode, float $goods): float
    {
        if ($mode !== 'delivery') {
            return 0.0;
        }
        if ($shop['free_above'] !== null && $goods >= $shop['free_above']) {
            return 0.0;
        }

        return max(0.0, (float) $shop['delivery_charge']);
    }

    /** Lines for display: what the buyer pays per line (VAT added on top shown in the line). */
    private function displayLines(array $lines, array $lineTaxes): array
    {
        $out = [];
        $raw = [];
        foreach ($lines as $i => $l) {
            $added = ($lineTaxes[$i]['mode'] ?? '') === 'exclusive' ? (float) $lineTaxes[$i]['tax'] : 0.0;
            $k = self::key((int) $l['product_id'], $l['variant_id'] !== null ? (int) $l['variant_id'] : null);
            // serial items were split into single units: show them as one line again
            if (isset($raw[$k])) {
                $out[$raw[$k]]['qty'] += (float) $l['qty'];
                $out[$raw[$k]]['amount'] += round((float) $l['unit_price'] * (float) $l['qty'] + $added, 2);
                continue;
            }
            $raw[$k] = count($out);
            $out[] = ['key' => $k, 'product_id' => (int) $l['product_id'], 'name' => $l['name'], 'variant_name' => $l['variant_name'], 'qty' => (float) $l['qty'],
                'amount' => round((float) $l['unit_price'] * (float) $l['qty'] + $added, 2), 'unit' => $l['unit']];
        }

        return $out;
    }
}
