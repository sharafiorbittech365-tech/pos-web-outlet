<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Http\ApiEnvelope;
use OmniPOS\Core\Models\Generated\BookingsModel;
use OmniPOS\Core\Models\Generated\OrderItemsModel;
use OmniPOS\Core\Models\Generated\OrdersModel;
use OmniPOS\Core\Services\OrderService;

/** Order reads: customer tracking by reference, and the integrator list / detail (orders:read). */
final class OrderQueries
{
    public const STATUSES = ['open', 'held', 'paid', 'partial', 'due', 'cancelled', 'returned'];
    private const LIST_COLUMNS = 'o.id, o.number, o.doc_no, o.web_ref, o.channel, o.order_type, o.status, o.ship_status, o.customer_id, o.customer_name, o.customer_phone,'
        . ' o.item_count, o.subtotal, o.discount_total, o.service_charge, o.packing, o.tax_total, o.shipping, o.round_off, o.total, o.paid_total, o.due_total, o.returned_total,'
        . ' o.ship_address, o.note, o.created_at, o.paid_at';

    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * Tracking for the customer who placed the order (or booking) with this reference.
     *
     * @return array<string, mixed>
     */
    public function track(string $reference): array
    {
        if (! preg_match('/^[A-Za-z0-9]{4,24}$/', $reference)) {
            throw new NotFoundException('No order or booking with that reference.');
        }
        $o = model(OrdersModel::class)->scoped('o')
            ->select('o.id, o.web_ref, o.status, o.ship_status, o.total, o.created_at')
            ->where('o.web_ref', strtoupper($reference))->get()->getRowArray();
        if ($o !== null) {
            $items = model(OrderItemsModel::class)->scoped('i')->select('i.name, i.variant_name, i.qty, i.line_total')
                ->where('i.order_id', $o['id'])->orderBy('i.id')->get()->getResultArray();

            return [
                'type' => 'order',
                'reference' => (string) $o['web_ref'],
                'status' => (string) $o['status'],
                'ship_status' => $o['ship_status'],
                'total' => (float) $o['total'],
                'items' => array_map(static fn (array $i): array => [
                    'name' => $i['name'] . ($i['variant_name'] ? ' (' . $i['variant_name'] . ')' : ''),
                    'qty' => (float) $i['qty'],
                    'line_total' => (float) $i['line_total'],
                ], $items),
                'created_at' => self::iso($o['created_at']),
            ];
        }
        $b = model(BookingsModel::class)->scoped('b')
            ->select('b.reference, b.kind, b.status, b.staff_name, b.starts_at, b.ends_at, b.created_at')
            ->where('b.reference', strtoupper($reference))->get()->getRowArray();
        if ($b !== null) {
            return [
                'type' => 'booking',
                'reference' => (string) $b['reference'],
                'kind' => (string) $b['kind'],
                'status' => (string) $b['status'],
                'staff' => $b['staff_name'],
                'starts_at' => self::iso($b['starts_at']),
                'ends_at' => self::iso($b['ends_at']),
                'created_at' => self::iso($b['created_at']),
            ];
        }

        throw new NotFoundException('No order or booking with that reference.');
    }

    /**
     * @param array<string, mixed> $f status, from, to, channel
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(array $f, int $page, int $perPage): array
    {
        $errors = [];
        $b = model(OrdersModel::class)->scoped('o');
        $status = (string) ($f['status'] ?? '');
        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'Use one of: ' . implode(', ', self::STATUSES) . '.';
        } elseif ($status !== '') {
            $b->where('o.status', $status);
        }
        $channel = (string) ($f['channel'] ?? '');
        if ($channel !== '' && ! in_array($channel, ['pos', 'web', 'marketplace', 'api'], true)) {
            $errors['channel'] = 'Use pos, web, marketplace or api.';
        } elseif ($channel !== '') {
            $b->where('o.channel', $channel);
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $v = (string) ($f[$key] ?? '');
            if ($v === '') {
                continue;
            }
            $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? strtotime($v) : false;
            if ($t === false) {
                $errors[$key] = 'Use a date as YYYY-MM-DD.';
                continue;
            }
            // "to" is inclusive: everything before the next day
            $b->where('o.created_at ' . $op, date('Y-m-d 00:00:00', $key === 'to' ? strtotime('+1 day', $t) : $t));
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid filters.', $errors);
        }
        $total = (int) $b->countAllResults(false);
        $rows = $b->select(self::LIST_COLUMNS)->orderBy('o.created_at', 'DESC')->orderBy('o.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['data' => array_map(self::present(...), $rows), 'meta' => ApiEnvelope::pageMeta($total, $page, $perPage)];
    }

    /** @return array<string, mixed> */
    public function detail(int $id): array
    {
        $o = $this->orders->find($id);
        $out = self::present($o);
        $out['items'] = array_map(static fn (array $i): array => [
            'id' => (int) $i['id'], 'product_id' => $i['product_id'] !== null ? (int) $i['product_id'] : null, 'variant_id' => $i['variant_id'] !== null ? (int) $i['variant_id'] : null,
            'name' => (string) $i['name'], 'variant_name' => $i['variant_name'], 'sku' => $i['sku'], 'serial' => $i['serial'], 'unit' => $i['unit'],
            'qty' => (float) $i['qty'], 'unit_price' => (float) $i['unit_price'], 'discount' => (float) $i['discount'], 'line_total' => (float) $i['line_total'],
            'tax' => ['mode' => $i['tax_mode'], 'rate' => (float) $i['tax_rate'], 'amount' => (float) $i['tax_amount']], 'returned_qty' => (float) $i['returned_qty'],
        ], $o['items']);
        $out['taxes'] = array_map(static fn (array $t): array => ['mode' => $t['tax_mode'], 'rate' => (float) $t['rate'], 'taxable' => (float) $t['taxable'], 'tax' => (float) $t['tax']], $o['taxes']);
        $out['payments'] = array_map(static fn (array $p): array => ['method' => $p['method'], 'amount' => (float) $p['amount'], 'reference' => $p['reference'], 'created_at' => self::iso($p['created_at'])], $o['payments']);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function present(array $o): array
    {
        return [
            'id' => (int) $o['id'],
            'number' => (int) $o['number'],
            'doc_no' => (string) $o['doc_no'],
            'reference' => $o['web_ref'],
            'channel' => (string) $o['channel'],
            'order_type' => $o['order_type'],
            'status' => (string) $o['status'],
            'ship_status' => $o['ship_status'],
            'customer' => ['id' => $o['customer_id'] !== null ? (int) $o['customer_id'] : null, 'name' => (string) $o['customer_name'], 'phone' => $o['customer_phone']],
            'ship_address' => $o['ship_address'],
            'item_count' => (float) $o['item_count'],
            'subtotal' => (float) $o['subtotal'],
            'discount' => (float) $o['discount_total'],
            'service_charge' => (float) $o['service_charge'],
            'packing' => (float) $o['packing'],
            'tax' => (float) $o['tax_total'],
            'shipping' => (float) $o['shipping'],
            'round_off' => (float) $o['round_off'],
            'total' => (float) $o['total'],
            'paid' => (float) $o['paid_total'],
            'due' => (float) $o['due_total'],
            'returned' => (float) $o['returned_total'],
            'note' => $o['note'],
            'created_at' => self::iso($o['created_at']),
            'paid_at' => self::iso($o['paid_at']),
        ];
    }

    /** "2026-10-19 14:05:00" (Asia/Dhaka) -> ISO-8601 with offset. */
    public static function iso(?string $dt): ?string
    {
        return $dt === null || $dt === '' ? null : date('c', (int) strtotime($dt));
    }
}
