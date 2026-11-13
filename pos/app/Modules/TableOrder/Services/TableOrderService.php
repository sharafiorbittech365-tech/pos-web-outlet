<?php

declare(strict_types=1);

namespace App\Modules\TableOrder\Services;

use App\Modules\Sales\Services\TillCatalog;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\TenantAccessDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Table QR ordering on the POS address (public, no sign-in): the QR on a table opens
 * <pos address>/order/table-5/<secret code>; the guest sees the menu as tiles, taps to add and sends the
 * order to the kitchen — it arrives in Orders like any other sale (channel "web", type Dine In, on the
 * table, status "open": paid at the table). The secret code is the only key: it names one table of one
 * outlet and can be replaced any time (Tables › New QR).
 */
final class TableOrderService
{
    public const MAX_ITEMS = 50;
    private const PHONE = '/^\+?[0-9][0-9\- ]{6,18}$/';

    /** @var array<string, mixed> */
    private array $table;
    /** @var array<string, mixed> */
    private array $business;

    /**
     * Resolve the code to its table and outlet and make that outlet the request's tenant context.
     *
     * @throws NotFoundException           unknown / retired code, closed outlet, module off
     * @throws TenantAccessDeniedException the outlet is served by another POS server (details.server_url)
     */
    public function __construct(string $token)
    {
        $db = db_connect();
        $t = preg_match('/^[a-f0-9]{24,64}$/', $token)
            ? $db->table('dining_tables')->where('qr_token', $token)->where('is_active', 1)->get()->getRowArray() : null;
        if ($t === null) {
            throw new NotFoundException('This table code is not valid any more. Please ask the staff for help.');
        }
        $b = $db->table('businesses b')->select('b.*, bt.name AS type_name, bt.icon AS type_icon, bt.store_kind, bt.booking_kind')
            ->join('business_types bt', 'bt.code = b.business_type')->where('b.id', $t['business_id'])->where('b.is_active', 1)->get()->getRowArray();
        if ($b === null) {
            throw new NotFoundException('This outlet is closed right now.');
        }
        $b['settings'] = json_decode((string) ($b['settings'] ?? '{}'), true) ?: [];
        $access = service('moduleAccess')->forTenant((int) $b['tenant_id']);
        if (! $access->has('tableqr') || $access->isLocked()) {
            throw new NotFoundException('Ordering from the table is not available right now. Please ask the staff.');
        }
        service('servers')->assertServes((int) $b['tenant_id'], config(OmniPOS::class)->serverCode);

        $this->table = $t;
        $this->business = $b;
        service('tenant')->set((int) $b['tenant_id'], (int) $b['id'], null, 'Table QR');
    }

    /** @return array<string, mixed> */
    public function table(): array
    {
        return $this->table;
    }

    /** @return array<string, mixed> */
    public function business(): array
    {
        return $this->business;
    }

    /** Words for the page: shop name, table, currency, whether a phone is asked. @return array<string, mixed> */
    public function info(): array
    {
        $b = $this->business;

        return [
            'shop' => (string) $b['name'], 'table' => (string) $this->table['name'], 'area' => (string) ($this->table['area'] ?? ''),
            'currency' => ['symbol' => (string) ($b['currency_symbol'] ?? '৳'), 'decimals' => (int) ($b['currency_decimals'] ?? 2)],
            'kitchen' => $this->usesKitchen(),
        ];
    }

    /** @return array{cats: list<array<string, mixed>>, items: list<array<string, mixed>>} the menu: categories and product tiles */
    public function menu(): array
    {
        $cat = new TillCatalog($this->business);
        $items = [];
        for ($page = 1; $page <= 6; $page++) {
            $r = $cat->products('', 0, false, $page);
            foreach ($r['items'] as $p) {
                if (! empty($p['rx'])) {
                    continue;   // prescription medicines are never sold from a table
                }
                $items[] = [
                    'id' => $p['id'], 'cat' => $p['cat'], 'name' => $p['name'], 'unit' => $p['unit'], 'img' => $p['img'], 'price' => $p['price'], 'compare' => $p['compare'],
                    'badge' => $p['badge'], 'oos' => $p['tracked'] && ($p['stock'] ?? 0) <= 0,
                    'variants' => array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'delta' => $v['delta'], 'oos' => $v['stock'] !== null && $v['stock'] <= 0], $p['variants']),
                ];
            }
            if (! $r['more']) {
                break;
            }
        }
        $used = array_flip(array_column($items, 'cat'));
        $cats = array_values(array_filter($cat->categories(), static fn (array $c): bool => isset($used[(int) $c['id']])));

        return ['cats' => array_map(static fn (array $c): array => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'icon' => $c['icon']], $cats), 'items' => $items];
    }

    /**
     * Price the cart (tax, service charge) without saving anything.
     *
     * @param array<string, mixed> $in
     * @return array{total: float, subtotal: float, lines: list<array<string, mixed>>}
     */
    public function quote(array $in): array
    {
        $lines = $this->lines($in);
        $q = service('orders')->quote(['lines' => $lines, 'order_type' => $this->dineIn(), 'table_ref' => $this->table['name'], 'channel' => 'web']);
        $t = $q['totals'];

        return ['subtotal' => (float) $t->subtotal, 'total' => (float) $t->total, 'lines' => array_map(static fn (array $l): array => ['name' => (string) $l['name']], $q['lines'])];
    }

    /**
     * Send the order to the kitchen.
     *
     * @param array<string, mixed> $in lines[{product_id, variant_id?, qty}], name?, phone?, note?
     * @return array{reference: string, number: int, total: float, status: string}
     */
    public function place(array $in): array
    {
        $errors = [];
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($phone !== '' && ! preg_match(self::PHONE, $phone)) {
            $errors['phone'] = 'Enter a valid phone number, or leave it empty.';
        }
        $note = trim(mb_substr((string) ($in['note'] ?? ''), 0, 200));
        if ($errors !== []) {
            throw new ValidationException('Please check your details.', $errors);
        }
        $draft = [
            'channel' => 'web', 'lines' => $this->lines($in),
            'customer' => ['name' => $name !== '' ? $name : $this->table['name'] . ' guest', 'phone' => $phone, 'email' => '', 'address' => null],
            'order_type' => $this->dineIn(), 'table_ref' => (string) $this->table['name'], 'note' => $note !== '' ? $note : null,
            'kitchen' => $this->usesKitchen(),
        ];
        $order = Tx::run(function () use ($draft): array {
            $draft['web_ref'] = $this->newReference();
            $o = service('orders')->create($draft);
            if ($o['status'] !== 'paid') {
                // the table's open bill: paid at the table, like "send to kitchen" at the till
                db_connect()->table('orders')->where('id', $o['id'])->update(['status' => 'open', 'updated_at' => date('Y-m-d H:i:s')]);
                $o['status'] = 'open';
            }

            return $o;
        });

        return ['reference' => (string) $order['web_ref'], 'number' => (int) $order['number'], 'total' => (float) $order['total'], 'status' => (string) $order['status']];
    }

    /**
     * Live status of an order of this table (for the guest's status page).
     *
     * @return array{reference: string, number: int, status: string, kitchen: string, total: float, step: int, items: list<array<string, mixed>>, placed_at: string}|null
     */
    public function status(string $ref): ?array
    {
        $db = db_connect();
        $o = $db->table('orders')->select('id, number, web_ref, status, kitchen_status, total, created_at')
            ->where('business_id', (int) $this->business['id'])->where('web_ref', $ref)->where('table_ref', (string) $this->table['name'])->get()->getRowArray();
        if ($o === null) {
            return null;
        }
        $items = $db->table('order_items')->select('name, variant_name, qty, line_total')->where('order_id', $o['id'])->orderBy('id')->get()->getResultArray();
        $k = (string) $o['kitchen_status'];
        $step = in_array((string) $o['status'], ['paid', 'returned'], true) || $k === 'served' ? 2 : (in_array($k, ['preparing', 'cooking', 'ready'], true) ? 1 : 0);

        return ['reference' => (string) $o['web_ref'], 'number' => (int) $o['number'], 'status' => (string) $o['status'], 'kitchen' => $k, 'total' => (float) $o['total'], 'step' => $step,
            'items' => array_map(static fn (array $i): array => ['name' => $i['name'] . ($i['variant_name'] ? ' (' . $i['variant_name'] . ')' : ''), 'qty' => (float) $i['qty'], 'total' => (float) $i['line_total']], $items),
            'placed_at' => (string) $o['created_at']];
    }

    /**
     * Cart lines from the request, checked against this outlet's active products.
     *
     * @param array<string, mixed> $in
     * @return list<array{product_id: int, variant_id: ?int, qty: float}>
     */
    private function lines(array $in): array
    {
        $items = $in['lines'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new ValidationException('Add at least one item.', ['lines' => 'Add at least one item.']);
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new ValidationException('At most ' . self::MAX_ITEMS . ' items per order.', ['lines' => 'Too many items.']);
        }
        $lines = [];
        foreach ($items as $i => $it) {
            $pid = is_array($it) ? ($it['product_id'] ?? null) : null;
            $vid = is_array($it) ? ($it['variant_id'] ?? null) : null;
            $qty = is_array($it) ? ($it['qty'] ?? 1) : 0;
            if (! is_numeric($pid) || (int) $pid < 1 || ! is_numeric($qty) || (float) $qty <= 0 || (float) $qty > 99) {
                throw new ValidationException('One of the items is not valid.', ['lines.' . $i => 'Invalid item.']);
            }
            $lines[] = ['product_id' => (int) $pid, 'variant_id' => is_numeric($vid) && (int) $vid > 0 ? (int) $vid : null, 'qty' => round((float) $qty, 3)];
        }
        // only products on this outlet's menu (active, not prescription-only)
        $ids = array_values(array_unique(array_column($lines, 'product_id')));
        $ok = array_column(db_connect()->table('products')->select('id')->where('business_id', (int) $this->business['id'])->where('is_active', 1)->where('deleted_at', null)->where('is_rx', 0)->whereIn('id', $ids)->get()->getResultArray(), 'id');
        if (count($ok) !== count($ids)) {
            throw new ValidationException('Some items are not on the menu any more. Please review your order.', ['lines' => 'Item not available.']);
        }

        return $lines;
    }

    private function dineIn(): string
    {
        foreach ((array) ($this->business['settings']['features']['orderTypes'] ?? []) as $t) {
            if (stripos((string) $t, 'dine') !== false) {
                return (string) $t;
            }
        }

        return 'Dine In';
    }

    /** Menu businesses send table orders to the kitchen board (kitchen module on, switch on and allowed). */
    private function usesKitchen(): bool
    {
        $b = $this->business;
        if (($b['store_kind'] ?? '') !== 'menu') {
            return false;
        }
        $access = service('moduleAccess')->forTenant((int) $b['tenant_id']);

        return $access->has('kitchen') && (bool) ($b['settings']['features']['kitchen'] ?? true)
            && in_array('kitchen', \OmniPOS\Core\Services\BusinessTypeSetup::allowedFeatures((string) $b['business_type'], $access), true);
    }

    /** Public order reference: "W" + 6 base-36 characters, unique (web_ref is unique across outlets). */
    private function newReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $ref = 'W' . strtoupper(str_pad(base_convert((string) random_int(0, 36 ** 6 - 1), 10, 36), 6, '0', STR_PAD_LEFT));
            if (db_connect()->table('orders')->where('web_ref', $ref)->countAllResults() === 0) {
                return $ref;
            }
        }

        throw new \RuntimeException('Could not allocate an order reference.');
    }
}
