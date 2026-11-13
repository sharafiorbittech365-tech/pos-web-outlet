<?php

declare(strict_types=1);

namespace App\Modules\Register\Services;

use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Cash register sessions of the current outlet (one open session per outlet).
 *
 * Expected cash in the drawer = opening cash
 *   + cash payments (sales, payments taken later, due collections allocated to invoices)
 *   + cash due collections not allocated to an invoice (old khata balances)
 *   − cash refunds − cash expenses
 * over the session window (opened_at … closed_at / now), all on business_id + time indexes.
 */
final class RegisterService
{
    public function __construct(private readonly int $businessId)
    {
    }

    public static function forCurrent(): self
    {
        return new self(service('posSession')->businessId());
    }

    /** @return array<string, mixed>|null the open session */
    public function current(): ?array
    {
        return db_connect()->table('registers')->where('business_id', $this->businessId)->where('closed_at', null)
            ->orderBy('opened_at', 'DESC')->limit(1)->get()->getRowArray();
    }

    public function currentId(): ?int
    {
        $r = $this->current();

        return $r !== null ? (int) $r['id'] : null;
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return db_connect()->table('registers')->where('id', $id)->where('business_id', $this->businessId)->get()->getRowArray()
            ?? throw new NotFoundException('Register session not found.');
    }

    public function open(float $opening, string $note = ''): int
    {
        if ($opening < 0) {
            throw new BusinessRuleException('Opening cash cannot be negative.');
        }
        $id = Tx::run(function ($db) use ($opening, $note): int {
            // lock the outlet's open sessions so two tills cannot open at once
            $open = $db->query('SELECT id FROM registers WHERE business_id = ? AND closed_at IS NULL FOR UPDATE', [$this->businessId])->getRowArray();
            if ($open !== null) {
                throw new BusinessRuleException('The register is already open.');
            }
            $ctx = service('tenant');
            $db->table('registers')->insert(['tenant_id' => $ctx->tenantId(), 'business_id' => $this->businessId, 'user_id' => $ctx->userId(),
                'opened_at' => date('Y-m-d H:i:s'), 'opening_cash' => round($opening, 2), 'note' => $note !== '' ? mb_substr($note, 0, 255) : null]);

            return (int) $db->insertID();
        });
        service('audit')->activity('Register opened', number_format($opening, 2));

        return $id;
    }

    /**
     * Session report (the design build's registerReport).
     *
     * @param array<string, mixed> $reg registers row
     * @return array<string, mixed>
     */
    public function summary(array $reg): array
    {
        $db = db_connect();
        $from = (string) $reg['opened_at'];
        $to = (string) ($reg['closed_at'] ?? date('Y-m-d H:i:s'));
        $bid = $this->businessId;

        $byMethod = [];
        foreach ($db->table('order_payments')->select('method, SUM(amount) AS v, COUNT(*) AS n')->where('business_id', $bid)
            ->where('created_at >=', $from)->where('created_at <=', $to)->groupBy('method')->get()->getResultArray() as $r) {
            $byMethod[$r['method']] = ['v' => (float) $r['v'], 'n' => (int) $r['n']];
        }
        // due collections not allocated to an invoice (opening balances): the ledger row minus the
        // order_payments written by the same collection (same time, customer and user)
        $unalloc = [];
        $cols = $db->table('customer_ledger')->select('customer_id, method, (0 - amount) AS amount, created_at, user_id', false)->where('business_id', $bid)
            ->where('entry_type', 'payment')->where('ref_type', 'collection')->where('created_at >=', $from)->where('created_at <=', $to)->get()->getResultArray();
        if ($cols !== []) {
            $alloc = [];
            foreach ($db->table('order_payments p')->select('o.customer_id, p.created_at, p.user_id, p.method, SUM(p.amount) AS v')
                ->join('orders o', 'o.id = p.order_id')->where('p.business_id', $bid)
                ->whereIn('p.created_at', array_values(array_unique(array_column($cols, 'created_at'))))
                ->groupBy('o.customer_id, p.created_at, p.user_id, p.method')->get()->getResultArray() as $a) {
                $alloc[$a['customer_id'] . '|' . $a['created_at'] . '|' . $a['user_id'] . '|' . $a['method']] = (float) $a['v'];
            }
            foreach ($cols as $c) {
                $left = (float) $c['amount'] - ($alloc[$c['customer_id'] . '|' . $c['created_at'] . '|' . $c['user_id'] . '|' . $c['method']] ?? 0);
                if ($left > 0.009) {
                    $unalloc[$c['method']] = ($unalloc[$c['method']] ?? 0) + $left;
                }
            }
        }
        foreach ($unalloc as $m => $v) {
            $byMethod[$m] = ['v' => ($byMethod[$m]['v'] ?? 0) + $v, 'n' => ($byMethod[$m]['n'] ?? 0) + 1];
        }
        $cashIn = (float) ($byMethod['cash']['v'] ?? 0);

        $refunds = $db->table('order_returns')->select('method, SUM(amount) AS v')->where('business_id', $bid)
            ->where('created_at >=', $from)->where('created_at <=', $to)->groupBy('method')->get()->getResultArray();
        $refundAll = array_sum(array_map(static fn ($r) => (float) $r['v'], $refunds));
        $refundCash = 0.0;
        foreach ($refunds as $r) {
            if ($r['method'] === 'cash') {
                $refundCash = (float) $r['v'];
            }
        }
        $exp = $db->table('expenses')->select('COALESCE(SUM(amount), 0) AS v, COUNT(*) AS n')->where('business_id', $bid)->where('method', 'cash')
            ->where('spent_at >=', $from)->where('spent_at <=', $to)->get()->getRowArray();
        $sales = $db->table('orders')->select('COUNT(*) AS n, COALESCE(SUM(total), 0) AS v, COALESCE(SUM(tax_total), 0) AS tax')->where('business_id', $bid)
            ->whereNotIn('status', ['cancelled', 'held'])->where('created_at >=', $from)->where('created_at <=', $to)->get()->getRowArray();
        $types = $db->table('orders')->select("COALESCE(order_type, 'Counter') AS t, COUNT(*) AS n, SUM(total) AS v", false)->where('business_id', $bid)
            ->whereNotIn('status', ['cancelled', 'held'])->where('created_at >=', $from)->where('created_at <=', $to)->groupBy('t')->get()->getResultArray();
        $products = $db->table('orders o')->select('i.name, MAX(i.sku) AS sku, MAX(i.unit) AS unit, SUM(i.qty - i.returned_qty) AS qty, SUM(i.line_total) AS amount')
            ->join('order_items i', 'i.order_id = o.id')->where('o.business_id', $bid)->whereNotIn('o.status', ['cancelled', 'held'])
            ->where('o.created_at >=', $from)->where('o.created_at <=', $to)->groupBy('i.name')->orderBy('amount', 'DESC')->limit(50)->get()->getResultArray();
        $outstanding = $db->table('orders')->select('COUNT(*) AS n, COALESCE(SUM(due_total), 0) AS v')->where('business_id', $bid)
            ->whereIn('status', ['open', 'due', 'partial'])->get()->getRowArray();

        $opening = (float) $reg['opening_cash'];
        $expCash = (float) $exp['v'];

        return [
            'register' => $reg, 'from' => $from, 'to' => $to,
            'opening' => $opening, 'cash_in' => $cashIn, 'refund_cash' => $refundCash, 'refund_total' => $refundAll, 'expenses' => $expCash, 'expense_n' => (int) $exp['n'],
            'expected' => round($opening + $cashIn - $refundCash - $expCash, 2),
            'by_method' => $byMethod, 'collected' => array_sum(array_column($byMethod, 'v')),
            'gross' => (float) $sales['v'], 'tax' => (float) $sales['tax'], 'invoices' => (int) $sales['n'], 'net' => (float) $sales['v'] - $refundAll,
            'types' => $types, 'products' => $products,
            'outstanding_n' => (int) $outstanding['n'], 'outstanding' => (float) $outstanding['v'],
            'card_slips' => (int) ($byMethod['card']['n'] ?? 0), 'cheques' => (int) ($byMethod['cheque']['n'] ?? 0),
        ];
    }

    /** @return array{expected: float, counted: float, difference: float} */
    public function close(float $counted, string $note = ''): array
    {
        if ($counted < 0) {
            throw new BusinessRuleException('Counted cash cannot be negative.');
        }
        $out = Tx::run(function ($db) use ($counted, $note): array {
            $reg = $db->query('SELECT * FROM registers WHERE business_id = ? AND closed_at IS NULL ORDER BY opened_at DESC LIMIT 1 FOR UPDATE', [$this->businessId])->getRowArray();
            if ($reg === null) {
                throw new BusinessRuleException('The register is not open.');
            }
            $now = date('Y-m-d H:i:s');
            $reg['closed_at'] = $now;
            $expected = $this->summary($reg)['expected'];
            $db->table('registers')->where('id', $reg['id'])->where('business_id', $this->businessId)->update([
                'closed_at' => $now, 'expected_cash' => $expected, 'counted_cash' => round($counted, 2),
                'note' => trim(((string) $reg['note']) . ($note !== '' ? ' · ' . $note : ''), ' ·') ?: null,
            ]);

            return ['expected' => $expected, 'counted' => round($counted, 2), 'difference' => round($counted - $expected, 2)];
        });
        service('audit')->activity('Register closed', 'Expected ' . number_format($out['expected'], 2) . ' · counted ' . number_format($out['counted'], 2));

        return $out;
    }

    /** History query for the DataTable. */
    public function historyQuery(): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table('registers r')
            ->select('r.id, r.opened_at, r.closed_at, r.opening_cash, r.expected_cash, r.counted_cash, r.note, u.name AS user_name, (r.counted_cash - r.expected_cash) AS diff')
            ->join('users u', 'u.id = r.user_id', 'left')
            ->where('r.business_id', $this->businessId);
    }
}
