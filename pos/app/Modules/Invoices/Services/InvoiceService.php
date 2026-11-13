<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Modules\Orders\Services\OrderActions;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Pricing\CartLine;
use OmniPOS\Core\Pricing\TaxPolicy;

/**
 * Invoices (module "invoicing") for every business — also those that never open the till.
 *
 * An invoice starts as a draft (invoices row only: no number, no stock, not a sale). Issuing it
 * writes an order through the core OrderService (channel "invoice"): prices and VAT from
 * OrderCalculator, stock taken, the unpaid part on the customer's due book, and the next number of
 * the invoice series (DocSequence, row-locked, gap-free) — so reports, dues, stock and the POS see
 * the invoice like any other sale. Payments go through the same path as the Orders page, an edit of
 * an issued invoice through OrderService::revise() and a void through OrderService::void().
 *
 * Prices: without "invoices.edit_price" lines are priced from the catalogue and only quantities
 * change (lines already on the invoice keep their price); with it the unit price, line discount,
 * VAT rule (one of the outlet's own rates), order discount and free lines can be set.
 *
 * Every change is kept in invoice_revisions (who, when, a summary and the invoice before / after).
 */
final class InvoiceService
{
    public const STATES = ['draft' => 'Draft', 'due' => 'Issued · unpaid', 'partial' => 'Partially paid', 'paid' => 'Paid', 'returned' => 'Refunded', 'void' => 'Void'];

    private array $business;

    public function __construct()
    {
        helper(['ui', 'pos']);
        $this->business = service('posSession')->business();
    }

    public static function forCurrent(): self
    {
        return new self();
    }

    private function bid(): int
    {
        return (int) $this->business['id'];
    }

    private function can(string $perm): bool
    {
        return service('posSession')->can($perm);
    }

    public function canEditPrice(): bool
    {
        return $this->can('invoices.edit_price');
    }

    /** Display state of an invoice: draft | due | partial | paid | returned | void */
    public static function state(array $inv, ?string $orderStatus = null): string
    {
        if ($inv['status'] !== 'issued') {
            return (string) $inv['status'];
        }

        return match ($orderStatus) {
            'paid' => 'paid', 'partial' => 'partial', 'returned' => 'returned', 'cancelled' => 'void', default => 'due',
        };
    }

    /** @return list<string> payment methods offered on invoices */
    public function methods(): array
    {
        return array_values(array_unique(array_merge(['cash'], array_filter((array) ($this->business['settings']['payments'] ?? ['cash']), static fn ($m) => $m !== 'credit'))));
    }

    /* ------------------------------------------------------------------ reading */

    /** @return array<string, mixed> invoice row (+ order: OrderService::find(), mushak row) */
    public function find(int $id): array
    {
        $inv = db_connect()->table('invoices')->where('id', $id)->where('business_id', $this->bid())->get()->getRowArray()
            ?? throw new NotFoundException('Invoice not found.');
        $inv['data'] = json_decode((string) ($inv['draft'] ?? '{}'), true) ?: [];
        $inv['order'] = $inv['order_id'] ? service('orders')->find((int) $inv['order_id']) : null;
        $inv['state'] = self::state($inv, $inv['order']['status'] ?? null);
        $inv['mushak'] = $inv['order_id'] ? db_connect()->table('mushak_invoices')->select('id, doc_no, fiscal_year, serial, issued_at')
            ->where('business_id', $this->bid())->where('order_id', $inv['order_id'])->get()->getRowArray() : null;

        return $inv;
    }

    /** @return list<array<string, mixed>> newest first */
    public function revisions(int $id): array
    {
        $rows = db_connect()->table('invoice_revisions')->select('id, revision, action, summary, before_data, after_data, user_name, created_at')
            ->where('business_id', $this->bid())->where('invoice_id', $id)->orderBy('id', 'DESC')->limit(100)->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['before'] = json_decode((string) $r['before_data'], true);
            $r['after'] = json_decode((string) $r['after_data'], true);
            unset($r['before_data'], $r['after_data']);
        }

        return $rows;
    }

    /**
     * Lines for the editor: a draft's own lines, or an issued invoice's sold lines (with the price,
     * discount and VAT they were sold at).
     *
     * @return list<array<string, mixed>>
     */
    public function editorLines(array $inv, ?array $oldInput = null): array
    {
        $lines = $oldInput !== null ? self::linesFromInput($oldInput) : ($inv['order'] !== null ? self::orderLines($inv['order']) : (array) ($inv['data']['lines'] ?? []));
        $catalog = $this->catalogPrices($lines);
        $ids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['product_id'] ?? 0), $lines))));
        $products = $ids ? array_column(db_connect()->table('products')->select('id, name, sku, unit, price, stock_type, tax_mode, tax_rate')->where('business_id', $this->bid())->whereIn('id', $ids)->get()->getResultArray(), null, 'id') : [];
        foreach (array_values($lines) as $i => $l) {
            $lines[$i]['product'] = $products[(int) ($l['product_id'] ?? 0)] ?? null;
            $lines[$i]['catalog'] = $catalog[$i] ?? null;
        }

        return array_values($lines);
    }

    /**
     * The lines as they were submitted (the editor refills them after a validation error).
     *
     * @return list<array<string, mixed>>
     */
    public static function linesFromInput(array $raw): array
    {
        $out = [];
        foreach (array_values(array_slice($raw, 0, 200)) as $r) {
            if (! is_array($r)) {
                continue;
            }
            $l = ['qty' => is_numeric($r['qty'] ?? null) ? (float) $r['qty'] : 0.0, 'name' => (string) ($r['name'] ?? ''), 'unit' => $r['unit'] ?? null, 'note' => $r['note'] ?? null];
            if (! empty($r['custom']) && empty($r['product_id'])) {
                $l['custom'] = true;
                if (isset($r['keep'])) {
                    $l['keep'] = (int) $r['keep'];
                }
            } else {
                $l['product_id'] = (int) ($r['product_id'] ?? 0);
                $l['variant_id'] = (int) ($r['variant_id'] ?? 0) ?: null;
                $l['serial'] = $r['serial'] ?? null;
            }
            if (isset($r['unit_price']) && trim((string) $r['unit_price']) !== '' && is_numeric($r['unit_price'])) {
                $l['unit_price'] = (float) $r['unit_price'];
            }
            if (isset($r['discount_value']) && is_numeric($r['discount_value']) && (float) $r['discount_value'] > 0) {
                $l['discount_type'] = ($r['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
                $l['discount_value'] = (float) $r['discount_value'];
            }
            $vat = trim((string) ($r['vat'] ?? ''));
            if ($vat !== '') {
                [$m, $rate] = array_pad(explode('|', $vat, 2), 2, null);
                $l['tax_mode'] = $m;
                $l['tax_rate'] = $m === 'exempt' || ! is_numeric($rate) ? null : (float) $rate;
            }
            if (($l['product_id'] ?? 1) > 0 || ! empty($l['custom'])) {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * Sold lines of an order as invoice lines (price as sold, line discount per unit as a fixed amount).
     *
     * @return list<array<string, mixed>>
     */
    public static function orderLines(array $order): array
    {
        $out = [];
        foreach ($order['items'] as $it) {
            $l = [
                'name' => (string) $it['name'], 'unit' => $it['unit'], 'qty' => (float) $it['qty'], 'unit_price' => (float) $it['unit_price'],
                'discount_type' => 'fixed', 'discount_value' => (float) $it['discount'], 'tax_mode' => (string) $it['tax_mode'],
                'tax_rate' => $it['tax_mode'] === 'exempt' ? null : (float) $it['tax_rate'], 'note' => $it['note'],
            ];
            if ($it['product_id'] === null) {
                $l['custom'] = true;
            } else {
                $l += ['product_id' => (int) $it['product_id'], 'variant_id' => $it['variant_id'] !== null ? (int) $it['variant_id'] : null, 'serial' => $it['serial']];
            }
            $out[] = $l;
        }

        return $out;
    }

    /**
     * Payment method tiles of the payment card: the outlet's own accepted methods only (Setup ›
     * payments), in this order. A "digital wallet" has no method code in OmniPOS, so it has no tile.
     *
     * @return list<array{code: string, label: string, hint: string, icon: string}>
     */
    public function paymentTiles(): array
    {
        $code = (string) ($this->business['currency_code'] ?? 'BDT');
        $defs = [
            'cash' => ['Cash', 'Notes & coins', 'cash'],
            'bank' => ['Bank transfer', 'Deposit / EFT / RTGS', 'bank'],
            'card' => ['Card', 'Debit / credit card', 'card'],
            'mobile' => [$code === 'BDT' ? 'bKash / Nagad / Rocket' : 'Mobile money', $code === 'BDT' ? 'Mobile wallet' : 'Mobile wallet', 'mobile'],
            'cheque' => ['Cheque', 'Bank cheque', 'cheque'],
        ];
        $out = [];
        foreach ($defs as $c => [$label, $hint, $icon]) {
            if (in_array($c, $this->methods(), true)) {
                $out[] = ['code' => $c, 'label' => $label, 'hint' => $hint, 'icon' => $icon];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> payments of the invoice's sale (who took them), oldest first */
    public function paymentHistory(array $inv): array
    {
        if (empty($inv['order_id'])) {
            return [];
        }

        return db_connect()->table('order_payments p')->select('p.id, p.method, p.amount, p.reference, p.created_at, u.name AS user_name')
            ->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.order_id', (int) $inv['order_id'])->where('p.business_id', $this->bid())->orderBy('p.id')->get()->getResultArray();
    }

    /**
     * Activity of an invoice, newest first: its revisions (created, edited, issued, revised, voided,
     * Mushak-6.3), every payment of the sale (also those taken on the Orders page or as due
     * collection) and returns.
     *
     * @return list<array{at: string, kind: string, title: string, detail: string, user: ?string, before: mixed, after: mixed}>
     */
    public function timeline(array $inv, ?array $revisions = null, ?array $payments = null): array
    {
        $titles = ['created' => 'Draft created', 'edited' => 'Draft edited', 'issued' => 'Issued', 'revised' => 'Edited after issue', 'voided' => 'Voided', 'mushak' => 'Mushak-6.3 issued'];
        $out = [];
        foreach ($revisions ?? $this->revisions((int) $inv['id']) as $r) {
            if ($r['action'] === 'payment') {
                continue; // payments come from the sale itself below
            }
            $out[] = ['at' => (string) $r['created_at'], 'kind' => (string) $r['action'], 'title' => $titles[$r['action']] ?? ucfirst((string) $r['action']),
                'detail' => (string) $r['summary'], 'user' => $r['user_name'], 'before' => $r['before'], 'after' => $r['after'], 'rev' => (int) $r['revision']];
        }
        $code = (string) ($this->business['currency_code'] ?? 'BDT');
        foreach ($payments ?? $this->paymentHistory($inv) as $p) {
            $out[] = ['at' => (string) $p['created_at'], 'kind' => 'payment', 'title' => 'Payment received',
                'detail' => money($p['amount'], service('posSession')->currency()) . ' · ' . pay_label((string) $p['method'], $code) . (! empty($p['reference']) ? ' · ' . $p['reference'] : ''),
                'user' => $p['user_name'], 'before' => null, 'after' => null, 'rev' => null];
        }
        foreach ((array) ($inv['order']['returns'] ?? []) as $r) {
            $out[] = ['at' => (string) $r['created_at'], 'kind' => 'return', 'title' => 'Return / refund', 'detail' => money($r['amount'], service('posSession')->currency()) . (! empty($r['reason']) ? ' · ' . $r['reason'] : ''),
                'user' => null, 'before' => null, 'after' => null, 'rev' => null];
        }
        usort($out, static fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }

    /* ------------------------------------------------------------------ input */

    /**
     * Validated invoice from the editor form.
     *
     * @param list<array<string, mixed>> $existing the invoice's current lines (kept prices for users without the price permission)
     * @return array<string, mixed>
     */
    public function normalize(array $in, array $existing = [], array $existingDiscount = ['type' => 'percent', 'value' => 0], ?string $existingDate = null): array
    {
        $canPrice = $this->canEditPrice();
        $errors = [];
        $str = static fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        // customer
        $customerId = (int) ($in['customer_id'] ?? 0);
        $cust = null;
        if ($customerId > 0) {
            $cust = db_connect()->table('customers')->select('id, name, phone, email, address, is_walk_in')->where('id', $customerId)->where('business_id', $this->bid())->get()->getRowArray();
            if ($cust === null) {
                $errors['customer_id'] = 'Customer not found.';
            }
        }
        $bill = [
            'bill_name' => $str('bill_name', 120) ?? ($cust['name'] ?? null), 'bill_phone' => $str('bill_phone', 32) ?? ($cust['phone'] ?? null),
            'bill_email' => $str('bill_email', 160) ?? ($cust['email'] ?? null), 'bill_address' => $str('bill_address', 255) ?? ($cust['address'] ?? null),
            'bill_bin' => $str('bill_bin', 20),
        ];
        if ($bill['bill_email'] !== null && ! filter_var($bill['bill_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['bill_email'] = 'Enter a valid email or leave it empty.';
        }
        if ($bill['bill_bin'] !== null) {
            $bin = MushakSettings::normalizeBin($bill['bill_bin']);
            if ($bin === null) {
                $errors['bill_bin'] = 'A BIN has 9 digits (or 13 with the branch code).';
            } else {
                $bill['bill_bin'] = $bin;
            }
        }
        $due = trim((string) ($in['due_date'] ?? ''));
        if ($due !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || strtotime($due) === false)) {
            $errors['due_date'] = 'Pick a date.';
        }
        $invDate = $this->invoiceDate($in, $existingDate, $errors);
        if ($due !== '' && $invDate !== null && ! isset($errors['due_date']) && $due < $invDate) {
            $errors['due_date'] = 'The due date cannot be before the invoice date.';
        }

        // lines
        $byKey = [];
        foreach ($existing as $i => $e) {
            if (empty($e['custom'])) {
                $byKey[(int) ($e['product_id'] ?? 0) . ':' . (int) ($e['variant_id'] ?? 0)] = $e;
            }
        }
        $lines = [];
        foreach (array_values(array_slice((array) ($in['lines'] ?? []), 0, 200)) as $n => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $qty = round((float) ($raw['qty'] ?? 0), 3);
            if ($qty <= 0 || $qty > 100000) {
                $errors['lines.' . $n . '.qty'] = 'Quantity must be more than 0.';
                continue;
            }
            $priced = $this->priceFields($raw);
            $custom = ! empty($raw['custom']) && empty($raw['product_id']);
            if (! $canPrice) {
                if ($custom && isset($raw['keep']) && isset($existing[(int) $raw['keep']]) && ! empty($existing[(int) $raw['keep']]['custom'])) {
                    // a free line someone with the price permission added: kept as it is
                    $lines[] = $this->cleanLine($existing[(int) $raw['keep']]);
                    continue;
                }
                if ($custom || $priced !== []) {
                    throw new PermissionDeniedException('Your role cannot change invoice prices, discounts, VAT or add free lines.');
                }
            }
            if ($custom) {
                $name = mb_substr(trim((string) ($raw['name'] ?? '')), 0, 200);
                if ($name === '') {
                    $errors['lines.' . $n . '.name'] = 'Describe the line.';
                    continue;
                }
                if (! isset($priced['unit_price'])) {
                    $errors['lines.' . $n . '.unit_price'] = 'Enter a price.';
                    continue;
                }
                $lines[] = ['custom' => true, 'name' => $name, 'unit' => mb_substr(trim((string) ($raw['unit'] ?? '')), 0, 16) ?: null, 'qty' => $qty,
                    'note' => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 255) ?: null] + $priced;
                continue;
            }
            $pid = (int) ($raw['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $vid = (int) ($raw['variant_id'] ?? 0);
            $line = ['product_id' => $pid, 'variant_id' => $vid ?: null, 'qty' => $qty, 'name' => mb_substr((string) ($raw['name'] ?? ''), 0, 200),
                'note' => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 255) ?: null];
            if (! empty($raw['serial'])) {
                $line['serial'] = mb_substr((string) $raw['serial'], 0, 64);
            }
            if ($canPrice) {
                $line += $priced;
            } elseif (isset($byKey[$pid . ':' . $vid])) {
                // already on the invoice: keeps the price it was given
                $line += array_intersect_key($byKey[$pid . ':' . $vid], array_flip(['unit_price', 'discount_type', 'discount_value', 'tax_mode', 'tax_rate']));
            }
            $lines[] = $line;
        }
        if ($lines === [] && ! isset($errors['lines'])) {
            $errors['lines'] = 'Add at least one product, service or line.';
        }

        // order discount
        $discount = $existingDiscount;
        $dv = trim((string) ($in['discount_value'] ?? ''));
        if ($canPrice) {
            $discount = ['type' => ($in['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent', 'value' => is_numeric($dv) ? max(0.0, round((float) $dv, 2)) : 0.0];
            if ($discount['type'] === 'percent' && $discount['value'] > 100) {
                $errors['discount_value'] = 'A percentage up to 100.';
            }
        } elseif ($dv !== '' && (float) $dv != (float) ($existingDiscount['value'] ?? 0)) {
            throw new PermissionDeniedException('Your role cannot change the invoice discount.');
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the invoice.', $errors);
        }

        return [
            'customer' => $cust !== null ? ['id' => (int) $cust['id']] : array_filter(['name' => $bill['bill_name'], 'phone' => $bill['bill_phone'], 'email' => $bill['bill_email'], 'address' => $bill['bill_address']]),
            'customer_id' => $cust !== null ? (int) $cust['id'] : null, 'named' => $cust !== null ? ! (bool) $cust['is_walk_in'] : ($bill['bill_name'] !== null && $bill['bill_name'] !== 'Walk-in Customer'),
            'bill' => $bill, 'lines' => $lines, 'discount' => $discount,
            'ship_to' => $str('ship_to', 255), 'reference' => $str('reference', 64), 'due_date' => $due !== '' ? $due : null,
            'notes' => $str('notes', 2000), 'terms' => $str('terms', 2000), 'invoice_date' => $invDate, 'page' => self::pageOverrides($in),
        ];
    }

    /**
     * The date printed on the invoice (Y-m-d). Today, or the date it already had; any other date
     * (back-dating, up to a year) needs "pos.backdate". Stock, dues and reports always use the time
     * the invoice is issued.
     */
    private function invoiceDate(array $in, ?string $existing, array &$errors): ?string
    {
        $d = trim((string) ($in['invoice_date'] ?? ''));
        if ($d === '') {
            return $existing ?? date('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || strtotime($d) === false) {
            $errors['invoice_date'] = 'Pick a date.';

            return $existing;
        }
        if ($d === $existing || $d === date('Y-m-d')) {
            return $d;
        }
        if ($d > date('Y-m-d')) {
            $errors['invoice_date'] = 'The invoice date cannot be in the future.';
        } elseif ($d < date('Y-m-d', strtotime('-1 year'))) {
            $errors['invoice_date'] = 'At most one year back.';
        } elseif (! $this->can('pos.backdate')) {
            $errors['invoice_date'] = 'Your role can date invoices today only.';
        }

        return $d;
    }

    /** Price fields of a submitted line (only those actually given). */
    private function priceFields(array $raw): array
    {
        $out = [];
        if (isset($raw['unit_price']) && trim((string) $raw['unit_price']) !== '') {
            if (! is_numeric($raw['unit_price']) || (float) $raw['unit_price'] < 0) {
                throw new ValidationException('Enter a valid price.', ['lines.unit_price' => 'Invalid price']);
            }
            $out['unit_price'] = round((float) $raw['unit_price'], 2);
        }
        if (isset($raw['discount_value']) && trim((string) $raw['discount_value']) !== '' && (float) $raw['discount_value'] != 0) {
            if (! is_numeric($raw['discount_value']) || (float) $raw['discount_value'] < 0) {
                throw new ValidationException('Enter a valid discount.', ['lines.discount_value' => 'Invalid discount']);
            }
            $out['discount_type'] = ($raw['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $out['discount_value'] = round((float) $raw['discount_value'], 2);
        }
        $vat = trim((string) ($raw['vat'] ?? ''));
        if ($vat !== '') {
            [$mode, $rate] = array_pad(explode('|', $vat, 2), 2, null);
            if (! in_array($mode, [TaxPolicy::INCLUSIVE, TaxPolicy::EXCLUSIVE, TaxPolicy::EXEMPT], true)) {
                throw new ValidationException('Choose a VAT rule.', ['lines.vat' => 'Invalid VAT']);
            }
            if ($mode !== TaxPolicy::EXEMPT && (! is_numeric($rate) || ! in_array((float) $rate, TaxPolicy::fromBusiness($this->business)->rates(), false))) {
                throw new ValidationException('Choose one of the outlet\'s VAT rates.', ['lines.vat' => 'Unknown rate']);
            }
            $out['tax_mode'] = $mode;
            $out['tax_rate'] = $mode === TaxPolicy::EXEMPT ? null : (float) $rate;
        }

        return $out;
    }

    private function cleanLine(array $l): array
    {
        return array_intersect_key($l, array_flip(['custom', 'product_id', 'variant_id', 'serial', 'name', 'unit', 'qty', 'unit_price', 'discount_type', 'discount_value', 'tax_mode', 'tax_rate', 'note']));
    }

    /** OrderService lines from normalized invoice lines (prices are server-checked, so trusted). */
    public static function orderDraftLines(array $lines): array
    {
        return array_map(static function (array $l): array {
            $o = array_intersect_key($l, array_flip(['product_id', 'variant_id', 'serial', 'qty', 'note', 'unit_price', 'discount_type', 'discount_value', 'tax_mode', 'tax_rate', 'custom', 'name', 'unit']));
            if (empty($l['custom'])) {
                unset($o['name'], $o['unit'], $o['custom']);
            }

            return $o;
        }, $lines);
    }

    /**
     * Totals and per-line amounts of an invoice being edited (OrderCalculator via OrderService::quote).
     *
     * @return array<string, mixed>
     */
    public function quote(array $n): array
    {
        $q = service('orders')->quote(['channel' => 'invoice', 'lines' => self::orderDraftLines($n['lines']), 'discount' => $n['discount'], 'trusted_prices' => true]);
        $t = $q['totals'];
        $lines = [];
        foreach ($q['lines'] as $i => $l) {
            $src = $n['lines'][$i];
            $cl = new CartLine(qty: (float) $l['qty'], unitPrice: (float) $l['unit_price'], discountType: (string) ($src['discount_type'] ?? 'percent'), discountValue: (float) ($src['discount_value'] ?? 0));
            $lt = $t->lineTaxes[$i];
            $lines[] = ['name' => $l['name'], 'qty' => (float) $l['qty'], 'unit_price' => (float) $l['unit_price'], 'amount' => round($cl->amount(), 2),
                'vat' => $lt['mode'] === 'exempt' ? 'Exempt' : rate_str($lt['rate']) . '% ' . ($lt['mode'] === 'inclusive' ? 'incl.' : 'added'), 'tax' => $lt['tax']];
        }

        return ['lines' => $lines, 'subtotal' => $t->subtotal, 'discount' => $t->discount, 'service' => $t->serviceCharge + $t->packing, 'tax' => $t->tax,
            'tax_added' => $t->taxAdded, 'tax_included' => $t->taxIncluded, 'round_off' => $t->roundOff, 'total' => $t->total, 'taxLines' => $t->taxLines];
    }

    /* ------------------------------------------------------------------ writing */

    /** Create or change a draft. Returns the invoice id. */
    public function saveDraft(?int $id, array $in): int
    {
        $inv = $id !== null ? $this->find($id) : null;
        if ($inv !== null && $inv['status'] !== 'draft') {
            throw new BusinessRuleException('Only a draft can be saved as a draft — this invoice is ' . $inv['state'] . '.');
        }
        $n = $this->normalize($in, (array) ($inv['data']['lines'] ?? []), (array) ($inv['data']['discount'] ?? ['type' => 'percent', 'value' => 0]), $inv['data']['invoice_date'] ?? null);
        $q = $this->quote($n);
        foreach ($n['lines'] as $i => $l) {
            if (($l['name'] ?? '') === '' && isset($q['lines'][$i]['name'])) {
                $n['lines'][$i]['name'] = $q['lines'][$i]['name']; // the product's name, for the draft and its history
            }
        }
        $s = service('posSession');
        $now = date('Y-m-d H:i:s');
        $row = $n['bill'] + [
            'bill_name' => $n['bill']['bill_name'] ?? 'Walk-in Customer', 'customer_id' => $n['customer_id'], 'ship_to' => $n['ship_to'], 'reference' => $n['reference'],
            'due_date' => $n['due_date'], 'notes' => $n['notes'], 'terms' => $n['terms'], 'total' => $q['total'],
            'draft' => json_encode(['lines' => $n['lines'], 'discount' => $n['discount'], 'customer' => $n['customer'], 'named' => $n['named'], 'invoice_date' => $n['invoice_date'], 'page' => $n['page'] + (array) ($inv['data']['page'] ?? [])], JSON_UNESCAPED_UNICODE),
            'updated_by' => $s->userId() ?: null, 'updated_at' => $now,
        ];
        $row['bill_name'] = $row['bill_name'] ?? 'Walk-in Customer';

        return Tx::run(function (BaseConnection $db) use ($inv, $row, $n, $q, $s, $now): int {
            if ($inv === null) {
                $db->table('invoices')->insert($row + ['tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'status' => 'draft', 'created_by' => $s->userId() ?: null, 'created_at' => $now]);
                $id = (int) $db->insertID();
                $snap = $this->draftSnapshot($row, $n, $q);
                $this->log($id, 0, 'created', 'Draft created · ' . number_format($q['total'], 2) . self::overridesText($snap), null, $snap);

                return $id;
            }
            $db->table('invoices')->where('id', $inv['id'])->where('business_id', $this->bid())->update($row + ['revision' => (int) $inv['revision'] + 1]);
            $before = $this->draftSnapshot($inv, ['lines' => (array) ($inv['data']['lines'] ?? []), 'discount' => $inv['data']['discount'] ?? null, 'invoice_date' => $inv['data']['invoice_date'] ?? null], ['total' => (float) $inv['total']]);
            $after = $this->draftSnapshot($row, $n, $q);
            $this->log((int) $inv['id'], (int) $inv['revision'] + 1, 'edited', self::summarize($before, $after), $before, $after);

            return (int) $inv['id'];
        });
    }

    /**
     * Issue a draft: number it, write the sale (stock, VAT, due book) and take the first payment.
     *
     * @param list<array{method: string, amount: float|string, reference?: string}> $payments
     * @return array<string, mixed> the invoice (find())
     */
    public function issue(int $id, array $payments = []): array
    {
        $pay = $this->payments($payments);
        $s = service('posSession');
        Tx::run(function (BaseConnection $db) use ($id, $pay, $s): void {
            $inv = $db->query('SELECT * FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', [$id, $this->bid()])->getRowArray() ?? throw new NotFoundException('Invoice not found.');
            if ($inv['status'] !== 'draft') {
                throw new BusinessRuleException('This invoice was already ' . ($inv['status'] === 'void' ? 'voided' : 'issued') . '.');
            }
            $data = json_decode((string) ($inv['draft'] ?? '{}'), true) ?: [];
            if (empty($data['lines'])) {
                throw new ValidationException('The invoice has no lines.', ['lines' => 'Add at least one line.']);
            }
            $q = $this->quote($data);
            $paid = array_sum(array_column($pay, 'amount'));
            if ($paid + 0.009 < $q['total'] && empty($data['named'])) {
                throw new BusinessRuleException('Choose or enter the customer to leave a due on the invoice (walk-in invoices are paid in full).');
            }
            $cfg = InvoiceSettings::forCurrent()->get();
            $now = date('Y-m-d H:i:s');
            $period = InvoiceSettings::period($cfg, $now);
            $docNo = InvoiceSettings::format($cfg, DocSequence::next($this->bid(), 'invoice', $period), $period);
            if ($paid + 0.009 < $q['total']) {
                // the unpaid part goes on the customer's due book (credit limit applies)
                $pay[] = ['method' => 'credit', 'amount' => $q['total']];
            }
            $order = service('orders')->create([
                'channel' => 'invoice', 'lines' => self::orderDraftLines($data['lines']), 'customer' => (array) ($data['customer'] ?? []), 'discount' => $data['discount'] ?? null,
                'payments' => $pay, 'trusted_prices' => true, 'doc_no' => $docNo, 'due_date' => $inv['due_date'], 'note' => $inv['notes'] !== null ? mb_substr((string) $inv['notes'], 0, 500) : null,
                'register_id' => \App\Modules\Register\Services\RegisterService::forCurrent()->currentId(),
            ]);
            $db->table('orders')->where('id', $order['id'])->where('business_id', $this->bid())->update(['acknowledged_at' => $now, 'acknowledged_by' => $s->userId() ?: null]);
            $db->table('invoices')->where('id', $id)->update([
                'status' => 'issued', 'order_id' => $order['id'], 'doc_no' => $order['doc_no'], 'customer_id' => $order['customer_id'], 'total' => $order['total'],
                'issued_by' => $s->userId() ?: null, 'issued_at' => $now, 'updated_at' => $now, 'revision' => (int) $inv['revision'] + 1,
            ]);
            $after = $this->orderSnapshot($inv, service('orders')->find((int) $order['id']));
            $this->log($id, (int) $inv['revision'] + 1, 'issued', 'Issued as ' . $order['doc_no'] . ' · ' . number_format((float) $order['total'], 2) . ($paid > 0 ? ' · paid ' . number_format($paid, 2) : '') . self::overridesText($after), null, $after);
        });
        $inv = $this->find($id);
        service('audit')->activity('Invoice issued', $inv['doc_no'] . ' · ' . number_format((float) $inv['total'], 2));
        $m = MushakSettings::forCurrent();
        if ($m->autoIssue() && $this->can('mushak.issue') && service('posSession')->access()->hasFeature('invoicing.mushak')) {
            MushakService::forCurrent()->issue((int) $inv['order_id'], ['buyer_bin' => $inv['bill_bin'], 'buyer_address' => $inv['bill_address'], 'destination' => $inv['ship_to']]);
            $inv = $this->find($id);
        }

        return $inv;
    }

    /** Edit an issued invoice (lines, discount, billing details, notes). */
    public function revise(int $id, array $in): array
    {
        $inv = $this->find($id);
        if ($inv['status'] !== 'issued' || $inv['order'] === null) {
            throw new BusinessRuleException('Only an issued invoice can be revised.');
        }
        if ($inv['mushak'] !== null) {
            throw new BusinessRuleException('A Mushak-6.3 (' . $inv['mushak']['doc_no'] . ') was issued for this invoice — it can no longer be changed. Record changes with a credit / debit note.');
        }
        $o = $inv['order'];
        $n = $this->normalize($in, self::orderLines($o), ['type' => $o['discount_type'] === 'amount' ? 'fixed' : 'percent', 'value' => (float) $o['discount_value']], $inv['data']['invoice_date'] ?? substr((string) $inv['issued_at'], 0, 10));
        $before = $this->orderSnapshot($inv, $o);
        $s = service('posSession');
        Tx::run(function (BaseConnection $db) use ($inv, $n, $s): void {
            $db->query('SELECT id FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', [$inv['id'], $this->bid()]);
            $order = service('orders')->revise((int) $inv['order_id'], [
                'lines' => self::orderDraftLines($n['lines']), 'discount' => $n['discount'], 'trusted_prices' => true,
                'note' => $n['notes'] !== null ? mb_substr($n['notes'], 0, 500) : null, 'due_date' => $n['due_date'],
            ]);
            $bill = $n['bill'];
            $bill['bill_name'] = $bill['bill_name'] ?? $inv['bill_name'];
            $db->table('invoices')->where('id', $inv['id'])->update($bill + [
                'ship_to' => $n['ship_to'], 'reference' => $n['reference'], 'due_date' => $n['due_date'], 'notes' => $n['notes'], 'terms' => $n['terms'],
                'total' => $order['total'], 'draft' => json_encode(['lines' => $n['lines'], 'discount' => $n['discount'], 'customer' => ['id' => $order['customer_id']], 'named' => true, 'invoice_date' => $n['invoice_date'] ?? $inv['data']['invoice_date'] ?? null, 'page' => $n['page'] + (array) ($inv['data']['page'] ?? [])], JSON_UNESCAPED_UNICODE),
                'revision' => (int) $inv['revision'] + 1, 'updated_by' => $s->userId() ?: null, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        $inv2 = $this->find($id);
        $after = $this->orderSnapshot($inv2, $inv2['order']);
        $this->log($id, (int) $inv2['revision'], 'revised', self::summarize($before, $after), $before, $after);
        service('audit')->activity('Invoice revised', $inv2['doc_no'] . ' · ' . number_format((float) $inv2['total'], 2));

        return $inv2;
    }

    /**
     * Take a payment on an issued invoice (same rules as the Orders page: cash change, due book).
     *
     * @param list<array{method: string, amount: float|string, reference?: string}> $payments
     */
    public function pay(int $id, array $payments, ?string $date = null): array
    {
        $inv = $this->find($id);
        if ($inv['status'] !== 'issued' || $inv['order'] === null) {
            throw new BusinessRuleException('Issue the invoice before taking a payment.');
        }
        $date = $date !== null && trim($date) !== '' ? trim($date) : date('Y-m-d');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > date('Y-m-d')) {
            throw new ValidationException('Pick the payment date (today or earlier).', ['date' => 'Invalid date']);
        }
        if ($date !== date('Y-m-d') && ! $this->can('pos.backdate')) {
            throw new PermissionDeniedException('Your role can record payments for today only.');
        }
        $lastId = (int) (db_connect()->table('order_payments')->selectMax('id', 'm')->where('order_id', $inv['order_id'])->get()->getRow('m') ?? 0);
        $before = ['paid' => (float) $inv['order']['paid_total'], 'due' => (float) $inv['order']['due_total'], 'status' => $inv['state']];
        $r = (new OrderActions())->pay((int) $inv['order_id'], $this->payments($payments));
        if ($date !== date('Y-m-d')) {
            // a payment received earlier (cheque / bank transfer recorded late): dated as received
            db_connect()->table('order_payments')->where('order_id', $inv['order_id'])->where('business_id', $this->bid())->where('id >', $lastId)
                ->update(['created_at' => $date . ' ' . date('H:i:s')]);
            $r['order'] = service('orders')->find((int) $inv['order_id']);
        }
        $o = $r['order'];
        $after = ['paid' => (float) $o['paid_total'], 'due' => (float) $o['due_total'], 'status' => self::state($inv, $o['status'])];
        $amt = round($after['paid'] - $before['paid'], 2);
        $this->log($id, (int) $inv['revision'], 'payment', 'Payment ' . number_format($amt, 2) . ' · ' . implode(', ', array_map(static fn ($p) => pay_label((string) $p['method']), $payments)) . ($r['change'] > 0 ? ' · change ' . number_format($r['change'], 2) : ''), $before, $after);

        return $r;
    }

    /** Void a draft, or an issued invoice nothing was paid on (stock back, due removed). */
    public function void(int $id, string $reason): void
    {
        $inv = $this->find($id);
        $reason = mb_substr(trim($reason), 0, 255);
        if ($inv['status'] === 'void') {
            throw new BusinessRuleException('This invoice is already void.');
        }
        if ($inv['status'] === 'issued' && mb_strlen($reason) < 3) {
            throw new ValidationException('Say why the invoice is voided.', ['reason' => 'Required']);
        }
        if ($inv['mushak'] !== null) {
            throw new BusinessRuleException('A Mushak-6.3 (' . $inv['mushak']['doc_no'] . ') was issued for this invoice — cancel it with a credit note instead of voiding.');
        }
        $s = service('posSession');
        Tx::run(function (BaseConnection $db) use ($inv, $reason, $s): void {
            $db->query('SELECT id FROM invoices WHERE id = ? AND business_id = ? FOR UPDATE', [$inv['id'], $this->bid()]);
            if ($inv['order_id']) {
                service('orders')->void((int) $inv['order_id'], 'Invoice voided: ' . $reason);
            }
            $db->table('invoices')->where('id', $inv['id'])->update(['status' => 'void', 'voided_by' => $s->userId() ?: null, 'voided_at' => date('Y-m-d H:i:s'),
                'void_reason' => $reason !== '' ? $reason : null, 'revision' => (int) $inv['revision'] + 1, 'updated_at' => date('Y-m-d H:i:s')]);
        });
        $this->log((int) $inv['id'], (int) $inv['revision'] + 1, 'voided', 'Voided' . ($reason !== '' ? ': ' . $reason : ''), ['status' => $inv['state']], ['status' => 'void']);
        service('audit')->activity('Invoice voided', ($inv['doc_no'] ?? 'Draft #' . $inv['id']) . ($reason !== '' ? ' · ' . $reason : ''));
    }

    /** @return list<array{method: string, amount: float, reference: ?string}> */
    private function payments(array $payments): array
    {
        $allowed = $this->methods();
        $out = [];
        foreach (array_slice($payments, 0, 10) as $p) {
            $amt = round((float) ($p['amount'] ?? 0), 2);
            if ($amt <= 0) {
                continue;
            }
            $m = preg_replace('/[^a-z_]/', '', strtolower((string) ($p['method'] ?? 'cash')));
            if (! in_array($m, $allowed, true)) {
                throw new ValidationException(pay_label($m) . ' is not accepted here.', ['method' => 'Method not offered']);
            }
            $out[] = ['method' => $m, 'amount' => $amt, 'reference' => mb_substr(trim((string) ($p['reference'] ?? '')), 0, 64) ?: null];
        }

        return $out;
    }

    /**
     * Texts of the printed page this invoice changes (title, footer line); keys not sent keep the
     * invoice page settings.
     *
     * @return array<string, string>
     */
    private static function pageOverrides(array $in): array
    {
        $out = [];
        foreach (['doc_title' => ['title', 40], 'footer_text' => ['footer_text', 200]] as $field => [$key, $max]) {
            if (array_key_exists($field, $in)) {
                $v = mb_substr(trim((string) $in[$field]), 0, $max);
                if ($key === 'title' && $v === '') {
                    continue;
                }
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * Catalogue price of each line (product price + option price; null for free lines).
     *
     * @param list<array<string, mixed>> $lines lines with product_id / variant_id
     * @return array<int, ?float> line index => catalogue unit price
     */
    private function catalogPrices(array $lines): array
    {
        $db = db_connect();
        $pids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['product_id'] ?? 0), $lines))));
        $vids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['variant_id'] ?? 0), $lines))));
        $prices = $pids ? array_column($db->table('products')->select('id, price')->where('business_id', $this->bid())->whereIn('id', $pids)->get()->getResultArray(), 'price', 'id') : [];
        $deltas = $vids ? array_column($db->table('product_variants')->select('id, price_delta')->where('business_id', $this->bid())->whereIn('id', $vids)->get()->getResultArray(), 'price_delta', 'id') : [];
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            $out[$i] = $pid > 0 && isset($prices[$pid]) ? round((float) $prices[$pid] + (float) ($deltas[(int) ($l['variant_id'] ?? 0)] ?? 0), 2) : null;
        }

        return $out;
    }

    /** "Rice 65.00 (catalogue 72.00)" for every line charged at a custom price. */
    private static function overridesText(array $snapshot): string
    {
        $out = [];
        foreach ((array) ($snapshot['lines'] ?? []) as $l) {
            if (! empty($l['custom_price'])) {
                $out[] = $l['name'] . ' ' . number_format((float) $l['unit_price'], 2) . ' (catalogue ' . number_format((float) $l['catalog'], 2) . ')';
            }
        }

        return $out === [] ? '' : ' · custom prices: ' . implode(', ', $out);
    }

    /**
     * Bulk "Adjust prices" (invoices.edit_price): new unit prices for the given lines.
     *
     *   mode up | down  change by `value` percent (unit = pct) or a fixed amount (unit = amount)
     *   mode target     set each line's total (after its line discount) to `value`: the unit
     *                   price is worked back from the quantity and the line discount
     *   round           none | 1 | 5 | 10 — the new unit price to the nearest step
     *
     * @param list<array{unit_price: float|string, qty: float|string, discount_type?: string, discount_value?: float|string}> $lines
     * @return list<array{old: float, new: float, total: float}>
     */
    public static function adjustPrices(array $lines, string $mode, string $unit, float $value, string $round = 'none', int $decimals = 2): array
    {
        if (! in_array($mode, ['up', 'down', 'target'], true) || ! in_array($unit, ['pct', 'amount'], true) || ! in_array($round, ['none', '1', '5', '10'], true)) {
            throw new ValidationException('Choose how to adjust the prices.', ['adjust' => 'Invalid adjustment']);
        }
        if ($value < 0 || ($mode === 'down' && $unit === 'pct' && $value > 100)) {
            throw new ValidationException('Enter a positive amount (at most 100%).', ['adjust_value' => 'Invalid value']);
        }
        $out = [];
        foreach ($lines as $l) {
            $old = round((float) ($l['unit_price'] ?? 0), $decimals);
            $qty = (float) ($l['qty'] ?? 0);
            $dType = (string) ($l['discount_type'] ?? 'percent');
            $dVal = (float) ($l['discount_value'] ?? 0);
            if ($mode === 'target') {
                if ($qty <= 0) {
                    throw new ValidationException('Every line needs a quantity to set its total.', ['adjust_value' => 'No quantity']);
                }
                $new = $dType === 'fixed' ? $value / $qty + $dVal : ($dVal >= 100 ? 0.0 : $value / ($qty * (1 - max(0.0, $dVal) / 100)));
            } else {
                $delta = $unit === 'pct' ? $old * $value / 100 : $value;
                $new = $mode === 'up' ? $old + $delta : $old - $delta;
            }
            $new = max(0.0, $new);
            if ($round !== 'none') {
                $step = (float) $round;
                $new = round($new / $step) * $step;
            }
            $new = round($new, $decimals);
            $line = new CartLine(qty: $qty, unitPrice: $new, discountType: $dType, discountValue: $dVal);
            $out[] = ['old' => $old, 'new' => $new, 'total' => round($line->amount(), $decimals)];
        }

        return $out;
    }
    /* ------------------------------------------------------------------ audit */

    public function log(int $invoiceId, int $revision, string $action, string $summary, ?array $before, ?array $after): void
    {
        $s = service('posSession');
        db_connect()->table('invoice_revisions')->insert([
            'tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'invoice_id' => $invoiceId, 'revision' => $revision, 'action' => $action,
            'summary' => mb_substr($summary, 0, 500), 'before_data' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_data' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'user_id' => $s->userId() ?: null, 'user_name' => mb_substr((string) ($s->user()['name'] ?? ''), 0, 100) ?: null,
            'ip_address' => service('request')->getIPAddress(), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> comparable picture of a draft */
    private function draftSnapshot(array $row, array $n, array $q): array
    {
        $lines = [];
        $catalog = $this->catalogPrices((array) ($n['lines'] ?? []));
        foreach (array_values((array) ($n['lines'] ?? [])) as $i => $l) {
            $charged = isset($l['unit_price']) ? (float) $l['unit_price'] : ($q['lines'][$i]['unit_price'] ?? null);
            $lines[] = ['name' => (string) (($l['name'] ?? '') !== '' ? $l['name'] : ($q['lines'][$i]['name'] ?? '')), 'qty' => (float) $l['qty'], 'unit_price' => $charged,
                'catalog' => $catalog[$i], 'custom_price' => $catalog[$i] !== null && $charged !== null && abs((float) $charged - $catalog[$i]) > 0.004,
                'discount' => isset($l['discount_value']) ? $l['discount_value'] . ($l['discount_type'] === 'fixed' ? '' : '%') : null,
                'vat' => isset($l['tax_mode']) ? $l['tax_mode'] . ($l['tax_rate'] !== null ? ' ' . rate_str($l['tax_rate']) . '%' : '') : null];
        }

        return self::header($row) + ['invoice_date' => $n['invoice_date'] ?? null, 'page' => $n['page'] ?? [], 'discount' => $n['discount'] ?? null, 'lines' => $lines, 'total' => (float) ($q['total'] ?? 0)];
    }

    /** @return array<string, mixed> comparable picture of an issued invoice */
    private function orderSnapshot(array $inv, array $o): array
    {
        $catalog = $this->catalogPrices($o['items']);
        $lines = array_map(static fn (array $it, ?float $cat): array => ['name' => (string) $it['name'], 'qty' => (float) $it['qty'], 'unit_price' => (float) $it['unit_price'],
            'catalog' => $cat, 'custom_price' => $cat !== null && abs((float) $it['unit_price'] - $cat) > 0.004,
            'discount' => (float) $it['discount'] > 0 ? (string) (float) $it['discount'] : null, 'vat' => $it['tax_mode'] . ($it['tax_mode'] !== 'exempt' ? ' ' . rate_str($it['tax_rate']) . '%' : ''),
            'amount' => (float) $it['line_total']], $o['items'], array_values($catalog));

        return self::header($inv) + ['invoice_date' => $inv['data']['invoice_date'] ?? null, 'page' => (array) ($inv['data']['page'] ?? []), 'doc_no' => $o['doc_no'], 'discount' => ['type' => $o['discount_type'], 'value' => (float) $o['discount_value']], 'lines' => $lines,
            'vat' => (float) $o['tax_total'], 'total' => (float) $o['total'], 'paid' => (float) $o['paid_total'], 'due' => (float) $o['due_total']];
    }

    private static function header(array $row): array
    {
        return array_intersect_key($row, array_flip(['bill_name', 'bill_phone', 'bill_email', 'bill_address', 'bill_bin', 'ship_to', 'reference', 'due_date', 'notes', 'terms']));
    }

    /** One line that says what changed between two snapshots. */
    public static function summarize(array $before, array $after): string
    {
        $out = [];
        foreach (['invoice_date' => 'Invoice date', 'bill_name' => 'Customer', 'bill_phone' => 'Phone', 'bill_address' => 'Address', 'bill_bin' => 'Buyer BIN', 'ship_to' => 'Ship to', 'reference' => 'Reference', 'due_date' => 'Due date', 'notes' => 'Notes', 'terms' => 'Terms'] as $k => $label) {
            if (($before[$k] ?? null) != ($after[$k] ?? null)) {
                $out[] = $label . ($k === 'notes' || $k === 'terms' ? ' changed' : ': ' . ($before[$k] ?? '—') . ' → ' . ($after[$k] ?? '—'));
            }
        }
        $index = static function (array $lines): array {
            $m = [];
            foreach ($lines as $l) {
                $m[(string) $l['name']] = $l;
            }

            return $m;
        };
        $b = $index((array) ($before['lines'] ?? []));
        $a = $index((array) ($after['lines'] ?? []));
        foreach ($a as $name => $l) {
            if (! isset($b[$name])) {
                $out[] = 'Added ' . $name . ' × ' . rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.');
                continue;
            }
            $o = $b[$name];
            if ((float) $o['qty'] != (float) $l['qty']) {
                $out[] = $name . ' qty ' . rtrim(rtrim(number_format((float) $o['qty'], 3, '.', ''), '0'), '.') . ' → ' . rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.');
            }
            if (($o['unit_price'] ?? null) != ($l['unit_price'] ?? null)) {
                $out[] = $name . ' price ' . ($o['unit_price'] ?? '—') . ' → ' . ($l['unit_price'] ?? '—') . (! empty($l['custom_price']) ? ' (custom; catalogue ' . number_format((float) $l['catalog'], 2) . ')' : (isset($l['catalog']) && $l['catalog'] !== null && ! empty($o['custom_price']) ? ' (back to catalogue)' : ''));
            }
            if (($o['discount'] ?? null) != ($l['discount'] ?? null)) {
                $out[] = $name . ' discount ' . ($o['discount'] ?? '—') . ' → ' . ($l['discount'] ?? '—');
            }
            if (($o['vat'] ?? null) != ($l['vat'] ?? null)) {
                $out[] = $name . ' VAT ' . ($o['vat'] ?? 'product rule') . ' → ' . ($l['vat'] ?? 'product rule');
            }
        }
        foreach (array_diff_key($b, $a) as $name => $l) {
            $out[] = 'Removed ' . $name;
        }
        if (($before['discount'] ?? null) != ($after['discount'] ?? null)) {
            $out[] = 'Invoice discount changed';
        }
        foreach (['title' => 'Title', 'footer_text' => 'Footer line'] as $k => $label) {
            if (($before['page'][$k] ?? null) != ($after['page'][$k] ?? null) && array_key_exists($k, (array) ($after['page'] ?? []))) {
                $out[] = $label . ': ' . (($after['page'][$k] ?? '') !== '' ? $after['page'][$k] : '—');
            }
        }
        if ((float) ($before['total'] ?? 0) != (float) ($after['total'] ?? 0)) {
            $out[] = 'Total ' . number_format((float) ($before['total'] ?? 0), 2) . ' → ' . number_format((float) ($after['total'] ?? 0), 2);
        }

        return $out === [] ? 'Saved (no changes)' : implode('; ', $out);
    }

    /* ------------------------------------------------------------------ pickers */

    /** @return list<array<string, mixed>> products for the line picker */
    public function products(string $q, int $limit = 20): array
    {
        $db = db_connect();
        $b = $db->table('products')->select('id, category_id, name, sku, barcode, unit, price, image_path, stock_type, stock_qty, tax_mode, tax_rate')
            ->where('business_id', $this->bid())->where('is_active', 1)->where('deleted_at', null);
        $q = trim($q);
        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('sku', $q, 'after')->orWhere('barcode', $q)->groupEnd();
        }
        $rows = $b->orderBy('name')->limit(max(1, min(300, $limit)))->get()->getResultArray();
        $ids = array_column($rows, 'id');
        $variants = [];
        if ($ids !== []) {
            foreach ($db->table('product_variants')->select('id, product_id, name, price_delta')->where('business_id', $this->bid())->whereIn('product_id', $ids)->get()->getResultArray() as $v) {
                $variants[(int) $v['product_id']][] = ['id' => (int) $v['id'], 'name' => $v['name'], 'price' => (float) $v['price_delta']];
            }
        }
        $policy = TaxPolicy::fromBusiness($this->business);

        return array_map(static fn (array $p): array => [
            'id' => (int) $p['id'], 'cat' => (int) $p['category_id'], 'img' => $p['image_path'] ? base_url((string) $p['image_path']) : null, 'name' => $p['name'], 'sku' => $p['sku'], 'unit' => $p['unit'], 'price' => (float) $p['price'],
            'stock' => $p['stock_type'] === 'none' ? null : (float) $p['stock_qty'], 'vat' => $policy->ruleFor((string) $p['tax_mode'], $p['tax_rate'] !== null ? (float) $p['tax_rate'] : null)->label(),
            'tax_mode' => (string) $p['tax_mode'], 'tax_rate' => $p['tax_rate'] !== null ? (float) $p['tax_rate'] : null,
            'variants' => array_map(static fn ($v) => ['id' => $v['id'], 'name' => $v['name'], 'price' => (float) $p['price'] + $v['price']], $variants[(int) $p['id']] ?? []),
        ], $rows);
    }

    /** @return array{cats: list<array<string, mixed>>, items: list<array<string, mixed>>} the product tiles of the invoice form (click to add) */
    public function catalog(): array
    {
        $items = $this->products('', 300);
        $names = [];
        foreach (db_connect()->table('categories')->select('id, name, icon')->where('business_id', $this->bid())->orderBy('sort_order')->orderBy('name')->get()->getResultArray() as $c) {
            $names[(int) $c['id']] = ['id' => (int) $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'n' => 0];
        }
        foreach ($items as $i) {
            if (isset($names[$i['cat']])) {
                $names[$i['cat']]['n']++;
            }
        }

        return ['cats' => array_values(array_filter($names, static fn (array $c): bool => $c['n'] > 0)), 'items' => $items];
    }

    /** @return list<array<string, mixed>> customers for the bill-to picker */
    public function customers(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return db_connect()->table('customers')->select('id, name, phone, email, address, balance_due')->where('business_id', $this->bid())->where('is_walk_in', 0)
            ->groupStart()->like('name', $q)->orLike('phone', $q)->groupEnd()->orderBy('name')->limit(10)->get()->getResultArray();
    }

    /** VAT choices for the price editor: [value => label] ('' = the product's own rule). */
    public function vatChoices(): array
    {
        $out = ['' => 'Product rule', 'exempt' => 'Exempt'];
        foreach (TaxPolicy::fromBusiness($this->business)->rates() as $r) {
            if ($r <= 0) {
                continue;
            }
            $out['inclusive|' . rate_str($r)] = rate_str($r) . '% incl.';
            $out['exclusive|' . rate_str($r)] = rate_str($r) . '% added';
        }

        return $out;
    }
}
