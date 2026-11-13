<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Register\Services\RegisterService;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Changes to existing orders from the back office and the till: take a payment (core
 * addPayments), sell return (core returnItems) and the delivery status of web / marketplace orders.
 */
final class OrderActions
{
    public const SHIP = ['ordered', 'packed', 'out_for_delivery', 'delivered', 'cancelled'];

    private array $business;

    public function __construct()
    {
        helper(['ui', 'pos']);
        $this->business = service('posSession')->business();
    }

    private function order(int $id): array
    {
        return db_connect()->table('orders')->where('id', $id)->where('business_id', (int) $this->business['id'])->get()->getRowArray()
            ?? throw new NotFoundException('Order not found.');
    }

    /**
     * Take payment for an order. Cash over the due is change (never recorded); an open tab that is
     * not paid in full goes on the customer's due book (needs pos.credit and a named customer).
     *
     * @param list<array{method: string, amount: float|string, reference?: string}> $payments
     * @return array{order: array<string, mixed>, change: float, tendered: float}
     */
    public function pay(int $orderId, array $payments): array
    {
        $o = $this->order($orderId);
        $due = (float) $o['due_total'];
        if ($due <= 0.009) {
            throw new BusinessRuleException('Nothing is due on this order.');
        }
        $allowed = array_values(array_unique(array_merge(['cash'], array_filter((array) ($this->business['settings']['payments'] ?? ['cash']), static fn ($m) => $m !== 'credit'))));
        if ($o['channel'] !== 'pos') {
            $allowed[] = 'cod';
        }
        $rows = [];
        $sum = 0.0;
        $cash = 0.0;
        foreach (array_slice($payments, 0, 10) as $p) {
            $m = preg_replace('/[^a-z_]/', '', strtolower((string) ($p['method'] ?? '')));
            $amt = round((float) ($p['amount'] ?? 0), 2);
            if ($amt <= 0) {
                continue;
            }
            if (! in_array($m, $allowed, true)) {
                throw new ValidationException(pay_label($m) . ' is not accepted here.', ['payments' => 'Method not offered']);
            }
            $rows[] = ['method' => $m, 'amount' => $amt, 'reference' => mb_substr(trim((string) ($p['reference'] ?? '')), 0, 64) ?: null];
            $sum += $amt;
            $cash += in_array($m, ['cash', 'cod'], true) ? $amt : 0;
        }
        if ($rows === []) {
            throw new ValidationException('Enter the amount received.', ['payments' => 'Required']);
        }
        $change = round(max(0, $sum - $due), 2);
        if ($change > 0 && $cash + 0.009 < $change) {
            throw new BusinessRuleException('Change can only be returned from a cash payment.');
        }
        // change comes out of the last cash rows
        $over = $change;
        foreach (array_reverse(array_keys($rows)) as $i) {
            if ($over <= 0) {
                break;
            }
            if (in_array($rows[$i]['method'], ['cash', 'cod'], true)) {
                $cut = min($over, $rows[$i]['amount']);
                $rows[$i]['amount'] = round($rows[$i]['amount'] - $cut, 2);
                $over -= $cut;
            }
        }
        $rows = array_values(array_filter($rows, static fn ($r) => $r['amount'] > 0));
        $left = round($due - min($sum, $due), 2);
        $s = service('posSession');
        $reg = RegisterService::forCurrent()->currentId();
        if ($left > 0.009 && $o['status'] === 'open') {
            $walk = $o['customer_id'] === null || (bool) db_connect()->table('customers')->select('is_walk_in')->where('id', $o['customer_id'])->get()->getRow('is_walk_in');
            if (! $s->can('pos.credit') || $walk) {
                throw new BusinessRuleException('Take the full amount — leaving a due needs a named customer and the credit permission.');
            }
            $out = Tx::run(static function () use ($orderId, $rows, $reg, $o, $left): array {
                $r = service('orders')->addPayments($orderId, $rows, $reg);
                service('ledger')->charge((int) $o['customer_id'], $left, 'order', $orderId, 'Invoice ' . $o['doc_no']);

                return $r;
            });
        } else {
            $out = service('orders')->addPayments($orderId, $rows, $reg);
        }
        service('usage')->touch((int) $o['tenant_id']);

        return ['order' => service('orders')->find($orderId), 'change' => $change, 'tendered' => round($cash, 2), 'status' => $out['status']];
    }

    /**
     * Sell return: refund (or reduce the due of a credit sale) and put stock back.
     *
     * @param array<int|string, float|string> $qty order_item id => quantity to return
     * @return array{amount: float, order: array<string, mixed>, method: string}
     */
    public function returnItems(int $orderId, array $qty, string $reason = '', string $as = 'refund'): array
    {
        $o = $this->order($orderId);
        if (! in_array($o['status'], ['paid', 'partial', 'due', 'open'], true)) {
            throw new BusinessRuleException('Only completed sales can be returned.');
        }
        $items = [];
        foreach ($qty as $id => $q) {
            if ((float) $q > 0) {
                $items[] = ['item_id' => (int) $id, 'qty' => round((float) $q, 3)];
            }
        }
        if ($items === []) {
            throw new ValidationException('Enter a quantity to return.', ['qty' => 'Required']);
        }
        // the refund is cash only for what was paid; a credit sale's return reduces the due
        $lines = array_column(db_connect()->table('order_items')->select('id, qty, returned_qty, line_total')->where('order_id', $orderId)->get()->getResultArray(), null, 'id');
        $base = (float) $o['total'] - (float) $o['shipping'] - (float) $o['round_off'];
        $factor = (float) $o['subtotal'] > 0 ? $base / (float) $o['subtotal'] : 0;
        $value = 0.0;
        foreach ($items as $it) {
            $l = $lines[$it['item_id']] ?? null;
            if ($l !== null && (float) $l['qty'] > 0) {
                $value += (float) $l['line_total'] / (float) $l['qty'] * min($it['qty'], (float) $l['qty'] - (float) $l['returned_qty']) * $factor;
            }
        }
        $method = round($value, 2) <= (float) $o['due_total'] + 0.009 && (float) $o['due_total'] > 0 ? 'credit' : ($as === 'exchange' ? 'exchange' : 'cash');
        if ($method === 'exchange' && ($o['customer_id'] === null || (bool) (db_connect()->table('customers')->select('is_walk_in')->where('id', $o['customer_id'])->get()->getRow('is_walk_in') ?? 1))) {
            throw new BusinessRuleException('Exchange credit needs a named customer on the invoice — choose a cash refund, or put the customer on the invoice first.');
        }
        $r = service('orders')->returnItems($orderId, $items, $method, mb_substr(trim($reason), 0, 200));

        return $r + ['method' => $method];
    }

    /** Delivery status of a web / marketplace order; "delivered" can collect the COD amount. */
    public function shipStatus(int $orderId, string $status, bool $collect = false): array
    {
        if (! in_array($status, self::SHIP, true)) {
            throw new ValidationException('Unknown delivery status.', ['ship_status' => 'Invalid']);
        }
        $o = $this->order($orderId);
        if ($o['ship_status'] === null && $o['channel'] === 'pos') {
            throw new BusinessRuleException('This order has no delivery.');
        }
        if (in_array($o['ship_status'], ['delivered', 'cancelled'], true) && $o['ship_status'] !== $status) {
            throw new BusinessRuleException('This delivery is already ' . ship_label($o['ship_status']) . '.');
        }
        db_connect()->table('orders')->where('id', $orderId)->where('business_id', (int) $this->business['id'])->update(['ship_status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->activity('Delivery status', $o['doc_no'] . ' · ' . ship_label($status));
        if ($status === 'delivered' && $collect && (float) $o['due_total'] > 0) {
            if (! service('posSession')->can('orders.view')) {
                throw new PermissionDeniedException();
            }
            service('orders')->addPayments($orderId, [['method' => 'cod', 'amount' => (float) $o['due_total'], 'reference' => 'Collected on delivery']], RegisterService::forCurrent()->currentId());
        }

        return $this->order($orderId);
    }
}
