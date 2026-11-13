<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Services;

use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Kitchen / production board (KDS). A ticket is an order with kitchen_status new / cooking /
 * ready (served = done). Each line is ticked off on its own (order_items.kds_done); the ticket
 * moves to "ready" by itself when every line is done and stays on the board until then.
 */
final class KitchenService
{
    public const FILTERS = ['active', 'new', 'cooking', 'delayed', 'ready', 'served'];

    public function __construct(private readonly int $businessId)
    {
    }

    /**
     * Tickets of the last 24 hours with their lines.
     *
     * @return list<array<string, mixed>>
     */
    public function tickets(): array
    {
        $db = db_connect();
        $orders = $db->table('orders')
            ->select('id, number, doc_no, order_type, table_ref, customer_name, staff_name, note, kitchen_status, status, item_count, due_date, created_at, updated_at')
            ->where('business_id', $this->businessId)->whereIn('kitchen_status', ['new', 'cooking', 'ready', 'served'])
            ->where('created_at >=', date('Y-m-d H:i:s', time() - 86400))->whereNotIn('status', ['cancelled', 'held'])
            ->orderBy('created_at')->limit(200)->get()->getResultArray();
        if ($orders === []) {
            return [];
        }
        $items = [];
        foreach ($db->table('order_items')->select('id, order_id, name, variant_name, qty, unit, note, kds_done')
            ->where('business_id', $this->businessId)->whereIn('order_id', array_column($orders, 'id'))->orderBy('id')->get()->getResultArray() as $it) {
            $items[(int) $it['order_id']][] = $it;
        }
        $now = time();
        foreach ($orders as &$o) {
            $o['items'] = $items[(int) $o['id']] ?? [];
            $o['done'] = count(array_filter($o['items'], static fn ($i) => (int) $i['kds_done'] === 1));
            $o['target'] = 10 + count($o['items']) * 3;
            $o['token'] = (int) $o['number'] % 99 + 1;
            $late = in_array($o['kitchen_status'], ['new', 'cooking'], true) && $now > strtotime($o['created_at']) + $o['target'] * 60;
            $o['state'] = $late ? 'delayed' : $o['kitchen_status'];
        }

        return $orders;
    }

    /** @return array<string, int> */
    public function counts(array $tickets): array
    {
        $c = ['active' => 0, 'new' => 0, 'cooking' => 0, 'delayed' => 0, 'ready' => 0, 'served' => 0];
        foreach ($tickets as $t) {
            $c[$t['state']]++;
            if ($t['kitchen_status'] !== 'served') {
                $c['active']++;
            }
        }

        return $c;
    }

    /**
     * Remaining (unticked) quantities across all active tickets, biggest first.
     *
     * @return list<array{key: string, name: string, variant: string, unit: string, qty: float, orders: int, notes: int}>
     */
    public function backlog(array $tickets): array
    {
        $map = [];
        foreach ($tickets as $t) {
            if ($t['kitchen_status'] === 'served') {
                continue;
            }
            foreach ($t['items'] as $i) {
                if ((int) $i['kds_done'] === 1) {
                    continue;
                }
                $k = $i['name'] . '|' . ($i['variant_name'] ?? '');
                $map[$k] ??= ['key' => $k, 'name' => (string) $i['name'], 'variant' => (string) ($i['variant_name'] ?? ''), 'unit' => (string) ($i['unit'] ?? ''), 'qty' => 0.0, 'orders' => [], 'notes' => 0];
                $map[$k]['qty'] = round($map[$k]['qty'] + (float) $i['qty'], 3);
                $map[$k]['orders'][(int) $t['id']] = true;
                $map[$k]['notes'] += $i['note'] ? 1 : 0;
            }
        }
        $out = array_map(static fn ($x) => array_merge($x, ['orders' => count($x['orders'])]), array_values($map));
        usort($out, static fn ($a, $b) => [$b['qty'], $a['name']] <=> [$a['qty'], $b['name']]);

        return $out;
    }

    /**
     * Tick / untick one line. Returns the ticket's new status.
     *
     * @return array{order_id: int, status: string, done: int, total: int}
     */
    public function toggle(int $itemId, bool $done): array
    {
        $bid = $this->businessId;
        $r = Tx::run(static function ($db) use ($itemId, $done, $bid): array {
            $it = $db->table('order_items')->select('id, order_id')->where('id', $itemId)->where('business_id', $bid)->get()->getRowArray()
                ?? throw new NotFoundException('Ticket line not found.');
            $o = $db->query('SELECT id, doc_no, kitchen_status FROM orders WHERE id = ? AND business_id = ? FOR UPDATE', [$it['order_id'], $bid])->getRowArray()
                ?? throw new NotFoundException('Ticket not found.');
            if (in_array($o['kitchen_status'], ['none', 'served'], true)) {
                throw new BusinessRuleException('This ticket is already done.');
            }
            $db->table('order_items')->where('id', $itemId)->where('business_id', $bid)->update(['kds_done' => $done ? 1 : 0]);
            $c = $db->table('order_items')->select('COUNT(*) AS n, SUM(kds_done) AS d')->where('order_id', $o['id'])->get()->getRowArray();
            $n = (int) $c['n'];
            $d = (int) $c['d'];
            $status = $o['kitchen_status'];
            if ($n > 0 && $d === $n) {
                $status = 'ready';
            } elseif ($status === 'ready') {
                $status = 'cooking';
            } elseif ($status === 'new' && $d > 0) {
                $status = 'cooking';
            }
            if ($status !== $o['kitchen_status']) {
                $db->table('orders')->where('id', $o['id'])->where('business_id', $bid)->update(['kitchen_status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
            }

            return ['order_id' => (int) $o['id'], 'status' => $status, 'done' => $d, 'total' => $n, 'doc_no' => $o['doc_no']];
        });
        if ($r['status'] === 'ready') {
            service('audit')->activity('Kitchen ready', $r['doc_no']);
        }
        unset($r['doc_no']);

        return $r;
    }

    /** Start / pause / mark ready (ticks every line) / recall / served. */
    public function setStatus(int $orderId, string $status): string
    {
        if (! in_array($status, ['new', 'cooking', 'ready', 'served'], true)) {
            throw new ValidationException('Unknown ticket status.', ['status' => 'Invalid']);
        }
        $bid = $this->businessId;
        Tx::run(static function ($db) use ($orderId, $status, $bid): void {
            $o = $db->query('SELECT id, kitchen_status FROM orders WHERE id = ? AND business_id = ? FOR UPDATE', [$orderId, $bid])->getRowArray()
                ?? throw new NotFoundException('Ticket not found.');
            if ($o['kitchen_status'] === 'none') {
                throw new BusinessRuleException('This order is not on the kitchen board.');
            }
            if ($status === 'ready' || $status === 'served') {
                $db->table('order_items')->where('order_id', $orderId)->where('business_id', $bid)->update(['kds_done' => 1]);
            }
            $db->table('orders')->where('id', $orderId)->where('business_id', $bid)->update(['kitchen_status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        });

        return $status;
    }
}
