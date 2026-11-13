<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\SuppliersModel;

/**
 * Suppliers, purchases (received stock goes through StockService, reason "purchase"), supplier
 * payments and payables of the current outlet. suppliers.balance_due is what the outlet still owes.
 */
final class PurchaseService
{
    public const METHODS = ['cash' => 'Cash', 'bank' => 'Bank transfer', 'mobile' => 'bKash / Nagad / Rocket', 'cheque' => 'Cheque', 'card' => 'Card'];

    public function __construct()
    {
        helper('ui');
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    /* ------------------------------------------------------------------ suppliers */

    public function suppliersQuery(): BaseBuilder
    {
        return $this->db()->table('suppliers s')
            ->select('s.id, s.name, s.phone, s.email, s.address, s.balance_due,
                      (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.id) AS n,
                      (SELECT COALESCE(SUM(p.total), 0) FROM purchases p WHERE p.supplier_id = s.id) AS purchased,
                      (SELECT MAX(p.purchased_at) FROM purchases p WHERE p.supplier_id = s.id) AS last_at')
            ->where('s.business_id', $this->bid());
    }

    /** @return list<array{id: int, name: string, balance_due: float}> */
    public function supplierOptions(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'balance_due' => (float) $r['balance_due']],
            $this->db()->table('suppliers')->select('id, name, balance_due')->where('business_id', $this->bid())->orderBy('name')->limit(1000)->get()->getResultArray());
    }

    /** @return array<string, mixed> */
    public function supplier(int $id): array
    {
        return (new SuppliersModel())->find($id) ?? throw new NotFoundException('Supplier not found.');
    }

    /** @param array<string, mixed> $in */
    public function saveSupplier(?int $id, array $in): int
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        if ($name === '') {
            throw new ValidationException('Enter the supplier name.', ['name' => 'Supplier name is required.']);
        }
        $email = trim((string) ($in['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Check the email.', ['email' => 'Enter a valid email or leave it empty.']);
        }
        $row = ['name' => $name, 'phone' => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 32) ?: null, 'email' => $email ?: null, 'address' => mb_substr(trim((string) ($in['address'] ?? '')), 0, 255) ?: null];
        $model = new SuppliersModel();
        if ($id !== null) {
            $this->supplier($id);
            $model->update($id, $row);
            service('audit')->activity('Supplier updated', $name);

            return $id;
        }
        $opening = max(0.0, round((float) ($in['opening'] ?? 0), 2));
        $newId = (int) $model->insert($row + ['balance_due' => $opening]);
        service('audit')->activity('Supplier added', $name . ($opening > 0 ? ' · opening due ' . number_format($opening, 2) : ''));

        return $newId;
    }

    public function deleteSupplier(int $id): void
    {
        $s = $this->supplier($id);
        if ($this->db()->table('purchases')->where('supplier_id', $id)->countAllResults() > 0 || (float) $s['balance_due'] > 0) {
            throw new BusinessRuleException($s['name'] . ' has purchases or a balance — it cannot be deleted.');
        }
        (new SuppliersModel())->delete($id);
        service('audit')->activity('Supplier deleted', (string) $s['name']);
    }

    /**
     * Supplier ledger: purchases (debit) and payments (credit) with running balance, oldest first.
     *
     * @return list<array{at: string, kind: string, ref: string, id: int, debit: float, credit: float, bal: float}>
     */
    public function ledger(int $supplierId): array
    {
        $s = $this->supplier($supplierId);
        $db = $this->db();
        $rows = [];
        foreach ($db->table('purchases')->select('id, reference, bill_no, total, purchased_at')->where('supplier_id', $supplierId)->where('business_id', $this->bid())->orderBy('purchased_at')->limit(1000)->get()->getResultArray() as $p) {
            $rows[] = ['at' => $p['purchased_at'], 'kind' => 'pu', 'ref' => $p['reference'] . ($p['bill_no'] ? ' · bill ' . $p['bill_no'] : ''), 'id' => (int) $p['id'], 'debit' => (float) $p['total'], 'credit' => 0.0];
        }
        foreach ($db->table('supplier_payments')->select('id, amount, method, reference, paid_at')->where('supplier_id', $supplierId)->where('business_id', $this->bid())->orderBy('paid_at')->limit(1000)->get()->getResultArray() as $p) {
            $rows[] = ['at' => $p['paid_at'], 'kind' => 'sp', 'ref' => 'Payment · ' . (self::METHODS[$p['method']] ?? $p['method']) . ($p['reference'] ? ' · ' . $p['reference'] : ''), 'id' => (int) $p['id'], 'debit' => 0.0, 'credit' => (float) $p['amount']];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']) ?: strcmp($a['kind'], $b['kind']));
        // opening balance = what remains unexplained by purchases and payments
        $opening = round((float) $s['balance_due'] - array_sum(array_column($rows, 'debit')) + array_sum(array_column($rows, 'credit')), 2);
        $bal = $opening;
        $out = abs($opening) > 0.009 ? [['at' => (string) $s['created_at'], 'kind' => 'op', 'ref' => 'Opening balance', 'id' => 0, 'debit' => max(0, $opening), 'credit' => max(0, -$opening), 'bal' => $opening]] : [];
        foreach ($rows as $r) {
            $bal = round($bal + $r['debit'] - $r['credit'], 2);
            $out[] = $r + ['bal' => $bal];
        }

        return $out;
    }

    /* ------------------------------------------------------------------ purchases */

    /** @param array{status?: string, supplier?: int} $f */
    public function purchasesQuery(array $f, string $from, string $to): BaseBuilder
    {
        $b = $this->db()->table('purchases p')
            ->select('p.id, p.reference, p.bill_no, p.total, p.paid, (p.total - p.paid) AS due, p.status, p.purchased_at, p.note, s.name AS supplier, p.supplier_id,
                      (SELECT GROUP_CONCAT(CONCAT(i.name, \' ×\', TRIM(TRAILING \'.\' FROM TRIM(TRAILING \'0\' FROM i.qty))) SEPARATOR \', \') FROM purchase_items i WHERE i.purchase_id = p.id) AS items', false)
            ->join('suppliers s', 's.id = p.supplier_id')
            ->where('p.business_id', $this->bid())->where('p.purchased_at >=', $from)->where('p.purchased_at <', $to);
        if (in_array($f['status'] ?? '', ['paid', 'partial', 'due'], true)) {
            $b->where('p.status', $f['status']);
        }
        if (! empty($f['supplier'])) {
            $b->where('p.supplier_id', (int) $f['supplier']);
        }

        return $b;
    }

    public function paymentsQuery(string $from, string $to): BaseBuilder
    {
        return $this->db()->table('supplier_payments sp')
            ->select('sp.id, sp.amount, sp.method, sp.reference, sp.paid_at, s.name AS supplier, sp.supplier_id, p.reference AS purchase_ref, sp.purchase_id, u.name AS user_name')
            ->join('suppliers s', 's.id = sp.supplier_id')
            ->join('purchases p', 'p.id = sp.purchase_id', 'left')
            ->join('users u', 'u.id = sp.user_id', 'left')
            ->where('sp.business_id', $this->bid())->where('sp.paid_at >=', $from)->where('sp.paid_at <', $to);
    }

    /** @return array{purchased: float, paid: float, payable: float, suppliers: int, counts: array<string, int>} */
    public function stats(string $from, string $to): array
    {
        $db = $this->db();
        $bid = $this->bid();
        $p = $db->query('SELECT COALESCE(SUM(total), 0) AS t, SUM(status = \'paid\') AS paid_n, SUM(status = \'partial\') AS partial_n, SUM(status = \'due\') AS due_n, COUNT(*) AS n
                           FROM purchases WHERE business_id = ? AND purchased_at >= ? AND purchased_at < ?', [$bid, $from, $to])->getRowArray() ?? [];
        $paid = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS a FROM supplier_payments WHERE business_id = ? AND paid_at >= ? AND paid_at < ?', [$bid, $from, $to])->getRow('a') ?? 0);
        $s = $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS due FROM suppliers WHERE business_id = ?', [$bid])->getRowArray() ?? [];

        return ['purchased' => (float) ($p['t'] ?? 0), 'paid' => $paid, 'payable' => (float) ($s['due'] ?? 0), 'suppliers' => (int) ($s['n'] ?? 0),
            'counts' => ['all' => (int) ($p['n'] ?? 0), 'paid' => (int) ($p['paid_n'] ?? 0), 'partial' => (int) ($p['partial_n'] ?? 0), 'due' => (int) ($p['due_n'] ?? 0)]];
    }

    /** @return array<string, mixed> purchase with items and payments */
    public function purchase(int $id): array
    {
        $db = $this->db();
        $p = $db->table('purchases p')->select('p.*, s.name AS supplier, s.phone AS supplier_phone, u.name AS user_name')->join('suppliers s', 's.id = p.supplier_id')->join('users u', 'u.id = p.user_id', 'left')
            ->where('p.id', $id)->where('p.business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Purchase not found.');
        $p['items'] = $db->table('purchase_items')->where('purchase_id', $id)->orderBy('id')->get()->getResultArray();
        $p['payments'] = $db->table('supplier_payments')->where('purchase_id', $id)->where('business_id', $this->bid())->orderBy('paid_at')->get()->getResultArray();

        return $p;
    }

    /** @return list<array{id: int, name: string, cost: float, stock_type: string, unit: ?string}> products for the purchase form */
    public function productOptions(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'cost' => (float) $r['cost'], 'stock_type' => $r['stock_type'], 'unit' => $r['unit']],
            $this->db()->table('products')->select('id, name, cost, stock_type, unit')->where('business_id', $this->bid())->where('deleted_at', null)->orderBy('name')->limit(3000)->get()->getResultArray());
    }

    /**
     * Record a purchase. Items with a catalogue product (and "receive" on) raise stock through
     * StockService; the unpaid part is added to the supplier's balance.
     *
     * @param array<string, mixed> $in supplier_id, bill_no, purchased_at, note, paid, method, reference, receive, update_cost, items[]
     * @return int purchase id
     */
    public function createPurchase(array $in): int
    {
        $supplierId = (int) ($in['supplier_id'] ?? 0);
        $sup = $this->supplier($supplierId);
        $items = $this->items((array) ($in['items'] ?? []));
        $total = round(array_sum(array_column($items, 'line_total')), 2);
        $paid = round(max(0.0, (float) ($in['paid'] ?? 0)), 2);
        if ($paid > $total + 0.009) {
            throw new ValidationException('Paid is more than the purchase total.', ['paid' => 'At most ' . number_format($total, 2) . '.']);
        }
        $method = array_key_exists((string) ($in['method'] ?? ''), self::METHODS) ? (string) $in['method'] : 'cash';
        $at = $this->dateTime((string) ($in['purchased_at'] ?? ''));
        // stock only moves when Purchase, Inventory and "Automatic stock update" are all on (PurchaseSettings)
        $receive = ! empty($in['receive']) && PurchaseSettings::stockLinked();
        $updateCost = ! empty($in['update_cost']);
        $ctx = service('tenant');

        $id = Tx::run(function (BaseConnection $db) use ($sup, $items, $total, $paid, $method, $at, $receive, $updateCost, $in, $ctx): int {
            $bid = $this->bid();
            $last = $db->query('SELECT reference FROM purchases WHERE business_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE', [$bid])->getRowArray();
            $n = $last !== null && preg_match('/(\d+)$/', (string) $last['reference'], $m) ? (int) $m[1] + 1 : 1;
            $ref = 'PO-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $status = $paid >= $total - 0.009 ? 'paid' : ($paid > 0 ? 'partial' : 'due');
            $db->table('purchases')->insert([
                'tenant_id' => $ctx->tenantId(), 'business_id' => $bid, 'supplier_id' => (int) $sup['id'], 'reference' => $ref,
                'bill_no' => mb_substr(trim((string) ($in['bill_no'] ?? '')), 0, 48) ?: null, 'total' => $total, 'paid' => $paid, 'status' => $status,
                'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null, 'user_id' => $ctx->userId(), 'purchased_at' => $at, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $pid = (int) $db->insertID();
            $stock = service('stock');
            foreach ($items as $it) {
                $db->table('purchase_items')->insert(['purchase_id' => $pid, 'product_id' => $it['product_id'], 'name' => $it['name'], 'qty' => $it['qty'], 'unit_cost' => $it['unit_cost'], 'line_total' => $it['line_total']]);
                if ($it['product'] === null) {
                    continue;
                }
                $prod = $it['product'];
                if ($receive) {
                    match ($prod['stock_type']) {
                        'simple' => $stock->adjust((int) $prod['id'], $it['qty'], 'purchase', $ref . ' · ' . $sup['name'], null, 'purchase', $pid),
                        'variant' => $it['variant_id'] !== null ? $stock->adjust((int) $prod['id'], $it['qty'], 'purchase', $ref . ' · ' . $sup['name'], $it['variant_id'], 'purchase', $pid) : null,
                        'batch' => $stock->receiveBatch((int) $prod['id'], $it['batch_no'] !== '' ? $it['batch_no'] : $ref, $it['expires_on'], $it['qty'], 'purchase', $ref . ' · ' . $sup['name'], 'purchase', $pid),
                        'serial' => $stock->receiveSerials((int) $prod['id'], $it['serials'], 'purchase', $ref . ' · ' . $sup['name'], 'purchase', $pid),
                        default => null,
                    };
                }
                if ($updateCost && $it['unit_cost'] > 0) {
                    $db->table('products')->where('id', $prod['id'])->where('business_id', $bid)->update(['cost' => $it['unit_cost'], 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
            if ($paid > 0) {
                $db->table('supplier_payments')->insert(['tenant_id' => $ctx->tenantId(), 'business_id' => $bid, 'supplier_id' => (int) $sup['id'], 'purchase_id' => $pid, 'amount' => $paid,
                    'method' => $method, 'reference' => mb_substr(trim((string) ($in['reference'] ?? '')), 0, 64) ?: null, 'user_id' => $ctx->userId(), 'paid_at' => $at]);
            }
            if ($total - $paid > 0.009) {
                $db->table('suppliers')->set('balance_due', 'balance_due + ' . $db->escape(round($total - $paid, 2)), false)->set('updated_at', date('Y-m-d H:i:s'))->where('id', $sup['id'])->where('business_id', $bid)->update();
            }

            return $pid;
        });
        service('audit')->activity('Purchase', $sup['name'] . ' · ' . number_format($total, 2) . ($paid < $total ? ' · due ' . number_format($total - $paid, 2) : ''));

        return $id;
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return list<array{product_id: ?int, product: ?array, variant_id: ?int, name: string, qty: float, unit_cost: float, line_total: float, batch_no: string, expires_on: ?string, serials: list<string>}>
     */
    private function items(array $rows): array
    {
        $db = $this->db();
        $ids = array_values(array_filter(array_map(static fn ($r): int => is_array($r) ? (int) ($r['product_id'] ?? 0) : 0, $rows)));
        $products = $ids === [] ? [] : array_column($db->table('products')->select('id, name, stock_type, unit')->where('business_id', $this->bid())->where('deleted_at', null)->whereIn('id', $ids)->get()->getResultArray(), null, 'id');
        $out = [];
        $errors = [];
        foreach ($rows as $i => $r) {
            if (! is_array($r)) {
                continue;
            }
            $pid = (int) ($r['product_id'] ?? 0);
            $prod = $pid > 0 ? ($products[$pid] ?? null) : null;
            $name = trim((string) ($r['name'] ?? '')) ?: (string) ($prod['name'] ?? '');
            $serials = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($r['serials'] ?? '')) ?: []))));
            $qty = $prod !== null && $prod['stock_type'] === 'serial' && $serials !== [] ? (float) count($serials) : round((float) ($r['qty'] ?? 0), 3);
            if ($name === '' && $qty <= 0) {
                continue;
            }
            if ($pid > 0 && $prod === null) {
                $errors['items'] = 'Row ' . ((int) $i + 1) . ': product not found.';
                continue;
            }
            if ($name === '' || $qty <= 0) {
                $errors['items'] = 'Row ' . ((int) $i + 1) . ': enter the item and a quantity.';
                continue;
            }
            $cost = round(max(0.0, (float) ($r['unit_cost'] ?? 0)), 2);
            $exp = trim((string) ($r['expires_on'] ?? ''));
            $out[] = ['product_id' => $prod !== null ? (int) $prod['id'] : null, 'product' => $prod, 'variant_id' => (int) ($r['variant_id'] ?? 0) ?: null, 'name' => mb_substr($name, 0, 200),
                'qty' => $qty, 'unit_cost' => $cost, 'line_total' => round($qty * $cost, 2), 'batch_no' => mb_substr(trim((string) ($r['batch_no'] ?? '')), 0, 48),
                'expires_on' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null, 'serials' => $serials];
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the items.', $errors);
        }
        if ($out === []) {
            throw new ValidationException('Add at least one item.', ['items' => 'Add at least one item with a quantity.']);
        }

        return $out;
    }

    private function dateTime(string $in): string
    {
        $t = $in !== '' ? strtotime($in) : false;
        if ($t === false || $t > time() + 86400) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d', $t) === date('Y-m-d') ? date('Y-m-d H:i:s') : date('Y-m-d 12:00:00', $t);
    }

    /**
     * Pay a supplier: reduces the balance and settles the oldest unpaid purchases first.
     *
     * @return array{amount: float, after: float}
     */
    public function pay(int $supplierId, float $amount, string $method, string $reference = '', string $date = ''): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ValidationException('Enter the amount paid.', ['amount' => 'Enter an amount above zero.']);
        }
        $method = array_key_exists($method, self::METHODS) ? $method : 'cash';
        $at = $this->dateTime($date);
        $ctx = service('tenant');
        $res = Tx::run(function (BaseConnection $db) use ($supplierId, $amount, $method, $reference, $at, $ctx): array {
            $bid = $this->bid();
            $s = $db->query('SELECT id, name, balance_due FROM suppliers WHERE id = ? AND business_id = ? FOR UPDATE', [$supplierId, $bid])->getRowArray() ?? throw new NotFoundException('Supplier not found.');
            if ($amount > (float) $s['balance_due'] + 0.009) {
                throw new BusinessRuleException('You owe ' . $s['name'] . ' only ' . number_format((float) $s['balance_due'], 2) . '.');
            }
            $left = $amount;
            $first = null;
            foreach ($db->query("SELECT id, total, paid FROM purchases WHERE supplier_id = ? AND business_id = ? AND status <> 'paid' ORDER BY purchased_at, id FOR UPDATE", [$supplierId, $bid])->getResultArray() as $p) {
                if ($left <= 0.009) {
                    break;
                }
                $take = min($left, round((float) $p['total'] - (float) $p['paid'], 2));
                if ($take <= 0) {
                    continue;
                }
                $paid = round((float) $p['paid'] + $take, 2);
                $db->table('purchases')->where('id', $p['id'])->update(['paid' => $paid, 'status' => $paid >= (float) $p['total'] - 0.009 ? 'paid' : 'partial']);
                $first ??= (int) $p['id'];
                $left = round($left - $take, 2);
            }
            $db->table('supplier_payments')->insert(['tenant_id' => $ctx->tenantId(), 'business_id' => $bid, 'supplier_id' => $supplierId, 'purchase_id' => $first, 'amount' => $amount,
                'method' => $method, 'reference' => mb_substr(trim($reference), 0, 64) ?: null, 'user_id' => $ctx->userId(), 'paid_at' => $at]);
            $after = round((float) $s['balance_due'] - $amount, 2);
            $db->table('suppliers')->where('id', $supplierId)->update(['balance_due' => $after, 'updated_at' => date('Y-m-d H:i:s')]);

            return ['amount' => $amount, 'after' => $after, 'name' => $s['name']];
        });
        service('audit')->activity('Supplier paid', $res['name'] . ' · ' . number_format($amount, 2) . ' via ' . $method);

        return ['amount' => $res['amount'], 'after' => $res['after']];
    }
}
