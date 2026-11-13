<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Register\Services\RegisterService;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Pricing\OrderTotals;
use OmniPOS\Core\Pricing\TaxPolicy;

/**
 * The POS till on the server. The browser keeps only the cart (product ids, options, quantities,
 * customer, order type, table, discount); every price and total comes from OrderService
 * (quote → create), which re-prices from the catalogue. Role rules are applied here:
 *   pos.price     price override per line (trusted_prices)
 *   pos.discount  order discount / VAT override, limited to the role's max_discount (owner: no limit)
 *   pos.credit    credit sale / leaving a balance due (named customer only)
 *
 * Checkout is idempotent per cart token: a double click or a retried request returns the same
 * order instead of selling twice (idempotency_keys row, api_key_id 0, key "pos:<outlet>:<token>").
 *
 * Held carts ("Suspend"): held_carts rows — no invoice number, stock or payment until resumed.
 * "Send to kitchen / pay later": a real order (stock taken, ticket on the board) left with status
 * 'open' and no payment; it is paid later from the till or the order page (addPayments).
 */
final class TillService
{
    private const MAX_LINES = 300;

    private array $business;
    private array $features;

    public function __construct()
    {
        helper(['ui', 'pos']);
        $this->business = service('posSession')->business();
        $this->features = (array) ($this->business['settings']['features'] ?? []);
        // Pharmacy: expired-batch policy (block by default; "warn" lets the till take expired stock last)
        \App\Modules\Pharmacy\Services\PharmacySettings::applyToTill($this->business);
    }

    private function can(string $perm): bool
    {
        return PosSettings::allows($perm);
    }

    /**
     * Turn the browser cart into an OrderService draft (permissions applied).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [draft, meta]
     */
    public function draft(array $cart): array
    {
        $s = service('posSession');
        $trusted = $this->can('pos.price');
        $lines = [];
        foreach (array_slice((array) ($cart['lines'] ?? []), 0, self::MAX_LINES) as $l) {
            if (! is_array($l) || (int) ($l['product_id'] ?? 0) <= 0) {
                continue;
            }
            $line = ['product_id' => (int) $l['product_id'], 'qty' => (float) ($l['qty'] ?? 1)];
            if (! empty($l['variant_id'])) {
                $line['variant_id'] = (int) $l['variant_id'];
            }
            if (isset($l['serial']) && trim((string) $l['serial']) !== '') {
                $line['serial'] = mb_substr(trim((string) $l['serial']), 0, 64);
                $line['qty'] = 1;
            }
            if (isset($l['note']) && trim((string) $l['note']) !== '') {
                $line['note'] = mb_substr(trim((string) $l['note']), 0, 255);
            }
            if (isset($l['unit_price']) && $l['unit_price'] !== '' && $l['unit_price'] !== null) {
                if (! $trusted) {
                    throw new PermissionDeniedException('Your role cannot change item prices.');
                }
                $line['unit_price'] = max(0, (float) $l['unit_price']);
            }
            if ($trusted && ! empty($l['discount_value'])) {
                $line['discount_type'] = ($l['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
                $line['discount_value'] = max(0, (float) $l['discount_value']);
            }
            $lines[] = $line;
        }

        $draft = ['channel' => 'pos', 'lines' => $lines, 'trusted_prices' => $trusted];
        $c = (array) ($cart['customer'] ?? []);
        if (! empty($c['id'])) {
            $draft['customer'] = ['id' => (int) $c['id']];
        } elseif (trim((string) ($c['name'] ?? '')) !== '' || trim((string) ($c['phone'] ?? '')) !== '') {
            $draft['customer'] = ['name' => mb_substr(trim((string) ($c['name'] ?? '')), 0, 120), 'phone' => mb_substr((string) ($c['phone'] ?? ''), 0, 32)];
        }
        $disc = (array) ($cart['discount'] ?? []);
        $dv = max(0, round((float) ($disc['value'] ?? 0), 2));
        if ($dv > 0) {
            if (! $this->can('pos.discount')) {
                throw new PermissionDeniedException('Your role cannot give discounts.');
            }
            $draft['discount'] = ['type' => ($disc['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent', 'value' => $dv];
        }
        $tax = $cart['tax_override'] ?? null;
        if ($tax !== null && $tax !== '') {
            if (! $this->can('pos.discount')) {
                throw new PermissionDeniedException('Your role cannot change the VAT rate.');
            }
            $rates = TaxPolicy::fromBusiness($this->business)->rates();
            if (! in_array((float) $tax, $rates, true)) {
                throw new ValidationException('Choose one of the outlet\'s VAT rates.', ['tax_override' => 'Not allowed']);
            }
            $draft['tax_override'] = (float) $tax;
        }
        $types = (array) ($this->features['orderTypes'] ?? []);
        $ot = (string) ($cart['order_type'] ?? '');
        if ($types !== []) {
            $draft['order_type'] = in_array($ot, $types, true) ? $ot : (string) $types[0];
        }
        $meta = ['minutes' => 0, 'rate' => 0.0, 'table' => null];
        $tableRef = trim((string) ($cart['table_ref'] ?? ''));
        if ($tableRef !== '') {
            $t = db_connect()->table('dining_tables')->select('id, name, hourly_rate')->where('business_id', (int) $this->business['id'])->where('name', $tableRef)->get()->getRowArray();
            if ($t === null) {
                throw new ValidationException('That table / room does not exist.', ['table_ref' => 'Unknown']);
            }
            $draft['table_ref'] = $t['name'];
            $meta['table'] = $t;
            $checkin = (int) ($cart['checkin'] ?? 0);
            if (! empty($this->features['hourly']) && $t['hourly_rate'] !== null && $checkin > 0) {
                $now = time();
                $checkin = min($now, max($now - 86400, $checkin));
                $minutes = max((int) ($this->features['minMinutes'] ?? 0), (int) ceil(($now - $checkin) / 60));
                $meta['minutes'] = $minutes;
                $meta['rate'] = (float) $t['hourly_rate'];
                $draft['hourly_charge'] = round((float) $t['hourly_rate'] * $minutes / 60, 2);
                $draft['note_time'] = date('h:i A', $checkin) . ' – ' . date('h:i A', $now) . ' (' . $minutes . ' min)';
            }
        }
        $staff = trim((string) ($cart['staff_name'] ?? ''));
        $staffList = array_merge((array) ($this->business['settings']['serviceStaff'] ?? []), (array) ($this->business['settings']['staff'] ?? []));
        if ($staff !== '' && in_array($staff, $staffList, true)) {
            $draft['staff_name'] = $staff;
        }
        $ship = (array) ($cart['shipping'] ?? []);
        if ((float) ($ship['charge'] ?? 0) > 0 || trim((string) ($ship['address'] ?? '')) !== '') {
            $draft['shipping'] = ['charge' => min(1000000, max(0, round((float) ($ship['charge'] ?? 0), 2))), 'address' => mb_substr(trim((string) ($ship['address'] ?? '')), 0, 255) ?: null, 'status' => 'ordered'];
        }
        if (! empty($cart['express']) && ! empty($this->features['express'])) {
            $draft['express'] = true;
        }
        if (! empty($cart['kitchen']) && ! empty($this->features['kitchen']) && $s->moduleOn('kitchen')) {
            $draft['kitchen'] = true;
        }
        $note = trim((string) ($cart['note'] ?? ''));
        if (isset($draft['note_time'])) {
            $note = trim('Time ' . $draft['note_time'] . ($note !== '' ? ' · ' . $note : ''));
        }
        unset($draft['note_time']);
        if ($note !== '') {
            $draft['note'] = mb_substr($note, 0, 500);
        }
        $due = (string) ($cart['due_date'] ?? '');
        if (! empty($this->features['dueDate']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
            $draft['due_date'] = substr($due, 0, 10);
        }
        $draft['staff_name'] ??= null;
        $draft['register_id'] = RegisterService::forCurrent()->currentId();

        return [$draft, $meta];
    }

    /**
     * Price the cart (no writes).
     *
     * @return array<string, mixed>
     */
    public function quote(array $cart): array
    {
        [$draft, $meta] = $this->draft($cart);
        if ($draft['lines'] === [] && empty($draft['hourly_charge'])) {
            return ['lines' => [], 'totals' => null, 'rows' => [], 'meta' => $meta, 'warning' => null];
        }
        if ($draft['lines'] === []) {
            throw new ValidationException('Add at least one item.', ['lines' => 'Empty']);
        }
        $q = service('orders')->quote($draft);
        $t = $q['totals'];
        $warning = null;
        try {
            $this->assertDiscount($t);
        } catch (BusinessRuleException $e) {
            $warning = $e->getMessage();
        }
        $cur = service('posSession')->currency();
        $lines = [];
        foreach ($q['lines'] as $i => $l) {
            $lt = $t->lineTaxes[$i];
            $lines[] = [
                'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'name' => $l['name'], 'variant_name' => $l['variant_name'], 'sku' => $l['sku'],
                'unit' => $l['unit'], 'qty' => $l['qty'], 'unit_price' => round($l['unit_price'] + $l['upcharge'], 2),
                'amount' => round(($l['unit_price'] + $l['upcharge']) * $l['qty'], 2), 'serial' => $l['serial'],
                'tax' => $lt['mode'] === 'exempt' ? 'Exempt' : rate_str($lt['rate']) . '% ' . ($lt['mode'] === 'inclusive' ? 'incl.' : 'added'),
            ];
        }

        return ['lines' => $lines, 'totals' => $t->toArray(), 'rows' => $this->rows($t, $draft, $meta, $cur), 'meta' => $meta, 'warning' => $warning,
            'total_text' => money($t->total, $cur)];
    }

    /** The cart summary rows shown under the lines (labels as in the design build). */
    private function rows(OrderTotals $t, array $draft, array $meta, array $cur): array
    {
        $b = $this->business;
        $label = (string) ($b['tax_label'] ?? 'VAT');
        $rows = [['Total', money($t->subtotal, $cur), null]];
        if ($t->hourly > 0) {
            $rows[] = [(\OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []))['seat']) . ' time (' . $meta['minutes'] . ' min)', money($t->hourly, $cur), null];
        }
        if ($t->express > 0) {
            $rows[] = ['Express (+' . rate_str($this->features['expressRate'] ?? 0) . '%)', money($t->express, $cur), null];
        }
        if ((bool) ($this->features['discount'] ?? true)) {
            $d = $draft['discount'] ?? null;
            $rows[] = ['Discount' . ($d && $d['type'] === 'percent' ? ' (' . rate_str($d['value']) . '%)' : '') . ' (-)', money($t->discount, $cur), 'discount'];
        }
        $rows[] = [$label . ' (' . $t->taxSummary() . ')' . ($t->taxAdded > 0 ? ' (+)' : ''), money($t->tax, $cur), 'tax'];
        $rows[] = ['Shipping (+)', money($t->shipping, $cur), 'shipping'];
        if ($t->packing > 0) {
            $rows[] = [($draft['order_type'] ?? 'Order') . ' charge (+)', money($t->packing, $cur), null];
        }
        if ($t->serviceCharge > 0) {
            $rows[] = ['Service (' . rate_str($b['service_charge_pct']) . '%)', money($t->serviceCharge, $cur), null];
        }
        if ((float) $b['round_to'] > 0) {
            $rows[] = ['Round off', money($t->roundOff, $cur), null];
        }

        return $rows;
    }

    /** Order discount within the role's limit (owner roles: no limit). */
    private function assertDiscount(OrderTotals $t): void
    {
        if ($t->discount <= 0) {
            return;
        }
        $u = service('posSession')->user();
        if ($u === null || $u['locked'] || $u['is_owner']) {
            return;
        }
        $base = $t->subtotal + $t->hourly + $t->express;
        $pct = $base > 0 ? $t->discount / $base * 100 : 100;
        $max = (float) $u['max_discount'];
        if ($pct > $max + 0.001) {
            throw new BusinessRuleException(sprintf('Your role can give up to %s%% discount (this is %s%%). Ask a manager.', rate_str($max), rate_str(round($pct, 2))));
        }
    }

    /**
     * Finalize the cart with payments.
     *
     * @param list<array{method: string, amount: float|string, reference?: string}> $payments
     * @return array{order: array<string, mixed>, change: float, tendered: float, replay: bool}
     */
    public function checkout(array $cart, array $payments, string $token): array
    {
        return $this->once($token, function () use ($cart, $payments): array {
            [$draft] = $this->draft($cart);
            if ($draft['lines'] === []) {
                throw new ValidationException('Add at least one item.', ['lines' => 'Empty']);
            }
            $q = service('orders')->quote($draft);
            $t = $q['totals'];
            $this->assertDiscount($t);

            $allowed = array_values(array_unique(array_merge(['cash', 'exchange'], (array) ($this->business['settings']['payments'] ?? ['cash']))));
            $rows = [];
            $sum = 0.0;
            $cash = 0.0;
            $credit = 0.0;
            foreach (array_slice($payments, 0, 10) as $p) {
                $m = preg_replace('/[^a-z_]/', '', strtolower((string) ($p['method'] ?? '')));
                $amt = round((float) ($p['amount'] ?? 0), 2);
                if ($amt <= 0) {
                    continue;
                }
                if (! in_array($m, $allowed, true)) {
                    throw new ValidationException(pay_label($m) . ' is not accepted at this outlet.', ['payments' => 'Method not offered']);
                }
                $rows[] = ['method' => $m, 'amount' => $amt, 'reference' => mb_substr(trim((string) ($p['reference'] ?? '')), 0, 64) ?: null];
                if ($m === 'credit') {
                    $credit += $amt;
                } else {
                    $sum += $amt;
                    $cash += $m === 'cash' ? $amt : 0;
                }
            }
            $change = round(max(0, $sum - $t->total), 2);
            if ($change > 0 && $cash + 0.009 < $change) {
                throw new BusinessRuleException('Change can only be returned from a cash payment.');
            }
            $left = round($t->total - min($sum, $t->total), 2);
            if ($left > 0.009) {
                // the rest goes on the customer's due book
                if (! $this->can('pos.credit')) {
                    throw new PermissionDeniedException('Your role cannot leave a balance due. Take the full amount or ask a manager.');
                }
                if (empty($draft['customer'])) {
                    throw new BusinessRuleException('Choose the customer to sell on credit (বাকি).');
                }
                $rows = array_values(array_filter($rows, static fn ($r) => $r['method'] !== 'credit'));
                $rows[] = ['method' => 'credit', 'amount' => $left, 'reference' => null];
            } elseif ($credit > 0) {
                $rows = array_values(array_filter($rows, static fn ($r) => $r['method'] !== 'credit'));
            }
            $draft['payments'] = $rows;
            $order = service('orders')->create($draft);
            service('usage')->touch((int) $order['tenant_id']);

            return ['order_id' => (int) $order['id'], 'change' => $change, 'tendered' => round($cash, 2)];
        });
    }

    /**
     * Send the order to the kitchen / table now and take payment later (status 'open').
     *
     * @return array{order: array<string, mixed>, change: float, tendered: float, replay: bool}
     */
    public function openOrder(array $cart, string $token): array
    {
        if (empty($this->features['kitchen']) && empty($this->features['seats'])) {
            throw new BusinessRuleException('Pay-later orders are for kitchens and table service.');
        }

        return $this->once($token, function () use ($cart): array {
            [$draft] = $this->draft($cart);
            if ($draft['lines'] === []) {
                throw new ValidationException('Add at least one item.', ['lines' => 'Empty']);
            }
            $this->assertDiscount(service('orders')->quote($draft)['totals']);
            $draft['payments'] = [];
            $order = service('orders')->create($draft);
            // nothing paid yet: an open tab, not a due on the customer's book
            db_connect()->table('orders')->where('id', $order['id'])->where('business_id', (int) $this->business['id'])->where('paid_total', 0)
                ->update(['status' => 'open', 'updated_at' => date('Y-m-d H:i:s')]);
            service('usage')->touch((int) $order['tenant_id']);

            return ['order_id' => (int) $order['id'], 'change' => 0.0, 'tendered' => 0.0];
        });
    }

    /**
     * Run a write once per cart token.
     *
     * @param callable(): array{order_id: int, change: float, tendered: float} $work
     */
    private function once(string $token, callable $work): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) {
            throw new ValidationException('The cart token is missing. Reload the till.', ['token' => 'Invalid']);
        }
        $db = db_connect();
        $key = 'pos:' . (int) $this->business['id'] . ':' . $token;
        $db->query('INSERT IGNORE INTO idempotency_keys (api_key_id, idem_key, request_hash, status_code, response, created_at) VALUES (0, ?, ?, 0, ?, ?)',
            [$key, hash('sha256', $key), '{}', date('Y-m-d H:i:s')]);
        if ($db->affectedRows() === 0) {
            $row = $db->table('idempotency_keys')->where('api_key_id', 0)->where('idem_key', $key)->get()->getRowArray();
            $resp = json_decode((string) ($row['response'] ?? '{}'), true) ?: [];
            if ((int) ($row['status_code'] ?? 0) === 200 && ! empty($resp['order_id'])) {
                return ['order' => service('orders')->find((int) $resp['order_id']), 'change' => (float) ($resp['change'] ?? 0), 'tendered' => (float) ($resp['tendered'] ?? 0), 'replay' => true];
            }

            throw new ConflictException('This sale is already being saved. Please wait a moment.');
        }
        try {
            $r = $work();
        } catch (\Throwable $e) {
            $db->table('idempotency_keys')->where('api_key_id', 0)->where('idem_key', $key)->delete();
            throw $e;
        }
        $db->table('idempotency_keys')->where('api_key_id', 0)->where('idem_key', $key)->update(['status_code' => 200, 'response' => json_encode($r)]);
        try {
            // the sale gets its invoice (POS-00001 …) right away; a failure here never undoes the sale
            \App\Modules\Invoices\Services\InvoiceSync::forCurrent()->ensure((int) $r['order_id']);
        } catch (\Throwable $e) {
            log_message('warning', 'Invoice for sale {id}: {msg}', ['id' => $r['order_id'], 'msg' => $e->getMessage()]);
        }

        return ['order' => service('orders')->find($r['order_id']), 'change' => $r['change'], 'tendered' => $r['tendered'], 'replay' => false];
    }

    /* ------------------------------------------------------------ held carts */

    public function hold(array $cart, string $note, string $kind = 'hold'): int
    {
        if (! in_array($kind, ['hold', 'draft', 'quotation'], true)) {
            $kind = 'hold';
        }
        if ($kind !== 'hold' && ! $this->can('pos.quotation')) {
            throw new PermissionDeniedException('Your role cannot save drafts or quotations.');
        }
        [$draft] = $this->draft($cart);
        if ($draft['lines'] === [] && empty($cart['table_ref'])) {
            throw new ValidationException('Add items first.', ['lines' => 'Empty']);
        }
        $total = 0.0;
        $count = 0.0;
        if ($draft['lines'] !== []) {
            $t = service('orders')->quote($draft)['totals'];
            $total = $t->total;
            $count = $t->itemCount;
        }
        $u = service('posSession')->user();
        unset($cart['token']);
        $now = date('Y-m-d H:i:s');
        db_connect()->table('held_carts')->insert([
            'tenant_id' => service('tenant')->tenantId(), 'business_id' => (int) $this->business['id'], 'kind' => $kind,
            'user_id' => $u['id'] ?? null, 'user_name' => $u['name'] ?? null, 'note' => mb_substr(trim($note), 0, 255) ?: null,
            'customer_name' => mb_substr((string) ($cart['customer']['name'] ?? 'Walk-in Customer'), 0, 120), 'table_ref' => $draft['table_ref'] ?? null,
            'item_count' => $count, 'total' => $total, 'cart' => json_encode($cart, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) db_connect()->insertID();
        service('audit')->activity($kind === 'hold' ? 'Sale suspended' : ucfirst($kind) . ' saved', money($total, service('posSession')->currency()));

        return $id;
    }

    /** @return array{held: list<array<string, mixed>>, open: list<array<string, mixed>>} */
    public function parked(): array
    {
        $db = db_connect();
        $bid = (int) $this->business['id'];

        return [
            'held' => $db->table('held_carts')->select('id, kind, note, customer_name, table_ref, item_count, total, user_name, created_at')
                ->where('business_id', $bid)->orderBy('created_at', 'DESC')->limit(60)->get()->getResultArray(),
            'open' => $db->table('orders')->select('id, doc_no, customer_name, table_ref, item_count, total, due_total, kitchen_status, created_at')
                ->where('business_id', $bid)->where('status', 'open')->orderBy('created_at', 'DESC')->limit(60)->get()->getResultArray(),
        ];
    }

    /** Take a held cart back into the till (it is removed from the list). */
    public function resume(int $id): array
    {
        $bid = (int) $this->business['id'];

        return Tx::run(static function ($db) use ($id, $bid): array {
            $row = $db->query('SELECT * FROM held_carts WHERE id = ? AND business_id = ? FOR UPDATE', [$id, $bid])->getRowArray()
                ?? throw new NotFoundException('This suspended sale was already resumed.');
            $db->table('held_carts')->where('id', $id)->where('business_id', $bid)->delete();

            return ['kind' => $row['kind'], 'note' => $row['note'], 'cart' => json_decode((string) $row['cart'], true) ?: []];
        });
    }

    public function deleteHeld(int $id): void
    {
        $db = db_connect();
        $db->table('held_carts')->where('id', $id)->where('business_id', (int) $this->business['id'])->delete();
        if ($db->affectedRows() === 0) {
            throw new NotFoundException('Suspended sale not found.');
        }
        service('audit')->activity('Suspended sale deleted', '#' . $id);
    }
}
