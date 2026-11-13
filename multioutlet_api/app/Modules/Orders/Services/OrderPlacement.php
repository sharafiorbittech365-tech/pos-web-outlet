<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Exceptions\OutOfStockException;
use App\Libraries\ApiSite;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiCaller;
use OmniPOS\Core\Services\LocationCatalog;
use OmniPOS\Core\Services\OrderService;

/**
 * POST /v1/orders — checkout from a storefront, the marketplace or an integration.
 *
 * Input is validated here (items, customer, delivery, payment against the site's checkout
 * settings); prices, VAT, stock and numbering are OrderService's job (it never trusts client
 * prices). Shipping follows the site checkout: the delivery charge, free at or above freeAbove
 * (goods total, VAT included), 0 for pickup. COD leaves the order due; bKash / card record a
 * payment with the transaction id.
 *
 * Table QR ordering ("table": the code from a table's QR, module tableqr): a dine-in order on that
 * table of this site's outlet — no address, name / phone optional, sent to the kitchen, and left
 * open (the table's bill, paid at the table) unless it was paid online.
 *
 * Delivery address: free text (customer.address), optionally with ids from the location catalogue
 * (customer.country_code / city_id / area_id, checked by LocationCatalog::resolve) and a map pin
 * (location {lat, lng}, checked by LocationCatalog::coordinates). See docs/modules/locations.md.
 */
final class OrderPlacement
{
    public const MAX_ITEMS = 50;
    private const CHANNELS = ['storefront' => 'web', 'web' => 'web', 'marketplace' => 'marketplace', 'api' => 'api'];
    private const PHONE = '/^\+?[0-9][0-9\- ]{6,18}$/';

    public function __construct(
        private readonly ApiSite $site,
        private readonly ApiCaller $caller,
        private readonly OrderService $orders,
    ) {
    }

    /**
     * @param array<string, mixed> $in request body
     * @return array<string, mixed> {reference, number, status, subtotal, tax, shipping, total}
     */
    public function place(array $in): array
    {
        $d = $this->validate($in);
        $checkout = $this->site->checkout();

        try {
            $goods = $this->orders->quote(['lines' => $d['lines']])['totals']->total;
            $shipping = 0.0;
            if ($d['table'] === null && $d['delivery'] === 'delivery') {
                $shipping = $checkout['freeAbove'] > 0 && $goods >= $checkout['freeAbove'] ? 0.0 : max(0.0, $checkout['delivery']);
            }
            $draft = [
                'channel' => $d['channel'],
                'lines' => $d['lines'],
                'customer' => $d['customer'],
                'shipping' => [
                    'charge' => $shipping,
                    'address' => $d['delivery'] === 'delivery' ? $d['customer']['address'] : null,
                    'status' => $d['delivery'] === 'delivery' ? 'ordered' : 'pickup',
                ],
                'order_type' => $d['delivery'] === 'delivery' ? 'Delivery' : 'Pickup',
                'table_ref' => null,
                'note' => $d['note'],
                'api_key_id' => $this->caller->keyId(),
                'geo' => $d['geo'],
                'place' => $d['place'] === null ? null : ['country_code' => $d['place']['country_code'], 'city_id' => $d['place']['city_id'], 'area_id' => $d['place']['area_id']],
                // menu businesses (restaurant, cafe) send online orders to the kitchen screen — when the
                // outlet uses its kitchen board (switch on and allowed, see BusinessTypeSetup::allowedFeatures)
                'kitchen' => $this->usesKitchen(),
            ];
            if ($d['table'] !== null) {
                unset($draft['shipping']);
                $draft['geo'] = null;   // the guest is at the table
                $draft['place'] = null;
                $draft['order_type'] = self::dineIn($this->site->business());
                $draft['table_ref'] = $d['table']['name'];
            }
            if ($d['payment'] !== 'cod') {
                $total = $this->orders->quote($draft)['totals']->total;
                $draft['payments'] = [['method' => $d['payment'], 'amount' => $total, 'reference' => $d['trx_id']]];
            }

            $order = Tx::run(function () use ($draft, $d): array {
                $draft['web_ref'] = $this->newReference();
                $order = $this->orders->create($draft);
                if ($d['table'] !== null && $order['status'] !== 'paid') {
                    // the table's open bill: paid at the table, like a "send to kitchen" sale at the till
                    db_connect()->table('orders')->where('id', $order['id'])->update(['status' => 'open', 'updated_at' => date('Y-m-d H:i:s')]);
                    $order['status'] = 'open';
                }

                return $order;
            });
        } catch (ValidationException $e) {
            // core speaks of "lines"; the API of "items"
            $details = [];
            foreach ($e->details() as $k => $v) {
                $details[(string) preg_replace('/^lines\b/', 'items', (string) $k)] = $v;
            }

            throw new ValidationException($e->getMessage(), $details, $e);
        } catch (BusinessRuleException $e) {
            if (preg_match('/stock|left in|not enough|in unexpired/i', $e->getMessage())) {
                throw new OutOfStockException($e->getMessage(), [], $e);
            }

            throw $e;
        }

        return [
            'reference' => (string) $order['web_ref'],
            'number' => (int) $order['number'],
            'status' => (string) $order['status'],
            'subtotal' => (float) $order['subtotal'],
            'tax' => (float) $order['tax_total'],
            'shipping' => (float) $order['shipping'],
            'total' => (float) $order['total'],
        ];
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed> normalised draft parts
     */
    private function validate(array $in): array
    {
        $errors = [];
        $checkout = $this->site->checkout();

        $items = $in['items'] ?? null;
        $lines = [];
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            $errors['items'] = 'Add at least one item.';
        } elseif (count($items) > self::MAX_ITEMS) {
            $errors['items'] = 'At most ' . self::MAX_ITEMS . ' items per order.';
        } else {
            foreach ($items as $i => $it) {
                if (! is_array($it)) {
                    $errors["items.{$i}"] = 'Invalid item.';
                    continue;
                }
                $pid = $it['product_id'] ?? null;
                if (! self::isId($pid)) {
                    $errors["items.{$i}.product_id"] = 'A product id is required.';
                }
                $vid = $it['variant_id'] ?? null;
                if ($vid !== null && $vid !== '' && ! self::isId($vid)) {
                    $errors["items.{$i}.variant_id"] = 'Invalid option id.';
                }
                $qty = $it['qty'] ?? 1;
                if (! is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 10000) {
                    $errors["items.{$i}.qty"] = 'Quantity must be more than 0.';
                }
                $lines[] = ['product_id' => (int) $pid, 'variant_id' => $vid !== null && $vid !== '' ? (int) $vid : null, 'qty' => (float) $qty];
            }
        }

        $table = null;
        $tableCode = trim((string) ($in['table'] ?? ''));
        if ($tableCode !== '') {
            $table = $this->table($tableCode);
            if ($table === null) {
                $errors['table'] = 'This table code is not valid for this shop. Scan the QR code on the table again.';
            }
        }

        $delivery = $tableCode !== '' ? 'table' : (string) ($in['delivery'] ?? 'delivery');
        if ($delivery === 'table') {
            // dine-in: nothing to deliver
        } elseif (! in_array($delivery, ['delivery', 'pickup'], true)) {
            $errors['delivery'] = 'Use delivery or pickup.';
        } elseif ($delivery === 'pickup' && ! $checkout['pickup']) {
            $errors['delivery'] = 'This shop does not offer pickup.';
        }

        $payment = (string) ($in['payment'] ?? 'cod');
        $trx = trim((string) ($in['trx_id'] ?? ''));
        if (! in_array($payment, ['cod', 'bkash', 'card'], true)) {
            $errors['payment'] = 'Use cod, bkash or card.';
        } elseif (! $checkout[$payment]) {
            $errors['payment'] = 'This shop does not accept ' . ($payment === 'cod' ? 'cash on delivery' : $payment) . '.';
        } elseif ($payment !== 'cod' && ! preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $trx)) {
            $errors['trx_id'] = 'Enter the transaction id of the ' . ($payment === 'bkash' ? 'bKash' : 'card') . ' payment.';
        }

        $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
        $name = trim((string) ($c['name'] ?? ''));
        $phone = trim((string) ($c['phone'] ?? ''));
        $email = trim((string) ($c['email'] ?? ''));
        $address = trim((string) ($c['address'] ?? ''));
        if ($delivery === 'delivery' && $name === '') {
            $errors['customer.name'] = 'Name is required.';
        }
        if (($delivery === 'delivery' || $phone !== '') && ! preg_match(self::PHONE, $phone)) {
            $errors['customer.phone'] = 'A valid phone number is required.';
        }
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['customer.email'] = 'Invalid email address.';
        }
        if ($delivery === 'delivery' && mb_strlen($address) < 5) {
            $errors['customer.address'] = 'The delivery address is required.';
        }
        if (mb_strlen($name) > 120) {
            $errors['customer.name'] = 'At most 120 characters.';
        }
        if (mb_strlen($address) > 255) {
            $errors['customer.address'] = 'At most 255 characters.';
        }

        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 500) {
            $errors['note'] = 'At most 500 characters.';
        }

        // map pin (LocationCatalog::coordinates: both or neither, in range, sane precision)
        $geo = null;
        $loc = $in['location'] ?? null;
        if ($loc !== null && ! is_array($loc)) {
            $errors['location'] = 'Send the location as {lat, lng}.';
        } elseif (is_array($loc)) {
            try {
                $ll = LocationCatalog::coordinates($loc['lat'] ?? null, $loc['lng'] ?? null);
                if ($ll !== null) {
                    $geo = $ll + ['area' => mb_substr(trim((string) ($loc['area'] ?? '')), 0, 64) ?: null];
                }
            } catch (ValidationException $e) {
                $errors += $e->details();
            }
        }

        // address picked from the location catalogue: area belongs to city belongs to country
        $place = null;
        if ($delivery === 'delivery') {
            try {
                $place = (new LocationCatalog())->resolve($c['country_code'] ?? null, $c['city_id'] ?? null, $c['area_id'] ?? null, 'customer.');
                if ($place['country_code'] === null) {
                    $place = null;
                }
            } catch (ValidationException $e) {
                $errors += $e->details();
            }
        }
        if ($place !== null && $address !== '') {
            // the printed address (challan, receipt) names the picked area and city too
            foreach ([$place['area'], $place['city']] as $part) {
                if ($part !== null && mb_stripos($address, $part) === false && mb_strlen($address . ', ' . $part) <= 255) {
                    $address .= ', ' . $part;
                }
            }
            if ($geo !== null && $geo['area'] === null && $place['area'] !== null) {
                $geo['area'] = mb_substr($place['area'], 0, 64);
            }
        }

        $channelIn = (string) ($in['channel'] ?? '');
        if ($channelIn !== '' && ! isset(self::CHANNELS[$channelIn])) {
            $errors['channel'] = 'Use storefront, marketplace or api.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the order details.', $errors);
        }
        $default = (bool) ($this->caller->key()['is_storefront'] ?? false) ? 'web' : 'api';

        return [
            'lines' => $lines,
            'delivery' => $delivery,
            'payment' => $payment,
            'trx_id' => $payment !== 'cod' ? $trx : null,
            'customer' => ['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address !== '' ? $address : null],
            'note' => $note !== '' ? $note : null,
            'geo' => $geo,
            'place' => $place,
            'channel' => $channelIn !== '' ? self::CHANNELS[$channelIn] : $default,
            'table' => $table,
        ];
    }

    /**
     * The table behind a QR code — only a table of this site's outlet, while Table QR ordering is on.
     *
     * @return array{id: int, name: string, area: ?string, seats: int}|null
     */
    public function table(string $code): ?array
    {
        if (! preg_match('/^[a-f0-9]{24,64}$/', $code)) {
            return null;
        }
        $b = $this->site->business();
        if (! service('moduleAccess')->forTenant((int) $b['tenant_id'])->has('tableqr')) {
            return null;
        }
        $t = db_connect()->table('dining_tables')->select('id, name, area, seats')
            ->where('business_id', (int) $b['id'])->where('qr_token', $code)->where('is_active', 1)->get()->getRowArray();

        return $t === null ? null : ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'area' => $t['area'], 'seats' => (int) $t['seats']];
    }

    /** The outlet's dine-in order type ("Dine In" unless it has its own wording). */
    private static function dineIn(array $business): string
    {
        $types = (array) ($business['settings']['features']['orderTypes'] ?? []);
        foreach ($types as $t) {
            if (stripos((string) $t, 'dine') !== false) {
                return (string) $t;
            }
        }

        return 'Dine In';
    }

    /** Online orders of this outlet go to its kitchen board (menu business, kitchen module on, kitchen switch on and allowed). */
    private function usesKitchen(): bool
    {
        $b = $this->site->business();
        if (($b['store_kind'] ?? '') !== 'menu') {
            return false;
        }
        $access = service('moduleAccess')->forTenant((int) $b['tenant_id']);

        return $access->has('kitchen')
            && (bool) ($b['settings']['features']['kitchen'] ?? true)
            && in_array('kitchen', \OmniPOS\Core\Services\BusinessTypeSetup::allowedFeatures((string) $b['business_type'], $access), true);
    }

    /** Public order reference: "W" + 6 base-36 characters, unique. */
    private function newReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $ref = 'W' . strtoupper(str_pad(base_convert((string) random_int(0, 36 ** 6 - 1), 10, 36), 6, '0', STR_PAD_LEFT));
            // web_ref is unique across all outlets (uq_order_webref)
            if (db_connect()->table('orders')->where('web_ref', $ref)->countAllResults() === 0) {
                return $ref;
            }
        }

        throw new \RuntimeException('Could not allocate an order reference.');
    }

    private static function isId(mixed $v): bool
    {
        return (is_int($v) && $v > 0) || (is_string($v) && ctype_digit($v) && (int) $v > 0);
    }
}
