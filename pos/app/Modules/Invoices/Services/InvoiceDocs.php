<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Modules\Orders\Services\OrderDocs;
use OmniPOS\Core\Pricing\CartLine;

/**
 * Print data of an invoice: the Orders document data (OrderDocs — same lines, VAT rows and totals
 * as every other sale) plus the invoice page (header / footer images, title, terms,
 * notes, billing details). A draft is printed from its quote with a DRAFT mark.
 */
final class InvoiceDocs
{
    /** @return array<string, mixed> */
    public static function build(array $inv, array $business): array
    {
        $order = $inv['order'] ?? self::draftOrder($inv, $business);
        $d = (new OrderDocs())->build($order, $business);
        $set = new InvoiceSettings($business);
        // this invoice's own title / footer line (edited on the invoice)
        $page = array_merge($set->get(), array_intersect_key((array) ($inv['data']['page'] ?? []), array_flip(['title', 'footer_text', 'sign_name', 'sign_title'])));
        $d['invoice'] = $inv;
        $d['page'] = $page;
        $d['draft'] = $inv['status'] === 'draft';
        $d['void'] = $inv['state'] === 'void';
        $d['images'] = ['header' => $set->layoutImage('header'), 'footer' => $set->layoutImage('footer')];
        $d['terms'] = (string) ($inv['terms'] ?? '') !== '' ? (string) $inv['terms'] : (string) $page['terms'];
        $d['notes'] = (string) ($inv['notes'] ?? '');
        $d['invoiceDate'] = (string) ($inv['data']['invoice_date'] ?? substr((string) ($inv['issued_at'] ?? $inv['created_at'] ?? date('Y-m-d')), 0, 10));
        $d['footer'] = (string) $page['footer_text'];
        $d['bank'] = (string) $page['bank'];
        if ($page['accent'] !== '') {
            $d['primary'] = $page['accent'];
        }
        $d['template'] = isset(InvoiceSettings::TEMPLATES[(string) ($page['template'] ?? '')]) ? (string) $page['template'] : 'modern';
        $d['issuedAt'] = $inv['issued_at'] ?? null;

        // NBR details for a VAT-registered outlet (Mushak settings: registered name, BIN, issuing
        // address — entered by the business; nothing is assumed): seller and buyer BIN, issue date
        // and time, and per line the value without VAT, SD, VAT rate / amount and value with VAT,
        // computed exactly as on the Mushak-6.3 (MushakService::compute).
        $mushak = new MushakSettings($business);
        $d['nbr'] = $mushak->enabled();
        $m = $mushak->get();
        $d['seller'] = [
            'name' => (string) $business['name'],
            'reg_name' => $d['nbr'] && trim((string) $m['reg_name']) !== '' && trim((string) $m['reg_name']) !== (string) $business['name'] ? (string) $m['reg_name'] : null,
            'address' => $d['nbr'] ? (string) $m['address'] : (string) ($business['address'] ?: ($business['settings']['branch'] ?? '')),
            'bin' => $d['nbr'] ? (string) $m['bin'] : ((string) ($business['settings']['bin'] ?? '') ?: null),
        ];
        $d['nbrRows'] = null;
        if ($d['nbr']) {
            $brands = [];
            $pids = array_values(array_unique(array_filter(array_map(static fn ($l) => (int) ($l['product_id'] ?? 0), $order['items']))));
            if ($pids) {
                $brands = array_filter(array_column(db_connect()->table('products')->select('id, brand')->where('business_id', (int) $business['id'])->whereIn('id', $pids)->get()->getResultArray(), 'brand', 'id'));
            }
            $d['nbrRows'] = MushakService::compute($order, (bool) ($business['tax_inclusive'] ?? true), $brands);
            $d['nbrSd'] = $d['nbrRows']['totals']['sd'] > 0;
        }
        $d['mushakDoc'] = $inv['mushak'] ?? null;
        if ($d['draft']) {
            $d['stamp'] = null; // the page carries a DRAFT watermark
            $d['statusText'] = 'Draft';
        } elseif ($d['void']) {
            $d['stamp'] = ['VOID', 'due'];
        }

        return $d;
    }

    /** An order-shaped array for a draft (nothing stored): priced now, like the sale would be. */
    private static function draftOrder(array $inv, array $business): array
    {
        $lines = (array) ($inv['data']['lines'] ?? []);
        $q = $lines !== [] ? service('orders')->quote(['channel' => 'invoice', 'lines' => InvoiceService::orderDraftLines($lines), 'discount' => $inv['data']['discount'] ?? null, 'trusted_prices' => true]) : null;
        $t = $q['totals'] ?? null;
        $items = [];
        foreach ($q['lines'] ?? [] as $i => $l) {
            $src = $lines[$i];
            $cl = new CartLine(qty: (float) $l['qty'], unitPrice: (float) $l['unit_price'], discountType: (string) ($src['discount_type'] ?? 'percent'), discountValue: (float) ($src['discount_value'] ?? 0));
            $lt = $t->lineTaxes[$i];
            $items[] = ['id' => 0, 'product_id' => $l['product_id'], 'variant_id' => $l['variant_id'], 'batch_id' => null, 'serial_id' => null, 'name' => $l['name'], 'variant_name' => $l['variant_name'],
                'sku' => $l['sku'], 'serial' => $l['serial'], 'unit' => $l['unit'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'], 'discount' => round($cl->unitDiscount(), 2),
                'line_total' => round($cl->amount(), 2), 'tax_mode' => $lt['mode'], 'tax_rate' => $lt['rate'], 'tax_amount' => $lt['tax'], 'returned_qty' => 0, 'note' => $l['note']];
        }

        return [
            'id' => 0, 'number' => 0, 'doc_no' => 'DRAFT-' . $inv['id'], 'channel' => 'invoice', 'order_type' => null, 'table_ref' => null, 'customer_id' => $inv['customer_id'],
            'customer_name' => $inv['bill_name'], 'customer_phone' => $inv['bill_phone'], 'status' => 'draft', 'kitchen_status' => 'none', 'item_count' => $t->itemCount ?? 0,
            'subtotal' => $t->subtotal ?? 0, 'discount_type' => 'percent', 'discount_value' => 0, 'discount_total' => $t->discount ?? 0, 'service_charge' => $t->serviceCharge ?? 0,
            'packing' => $t->packing ?? 0, 'tax_total' => $t->tax ?? 0, 'tax_included' => $t->taxIncluded ?? 0, 'tax_added' => $t->taxAdded ?? 0, 'shipping' => 0, 'round_off' => $t->roundOff ?? 0,
            'total' => $t->total ?? 0, 'paid_total' => 0, 'due_total' => $t->total ?? 0, 'returned_total' => 0, 'user_name' => null, 'staff_name' => null, 'web_ref' => null,
            'ship_address' => null, 'ship_status' => null, 'due_date' => $inv['due_date'], 'note' => null, 'created_at' => $inv['updated_at'] ?? $inv['created_at'],
            'items' => $items, 'taxes' => array_map(static fn ($x) => ['tax_mode' => $x['mode'], 'rate' => $x['rate'], 'taxable' => $x['net'], 'tax' => $x['tax']], $t->taxLines ?? []),
            'payments' => [], 'returns' => [],
        ];
    }
}
