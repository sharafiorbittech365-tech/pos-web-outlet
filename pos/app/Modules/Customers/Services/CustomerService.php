<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Register\Services\RegisterService;
use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\CustomersModel;

/**
 * Customers of the current outlet and their due book (বাকির খাতা). The due itself is always
 * changed through the core CustomerLedgerService (service('ledger')).
 */
final class CustomerService
{
    private CustomersModel $model;

    public function __construct()
    {
        $this->model = new CustomersModel();
    }

    /** List query: name, phone, group, credit limit, due, invoices, last purchase. */
    public function listQuery(string $filter = ''): BaseBuilder
    {
        $b = $this->model->scoped('c')
            ->select('c.id, c.name, c.phone, c.price_group, c.credit_limit, c.balance_due, c.points, c.is_walk_in, c.created_at,
                (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id AND o.status NOT IN (\'cancelled\',\'held\')) AS invoices,
                (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id) AS last_at', false);
        if ($filter === 'due') {
            $b->where('c.balance_due >', 0);
        } elseif ($filter === 'over') {
            $b->where('c.credit_limit >', 0)->where('c.balance_due > c.credit_limit * 0.8', null, false);
        }

        return $b;
    }

    /** @return array{customers: int, receivable: float, with_due: int, collected_today: float} */
    public function stats(): array
    {
        $db = db_connect();
        $bid = service('tenant')->businessId();
        $r = $db->table('customers')->select('COUNT(*) AS n, COALESCE(SUM(balance_due), 0) AS due, SUM(balance_due > 0) AS with_due', false)
            ->where('business_id', $bid)->where('is_walk_in', 0)->get()->getRowArray();
        $today = (float) $db->table('customer_ledger')->select('COALESCE(SUM(-amount), 0) AS v')->where('business_id', $bid)->where('entry_type', 'payment')
            ->where('created_at >=', date('Y-m-d 00:00:00'))->get()->getRow('v');

        return ['customers' => (int) $r['n'], 'receivable' => (float) $r['due'], 'with_due' => (int) $r['with_due'], 'collected_today' => $today];
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        return $this->model->scoped('c')->where('c.id', $id)->get()->getRowArray() ?? throw new NotFoundException('Customer not found.');
    }

    /**
     * Search for the till's customer picker.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $q, int $limit = 10): array
    {
        $b = $this->model->scoped('c')->select('c.id, c.name, c.phone, c.price_group, c.credit_limit, c.balance_due, c.exchange_balance, c.is_walk_in');
        $q = trim($q);
        if ($q !== '') {
            $digits = preg_replace('/[^0-9]/', '', $q);
            $b->groupStart()->like('c.name', $q, 'both');
            if ($digits !== '' && strlen($digits) >= 3) {
                $b->orLike('c.phone', $digits, 'both');
            }
            $b->groupEnd();
        }

        return $b->orderBy('c.is_walk_in', 'DESC')->orderBy('c.name')->limit($limit)->get()->getResultArray();
    }

    /** @param array<string, mixed> $in name, phone, email, address, price_group, credit_limit */
    public function create(array $in): array
    {
        $row = $this->clean($in);
        $this->assertPhoneFree($row['phone'], null);
        $id = (int) $this->model->insert($row + ['balance_due' => 0, 'is_walk_in' => 0], true);
        service('audit')->activity('Customer added', $row['name']);

        return $this->find($id);
    }

    public function update(int $id, array $in): array
    {
        $c = $this->find($id);
        if ((bool) $c['is_walk_in']) {
            throw new BusinessRuleException('The walk-in customer cannot be edited.');
        }
        $row = $this->clean($in);
        $this->assertPhoneFree($row['phone'], $id);
        $this->model->update($id, $row);
        service('audit')->activity('Customer edited', $row['name']);

        return $this->find($id);
    }

    /** Receive a due payment: allocated to the oldest unpaid invoices first (core ledger). */
    public function collect(int $id, float $amount, string $method, string $reference = '', string $note = ''): array
    {
        $c = $this->find($id);
        $methods = array_values(array_filter((array) (service('posSession')->business()['settings']['payments'] ?? ['cash']), static fn ($m) => $m !== 'credit'));
        if (! in_array($method, $methods ?: ['cash'], true)) {
            throw new ValidationException('Choose a payment method.', ['method' => 'Not offered']);
        }
        $r = service('ledger')->collect($id, round($amount, 2), $method, mb_substr($reference, 0, 64), mb_substr($note, 0, 200), RegisterService::forCurrent()->currentId());
        service('audit')->activity('Due collected', $c['name'] . ' · ' . number_format($amount, 2) . ' · ' . $method);

        return $r + ['customer' => $c];
    }

    /** Opening balance from an old khata (or another due without an invoice). */
    public function opening(int $id, float $amount, string $note): float
    {
        $c = $this->find($id);
        if ((bool) $c['is_walk_in']) {
            throw new BusinessRuleException('Add the due to a named customer.');
        }
        if ($amount <= 0) {
            throw new ValidationException('Enter the amount owed.', ['amount' => 'Must be more than 0']);
        }
        $bal = Tx::run(static fn () => service('ledger')->opening($id, round($amount, 2), $note !== '' ? mb_substr($note, 0, 200) : 'Opening balance'));
        service('audit')->activity('Previous due added', $c['name'] . ' · ' . number_format($amount, 2));

        return $bal;
    }

    /** Ledger rows of one customer for the DataTable (newest first by default). */
    public function ledgerQuery(int $customerId): BaseBuilder
    {
        return db_connect()->table('customer_ledger l')
            ->select('l.id, l.entry_type, l.amount, l.balance_after, l.method, l.ref_type, l.ref_id, l.note, l.created_at, o.doc_no')
            ->join('orders o', "o.id = l.ref_id AND l.ref_type = 'order'", 'left')
            ->where('l.business_id', service('tenant')->businessId())->where('l.customer_id', $customerId);
    }

    /**
     * Statement for printing: brought-forward balance and the period's rows (oldest first).
     *
     * @return array{opening: float, rows: list<array<string, mixed>>, debit: float, credit: float, closing: float, from: ?string}
     */
    public function statement(int $customerId, string $period): array
    {
        $from = match ($period) {
            'month' => date('Y-m-01 00:00:00'),
            '3m' => date('Y-m-d 00:00:00', strtotime('-3 months')),
            'year' => date('Y-01-01 00:00:00'),
            default => null,
        };
        $db = db_connect();
        $bid = service('tenant')->businessId();
        $opening = 0.0;
        if ($from !== null) {
            $prev = $db->table('customer_ledger')->select('balance_after')->where('business_id', $bid)->where('customer_id', $customerId)
                ->where('created_at <', $from)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
            $opening = (float) ($prev['balance_after'] ?? 0);
        }
        $q = $this->ledgerQuery($customerId);
        if ($from !== null) {
            $q->where('l.created_at >=', $from);
        }
        $rows = $q->orderBy('l.created_at')->orderBy('l.id')->limit(2000)->get()->getResultArray();
        $debit = array_sum(array_map(static fn ($r) => max(0, (float) $r['amount']), $rows));
        $credit = array_sum(array_map(static fn ($r) => max(0, -(float) $r['amount']), $rows));
        $closing = $rows !== [] ? (float) end($rows)['balance_after'] : $opening;

        return ['opening' => $opening, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit, 'closing' => $closing, 'from' => $from];
    }

    /** @return list<array<string, mixed>> unpaid invoices, oldest first */
    public function openInvoices(int $customerId): array
    {
        return db_connect()->table('orders')->select('id, doc_no, total, due_total, created_at')->where('business_id', service('tenant')->businessId())
            ->where('customer_id', $customerId)->where('due_total >', 0)->whereIn('status', ['due', 'partial'])->orderBy('created_at')->limit(50)->get()->getResultArray();
    }

    private function clean(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            throw new ValidationException('Enter the customer name.', ['name' => 'Required']);
        }
        $phone = preg_replace('/[^0-9+]/', '', (string) ($in['phone'] ?? '')) ?: null;
        $email = trim((string) ($in['email'] ?? ''));

        return [
            'name' => mb_substr($name, 0, 120), 'phone' => $phone !== null ? mb_substr($phone, 0, 32) : null,
            'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 160) : null,
            'address' => trim((string) ($in['address'] ?? '')) !== '' ? mb_substr(trim((string) $in['address']), 0, 255) : null,
            'price_group' => trim((string) ($in['price_group'] ?? '')) !== '' ? mb_substr(trim((string) $in['price_group']), 0, 32) : null,
            'credit_limit' => max(0, round((float) ($in['credit_limit'] ?? 0), 2)),
        ];
    }

    private function assertPhoneFree(?string $phone, ?int $exceptId): void
    {
        if ($phone === null) {
            return;
        }
        $b = $this->model->scoped('c')->where('c.phone', $phone);
        if ($exceptId !== null) {
            $b->where('c.id !=', $exceptId);
        }
        if ($b->countAllResults() > 0) {
            throw new ValidationException('Another customer already has this phone number.', ['phone' => 'Already used']);
        }
    }
}
