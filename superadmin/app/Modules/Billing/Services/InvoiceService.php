<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Platform invoices & payments for Super Admin, on top of BillingService:
 * record payment (and activate a paid trial conversion / restart), void, convert a trial.
 */
final class InvoiceService
{
    public const METHODS = ['bKash', 'Nagad', 'Card', 'Bank', 'Cash'];
    public const FILTERS = ['open', 'overdue', 'paid', 'void', 'all'];

    /** Notes BillingService::start() writes on a start invoice. */
    private const START_NOTES = ['Trial converted', 'Subscription started'];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    /** @param array{status?: string, tenant?: int} $filters */
    public static function listQuery(array $filters): BaseBuilder
    {
        $b = db_connect()->table('platform_invoices i')
            ->select('i.id, i.number, i.tenant_id, i.billing_cycle, i.amount, i.vat, i.total, i.period_from, i.period_to, i.issued_at, i.due_at, i.status, i.paid_at, i.note,
                      t.name AS tenant_name, p.name AS plan_name')
            ->select('(SELECT pp.method FROM platform_payments pp WHERE pp.invoice_id = i.id ORDER BY pp.id DESC LIMIT 1) AS pay_method', false)
            ->join('tenants t', 't.id = i.tenant_id')
            ->join('plans p', 'p.id = i.plan_id');
        $st = $filters['status'] ?? 'all';
        if ($st === 'overdue') {
            $b->where('i.status', 'open')->where('i.due_at <', date('Y-m-d H:i:s'));
        } elseif (in_array($st, ['open', 'paid', 'void'], true)) {
            $b->where('i.status', $st);
        }
        if (! empty($filters['tenant'])) {
            $b->where('i.tenant_id', (int) $filters['tenant']);
        }

        return $b;
    }

    public static function paymentsQuery(?int $tenantId = null): BaseBuilder
    {
        $b = db_connect()->table('platform_payments pp')
            ->select('pp.id, pp.invoice_id, pp.tenant_id, pp.amount, pp.method, pp.reference, pp.recorded_by, pp.paid_at, i.number, t.name AS tenant_name')
            ->join('platform_invoices i', 'i.id = pp.invoice_id')
            ->join('tenants t', 't.id = pp.tenant_id');
        if ($tenantId !== null) {
            $b->where('pp.tenant_id', $tenantId);
        }

        return $b;
    }

    /** @return array<string, mixed> invoice with tenant, plan and payments (print view) */
    public static function find(int $id): array
    {
        $db = db_connect();
        $inv = $db->table('platform_invoices i')
            ->select('i.*, t.name AS tenant_name, t.code AS tenant_code, t.owner_name, t.owner_email, t.owner_phone, t.business_type, p.name AS plan_name')
            ->join('tenants t', 't.id = i.tenant_id')->join('plans p', 'p.id = i.plan_id')
            ->where('i.id', $id)->get()->getRowArray() ?? throw new NotFoundException('Invoice not found.');
        $inv['payments'] = $db->table('platform_payments')->where('invoice_id', $id)->orderBy('paid_at')->get()->getResultArray();

        return $inv;
    }

    /** @return array{total: float, count: int, overdue: int, paid_month: float} */
    public static function totals(): array
    {
        $db = db_connect();
        $open = $db->table('platform_invoices')->select('COALESCE(SUM(total), 0) AS total, COUNT(*) AS n, COALESCE(SUM(due_at < NOW()), 0) AS overdue')->where('status', 'open')->get()->getRowArray();
        $paid = $db->table('platform_payments')->select('COALESCE(SUM(amount), 0) AS total')->where('paid_at >=', date('Y-m-01 00:00:00'))->get()->getRowArray();

        return ['total' => (float) $open['total'], 'count' => (int) $open['n'], 'overdue' => (int) $open['overdue'], 'paid_month' => (float) $paid['total']];
    }

    /**
     * Record payment of one open invoice. A paid start invoice (trial conversion / restart)
     * makes the subscription active for the invoice period.
     */
    public function pay(int $invoiceId, string $method, string $reference): float
    {
        $inv = db_connect()->table('platform_invoices')->where('id', $invoiceId)->get()->getRowArray() ?? throw new NotFoundException('Invoice not found.');
        if ($inv['status'] !== 'open') {
            throw new BusinessRuleException('Invoice ' . $inv['number'] . ' is not open.');
        }
        $tenantId = (int) $inv['tenant_id'];
        $sum = Tx::run(function (BaseConnection $db) use ($tenantId, $invoiceId, $method, $reference, $inv): float {
            $billing = service('billing');
            $sum = $billing->pay($tenantId, [$invoiceId], $method, $reference, $this->actor);
            $sub = $db->table('subscriptions')->select('status')->where('tenant_id', $tenantId)->get()->getRowArray();
            if (in_array((string) $inv['note'], self::START_NOTES, true) && in_array($sub['status'] ?? '', ['trial', 'expired', 'cancelled', 'past_due'], true)) {
                $billing->activate($tenantId, $inv);
                service('audit')->platform('Subscription activated', $inv['number'] . ' paid', $tenantId, $this->actor);
            }

            return $sum;
        });
        service('moduleAccess')->invalidate($tenantId);
        cache()->delete('omnipos_sa_pastdue');

        return $sum;
    }

    /** Void an open invoice; a past-due account with nothing left open becomes active again. */
    public function void(int $invoiceId, string $reason = ''): array
    {
        $inv = Tx::run(function (BaseConnection $db) use ($invoiceId, $reason): array {
            $inv = $db->query('SELECT * FROM platform_invoices WHERE id = ? FOR UPDATE', [$invoiceId])->getRowArray() ?? throw new NotFoundException('Invoice not found.');
            if ($inv['status'] !== 'open') {
                throw new BusinessRuleException('Only an open invoice can be voided.');
            }
            $db->table('platform_invoices')->where('id', $invoiceId)->update(['status' => 'void', 'updated_at' => date('Y-m-d H:i:s')]);
            $tid = (int) $inv['tenant_id'];
            $open = $db->table('platform_invoices')->where('tenant_id', $tid)->where('status', 'open')->countAllResults();
            if ($open === 0) {
                $db->table('subscriptions')->where('tenant_id', $tid)->where('status', 'past_due')->update(['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
            }
            service('audit')->platform('Invoice voided', $inv['number'] . ' · ' . number_format((float) $inv['total']) . ($reason !== '' ? ' · ' . $reason : ''), $tid, $this->actor);

            return $inv;
        });
        service('moduleAccess')->invalidate((int) $inv['tenant_id']);
        cache()->delete('omnipos_sa_pastdue');

        return $inv;
    }

    /**
     * Convert a trial (or restart an ended account) on a plan: issues the start invoice; with
     * $payNow the payment is recorded and the subscription becomes active right away.
     *
     * @return array<string, mixed> the start invoice
     */
    public function convert(int $tenantId, int $planId, string $cycle, bool $payNow, string $method, string $reference): array
    {
        $sub = db_connect()->table('subscriptions s')->select('s.status, t.name')->join('tenants t', 't.id = s.tenant_id')
            ->where('s.tenant_id', $tenantId)->where('t.deleted_at', null)->get()->getRowArray() ?? throw new NotFoundException('Account not found.');
        if (! in_array($sub['status'], ['trial', 'expired', 'cancelled'], true)) {
            throw new BusinessRuleException('This account already has a paid subscription — change the plan on the Subscription tab.');
        }
        $inv = Tx::run(function () use ($tenantId, $planId, $cycle, $payNow, $method, $reference, $sub): array {
            $inv = service('billing')->start($tenantId, $planId, $cycle === 'yearly' ? 'yearly' : 'monthly');
            service('audit')->platform($sub['status'] === 'trial' ? 'Trial converted' : 'Subscription started', $sub['name'] . ' · ' . $inv['number'] . ' · ' . number_format((float) $inv['total']), $tenantId, $this->actor);
            if ($payNow) {
                $this->pay((int) $inv['id'], $method, $reference);
            }

            return $inv;
        });
        service('moduleAccess')->invalidate($tenantId);

        return $inv;
    }

    /**
     * Renewals, trial expiry and dunning for every tenant (BillingService::run).
     *
     * @return array{invoices: int, changed: int}
     */
    public function runBilling(): array
    {
        $r = service('billing')->run($this->actor);
        service('audit')->platform('Billing run', $r['invoices'] . ' invoice(s) issued, ' . $r['changed'] . ' account(s) changed', null, $this->actor);
        cache()->delete('omnipos_sa_pastdue');

        return $r;
    }
}
