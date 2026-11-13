<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/**
 * Builds the data for printed documents of an order — POS receipt (58 / 80 mm), A4 invoice,
 * delivery challan and kitchen ticket — in the same layout as the design build's receiptHTML /
 * a4HTML (VAT rows per rate "incl." / "added", VAT column, "Taxable value | VAT" table).
 */
final class OrderDocs
{
    /**
     * @param array<string, mixed> $order    OrderService::find() result
     * @param array<string, mixed> $business businesses row with settings decoded
     * @return array<string, mixed>
     */
    public function build(array $order, array $business): array
    {
        $db = db_connect();
        $bid = (int) $business['id'];
        $items = $order['items'];
        $pids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['product_id'] ?? 0), $items))));
        $batchIds = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['batch_id'] ?? 0), $items))));
        $products = $pids ? array_column($db->table('products')->select('id, warranty_months, duration_min, stock_type')->where('business_id', $bid)->whereIn('id', $pids)->get()->getResultArray(), null, 'id') : [];
        $parts = [];
        if ($pids) {
            foreach ($db->table('product_warranties')->select('product_id, part, months')->whereIn('product_id', $pids)->get()->getResultArray() as $w) {
                $parts[(int) $w['product_id']][] = $w;
            }
        }
        $batches = $batchIds ? array_column($db->table('product_batches')->select('id, batch_no, expires_on')->where('business_id', $bid)->whereIn('id', $batchIds)->get()->getResultArray(), null, 'id') : [];
        $features = (array) ($business['settings']['features'] ?? []);
        $warrantyOn = ! empty($features['warranty']) || ! empty($features['serials']);

        $lines = [];
        $service = $items !== [];
        foreach ($items as $l) {
            $p = $products[(int) ($l['product_id'] ?? 0)] ?? null;
            $det = [];
            if (! empty($l['variant_name'])) {
                $det[] = esc($l['variant_name']);
            }
            if (! empty($l['serial'])) {
                $det[] = 'IMEI/SN: <b>' . esc($l['serial']) . '</b>';
            }
            $bt = $batches[(int) ($l['batch_id'] ?? 0)] ?? null;
            if ($bt !== null) {
                $det[] = 'Batch ' . esc($bt['batch_no']) . ($bt['expires_on'] ? ' · Exp ' . esc(date('M Y', strtotime($bt['expires_on']))) : '');
            }
            $ws = [];
            if ($warrantyOn && $p !== null) {
                if ((int) $p['warranty_months'] > 0) {
                    $ws[] = ['Full product', (int) $p['warranty_months']];
                }
                foreach ($parts[(int) $p['id']] ?? [] as $w) {
                    $ws[] = [(string) $w['part'], (int) $w['months']];
                }
            }
            if ($ws !== []) {
                $det[] = 'Warranty: ' . implode(' · ', array_map(fn ($w) => esc($w[0]) . ' ' . $this->wLabel($w[1]) . ($w[1] >= 9999 ? '' : ' (till ' . date('d M Y', strtotime($order['created_at'] . ' +' . $w[1] . ' months')) . ')'), $ws));
            }
            if ((float) $l['returned_qty'] > 0) {
                $det[] = 'Returned ' . qty($l['returned_qty'], $l['unit'] ?: null);
            }
            if (! empty($l['note'])) {
                $det[] = '* ' . esc($l['note']);
            }
            $unit = (float) $l['unit_price'] - (float) $l['discount'];
            $lines[] = $l + [
                'details' => $det, 'net_unit' => $unit, 'gross_unit' => (float) $l['unit_price'],
                'disc_total' => (float) $l['discount'] * (float) $l['qty'],
                'vat_cell' => $l['tax_mode'] === 'exempt' ? 'Exempt' : rate_str($l['tax_rate']) . '%' . ($l['tax_mode'] === 'inclusive' ? ' incl.' : ''),
            ];
            $service = $service && $p !== null && ((int) ($p['duration_min'] ?? 0) > 0 || ($p['stock_type'] === 'none' && in_array($business['business_type'], ['salon', 'agency', 'laundry', 'printing', 'karaoke'], true)));
        }

        $taxLabel = (string) ($business['tax_label'] ?? 'VAT');
        $taxRows = [];
        $vatTable = [];
        foreach ($order['taxes'] as $t) {
            $vatTable[] = ['label' => $t['tax_mode'] === 'exempt' ? 'Exempt' : rate_str($t['rate']) . '% ' . ($t['tax_mode'] === 'inclusive' ? 'included' : 'added'), 'net' => (float) $t['taxable'], 'tax' => (float) $t['tax']];
            if ((float) $t['tax'] > 0) {
                $taxRows[] = [$taxLabel . ' ' . rate_str($t['rate']) . '%' . ($t['tax_mode'] === 'inclusive' ? ' (incl.)' : ' (added)'), (float) $t['tax']];
            }
        }
        $vatOn = (float) $order['tax_total'] > 0 || array_filter($order['taxes'], static fn ($t) => $t['tax_mode'] === 'exempt') !== [];

        $returned = (float) $order['returned_total'];
        $status = (string) $order['status'];
        $stamp = match (true) {
            $status === 'returned' => ['REFUNDED', 'due'],
            $status === 'paid' => ['PAID', 'paid'],
            $status === 'partial' => ['PARTIALLY PAID', 'partial'],
            in_array($status, ['due', 'open'], true) => ['DUE', 'due'],
            default => null,
        };
        $cust = $order['customer_id'] ? $db->table('customers')->select('name, phone, address, is_walk_in')->where('id', $order['customer_id'])->where('business_id', $bid)->get()->getRowArray() : null;
        $inv = (array) ($business['settings']['invoice'] ?? []);
        helper('pos');
        $inv_set = new \App\Modules\Invoices\Services\InvoiceSettings($business);
        $page = $inv_set->get();

        return [
            'order' => $order, 'business' => $business, 'lines' => $lines, 'service' => $service && $lines !== [],
            'taxRows' => $taxRows, 'vatTable' => $vatOn ? $vatTable : [], 'vatOn' => $vatOn, 'taxLabel' => $taxLabel,
            'returned' => $returned, 'stamp' => $stamp, 'customer' => $cust,
            'phone' => $order['customer_phone'] ?: ($cust['phone'] ?? null),
            'footer' => (string) ($inv['footer'] ?? 'Thank you for your business!'),
            'bank' => (string) ($inv['bank'] ?? ''),
            'statusText' => ['paid' => 'Paid', 'partial' => 'Partially paid', 'due' => 'Due', 'open' => 'Open', 'returned' => 'Refunded', 'cancelled' => 'Void', 'held' => 'On hold'][$status] ?? ucfirst($status),
            'cur' => ['symbol' => $business['currency_symbol'] ?? '৳', 'decimals' => (int) ($business['currency_decimals'] ?? 2)],
            'code' => (string) ($business['currency_code'] ?? 'BDT'),
            'logo' => $business['settings']['logoIcon'] ?? $business['type_icon'] ?? null,
            'logoUrl' => biz_logo_url($business) ?: null,
            'showLogo' => (bool) ($page['show_logo'] ?? true),
            'sign' => [
                'show' => (bool) ($page['sign_show'] ?? true), 'customer' => (bool) ($page['sign_customer'] ?? true), 'name' => (string) ($page['sign_name'] ?? ''), 'title' => (string) ($page['sign_title'] ?? ''),
                'image' => $inv_set->imageUrl('signature'),
            ],
            'invoiceNo' => $order['id'] ? ($db->table('invoices')->select('doc_no')->where('order_id', $order['id'])->where('business_id', $bid)->get()->getRow('doc_no') ?: null) : null,
            'primary' => (string) ($business['settings']['theme']['primary'] ?? '#4F46E5'),
        ];
    }

    /** Default document type for a sale: receipt (thermal) or a page (A4 / A5) — see PrintSettings. */
    public static function defaultSize(array $business): string
    {
        return PrintSettings::isPage(PrintSettings::for($business, 'receipt')['size']) ? 'a4' : '80';
    }

    private function wLabel(int $m): string
    {
        if ($m >= 9999) {
            return 'Lifetime';
        }

        return $m >= 12 && $m % 12 === 0 ? ($m / 12) . ' year' . ($m === 12 ? '' : 's') : $m . ' month' . ($m === 1 ? '' : 's');
    }
}
