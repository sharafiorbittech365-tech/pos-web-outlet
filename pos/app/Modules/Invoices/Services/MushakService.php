<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * NBR Mushak-6.3 (কর চালানপত্র — tax invoice, VAT & SD Rules 2016, rule 40(1)(c) and (f)) for a
 * sale of a VAT-registered outlet (see docs/modules/mushak-6-3.md for the official form and sources).
 *
 * issue() gives a sale (an issued invoice or any POS / online order) its Mushak-6.3 once: the next
 * serial of the outlet's fiscal year (July–June; DocSequence series "mushak", row-locked, gap-free)
 * and a frozen copy of every printed field, so reprints never change. Values come from the sale as
 * calculated by OrderCalculator: VAT rates are the products' own VAT rules — nothing is invented.
 * Supplementary duty: products carry no SD rate in OmniPOS, so the SD column is 0.
 */
final class MushakService
{
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

    /** @return array<string, mixed>|null the Mushak-6.3 of a sale (data decoded) */
    public function forOrder(int $orderId): ?array
    {
        $r = db_connect()->table('mushak_invoices')->where('business_id', $this->bid())->where('order_id', $orderId)->get()->getRowArray();

        return $r === null ? null : $this->decode($r);
    }

    public function find(int $id): array
    {
        $r = db_connect()->table('mushak_invoices')->where('business_id', $this->bid())->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Mushak-6.3 not found.');

        return $this->decode($r);
    }

    private function decode(array $r): array
    {
        $r['data'] = json_decode((string) $r['data'], true) ?: [];

        return $r;
    }

    /**
     * Issue the Mushak-6.3 of a sale (idempotent: a sale that already has one gets it back).
     *
     * @param array{buyer_name?: ?string, buyer_bin?: ?string, buyer_address?: ?string, destination?: ?string, vehicle_type?: ?string, vehicle_no?: ?string} $in
     * @return array<string, mixed> the mushak_invoices row (data decoded)
     */
    public function issue(int $orderId, array $in = []): array
    {
        if (! service('posSession')->can('mushak.issue')) {
            throw new PermissionDeniedException('Your role cannot issue Mushak-6.3 VAT invoices.');
        }
        $set = MushakSettings::forCurrent();
        if (! $set->enabled()) {
            throw new BusinessRuleException('Mushak-6.3 is not set up for this outlet — enter the registered name, BIN and address under Invoices › Mushak-6.3 settings.');
        }
        $cfg = $set->get();
        $txt = static fn (string $k, int $max): ?string => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $buyerBin = $txt('buyer_bin', 20);
        if ($buyerBin !== null) {
            $buyerBin = MushakSettings::normalizeBin($buyerBin) ?? throw new ValidationException('The buyer\'s BIN has 9 digits (13 with the branch code).', ['buyer_bin' => 'Invalid BIN']);
        }
        $s = service('posSession');

        $row = Tx::run(function (BaseConnection $db) use ($orderId, $cfg, $txt, $buyerBin, $s): array {
            $o = $db->query('SELECT * FROM orders WHERE id = ? AND business_id = ? FOR UPDATE', [$orderId, $this->bid()])->getRowArray() ?? throw new NotFoundException('Sale not found.');
            $have = $db->table('mushak_invoices')->where('business_id', $this->bid())->where('order_id', $orderId)->get()->getRowArray();
            if ($have !== null) {
                return $have + ['_new' => false];
            }
            if (! in_array($o['status'], ['paid', 'partial', 'due'], true)) {
                throw new BusinessRuleException('A Mushak-6.3 is issued for a completed sale only (this one is ' . ($o['status'] === 'cancelled' ? 'void' : $o['status']) . ').');
            }
            $order = service('orders')->find($orderId);
            $inv = $db->table('invoices')->select('id, revision, bill_name, bill_address, bill_bin, ship_to')->where('business_id', $this->bid())->where('order_id', $orderId)->get()->getRowArray();
            $cust = $o['customer_id'] ? $db->table('customers')->select('name, address, is_walk_in')->where('id', $o['customer_id'])->where('business_id', $this->bid())->get()->getRowArray() : null;
            $now = date('Y-m-d H:i:s');
            $fy = FiscalYear::label($now);
            $serial = DocSequence::next($this->bid(), 'mushak', $fy);
            $docNo = $fy . '/' . str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
            $brands = $this->brands($order['items']);
            $calc = self::compute($order, (bool) ($this->business['tax_inclusive'] ?? true), $brands);
            $buyerName = $txt('buyer_name', 160) ?? ($inv['bill_name'] ?? null) ?? $o['customer_name'];
            $data = [
                'form' => 'মূসক-৬.৩', 'rule' => 'বিধি ৪০ এর উপ-বিধি (১) এর দফা (গ) ও দফা (চ)',
                'supplier' => ['name' => $cfg['reg_name'], 'bin' => $cfg['bin'], 'address' => $cfg['address']],
                'buyer' => ['name' => $buyerName, 'bin' => $buyerBin ?? ($inv['bill_bin'] ?? null), 'address' => $txt('buyer_address', 255) ?? ($inv['bill_address'] ?? null) ?? ($cust['address'] ?? null)],
                'destination' => $txt('destination', 255) ?? ($inv['ship_to'] ?? null) ?? ($o['ship_address'] ?? null),
                'vehicle' => ['type' => $txt('vehicle_type', 60), 'no' => $txt('vehicle_no', 40)],
                'doc_no' => $docNo, 'fiscal_year' => $fy, 'serial' => $serial, 'issued_at' => $now, 'sale_doc_no' => $o['doc_no'], 'sale_at' => $o['created_at'],
                'lines' => $calc['lines'], 'totals' => $calc['totals'],
                'discount' => (float) $o['discount_total'], 'net_payable' => (float) $o['total'], 'shipping' => (float) $o['shipping'], 'round_off' => (float) $o['round_off'],
                'payments' => array_values(array_unique(array_map(static fn ($p) => (string) $p['method'], $order['payments']))), 'paid' => (float) $o['paid_total'], 'due' => (float) $o['due_total'],
                'responsible' => ['name' => $cfg['responsible_name'], 'title' => $cfg['responsible_title']],
                'currency' => (string) ($this->business['currency_code'] ?? 'BDT'),
            ];
            $row = [
                'tenant_id' => $s->tenantId(), 'business_id' => $this->bid(), 'order_id' => $orderId, 'fiscal_year' => $fy, 'serial' => $serial, 'doc_no' => $docNo,
                'issued_at' => $now, 'buyer_name' => mb_substr((string) $buyerName, 0, 160), 'buyer_bin' => $data['buyer']['bin'],
                'value_total' => $calc['totals']['value'], 'sd_total' => $calc['totals']['sd'], 'vat_total' => $calc['totals']['vat'], 'grand_total' => $calc['totals']['total'],
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'user_id' => $s->userId() ?: null, 'user_name' => mb_substr((string) ($s->user()['name'] ?? ''), 0, 100) ?: null, 'created_at' => $now,
            ];
            $db->table('mushak_invoices')->insert($row);
            $row['id'] = (int) $db->insertID();
            if ($inv !== null) {
                InvoiceService::forCurrent()->log((int) $inv['id'], (int) $inv['revision'], 'mushak', 'Mushak-6.3 issued · ' . $docNo, null, ['mushak' => $docNo, 'vat' => $calc['totals']['vat'], 'total' => $calc['totals']['total']]);
            }

            return $row + ['_new' => true];
        });
        $new = (bool) $row['_new'];
        unset($row['_new']);
        if ($new) {
            service('audit')->activity('Mushak-6.3 issued', $row['doc_no'] . ' · ' . number_format((float) $row['grand_total'], 2));
        }

        return $this->decode($row);
    }

    /** @return array<int, string> product id => brand */
    private function brands(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['product_id'] ?? 0), $items))));
        if ($ids === []) {
            return [];
        }

        return array_filter(array_column(db_connect()->table('products')->select('id, brand')->where('business_id', $this->bid())->whereIn('id', $ids)->get()->getResultArray(), 'brand', 'id'));
    }

    /**
     * The Mushak-6.3 table of a sale (pure; unit-tested). Per line: value without any tax (after the
     * line and invoice discounts), SD (0), VAT rate and amount as calculated on the sale, value with
     * all taxes. Service charge / order-type charges are one more line at the outlet's default VAT;
     * delivery charge and rounding are not taxed and stay outside the table (net payable below it).
     *
     * @param array<string, mixed> $order  OrderService::find()
     * @param array<int, string>   $brands product id => brand name
     * @return array{lines: list<array<string, mixed>>, totals: array{value: float, sd: float, vat: float, total: float}}
     */
    public static function compute(array $order, bool $defaultInclusive, array $brands = []): array
    {
        $sub = (float) $order['subtotal'];
        $disc = (float) $order['discount_total'];
        $svc = (float) $order['service_charge'] + (float) $order['packing'];
        // time / express charges (subtotal + extra = base the discount was taken from)
        $extra = round((float) $order['total'] - $sub + $disc - $svc - (float) $order['tax_added'] - (float) $order['shipping'] - (float) $order['round_off'], 2);
        $base = $sub + $extra;
        $k = $base > 0 ? ($base - $disc) / $base : 0.0;
        $lines = [];
        $lineVat = 0.0;
        foreach ($order['items'] as $it) {
            $qty = (float) $it['qty'];
            $gross = (float) $it['line_total'] * $k;
            $vat = (float) $it['tax_amount'];
            $mode = (string) $it['tax_mode'];
            $value = $mode === 'inclusive' ? $gross - $vat : $gross;
            $name = (string) $it['name'] . (! empty($it['variant_name']) ? ' — ' . $it['variant_name'] : '');
            $brand = $brands[(int) ($it['product_id'] ?? 0)] ?? '';
            if ($brand !== '' && mb_stripos($name, $brand) === false) {
                $name .= ' (' . $brand . ')';
            }
            $lines[] = [
                'description' => $name, 'unit' => $it['unit'] ?: null, 'qty' => $qty,
                'unit_price' => $qty > 0 ? round($value / $qty, 2) : 0.0, 'value' => round($value, 2), 'sd' => 0.0,
                'vat_rate' => $mode === 'exempt' ? null : (float) $it['tax_rate'], 'vat_mode' => $mode, 'vat' => round($vat, 2), 'total' => round($value + $vat, 2),
            ];
            $lineVat += $vat;
        }
        // charges carry the rest of the sale's VAT at the default rule
        $chargeGross = $svc + $extra * $k;
        $chargeVat = round((float) $order['tax_total'] - $lineVat, 2);
        if ($chargeGross > 0.004) {
            $value = $defaultInclusive ? $chargeGross - $chargeVat : $chargeGross;
            $lines[] = ['description' => 'Service / other charges', 'unit' => null, 'qty' => 1.0, 'unit_price' => round($value, 2), 'value' => round($value, 2), 'sd' => 0.0,
                'vat_rate' => $value > 0 && abs($chargeVat) > 0.004 ? round($chargeVat / $value * 100, 2) : 0.0, 'vat_mode' => $defaultInclusive ? 'inclusive' : 'exclusive', 'vat' => $chargeVat, 'total' => round($value + $chargeVat, 2)];
        } elseif (abs($chargeVat) >= 0.005 && $lines !== []) {
            // rounding of per-line VAT against the sale's VAT total: kept on the last line so the table adds up
            $last = count($lines) - 1;
            $lines[$last]['vat'] = round($lines[$last]['vat'] + $chargeVat, 2);
            if ($lines[$last]['vat_mode'] === 'inclusive') {
                $lines[$last]['value'] = round($lines[$last]['value'] - $chargeVat, 2);
                $lines[$last]['unit_price'] = $lines[$last]['qty'] > 0 ? round($lines[$last]['value'] / $lines[$last]['qty'], 2) : 0.0;
            } else {
                $lines[$last]['total'] = round($lines[$last]['total'] + $chargeVat, 2);
            }
        }
        // per-line rounding (value = gross − VAT) can leave a paisa: kept on the last line's value so
        // the table adds up to the sale (total − delivery − rounding)
        if ($lines !== []) {
            $want = round((float) $order['total'] - (float) $order['shipping'] - (float) $order['round_off'], 2);
            $diff = round($want - array_sum(array_column($lines, 'total')), 2);
            if ($diff != 0.0 && abs($diff) <= 0.05) {
                $last = count($lines) - 1;
                $lines[$last]['value'] = round($lines[$last]['value'] + $diff, 2);
                $lines[$last]['total'] = round($lines[$last]['total'] + $diff, 2);
                $lines[$last]['unit_price'] = $lines[$last]['qty'] > 0 ? round($lines[$last]['value'] / $lines[$last]['qty'], 2) : 0.0;
            }
        }
        $totals = ['value' => 0.0, 'sd' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        foreach ($lines as $l) {
            $totals['value'] += $l['value'];
            $totals['vat'] += $l['vat'];
            $totals['total'] += $l['total'];
        }

        return ['lines' => $lines, 'totals' => array_map(static fn ($v) => round($v, 2), $totals)];
    }
}
