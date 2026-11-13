<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\BillingService;
use OmniPOS\Core\Services\ModuleSelection;
use OmniPOS\Core\Services\PlatformCatalog;

/**
 * The owner's own subscription: plan & price for the account's business type, add-on modules,
 * invoices and payment (demo: recorded with a reference — a live system confirms it with the
 * bKash / card gateway), plan / cycle changes, cancellation at period end.
 * Every rule about prices and status lives in core BillingService / ModuleAccess.
 */
final class SubscriptionService
{
    public const METHODS = ['bKash', 'Nagad', 'Rocket', 'Card'];

    public function __construct(private readonly int $tenantId)
    {
    }

    public static function make(): self
    {
        return new self(service('tenant')->tenantId());
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    private function catalog(): PlatformCatalog
    {
        return service('catalog');
    }

    private function billing(): BillingService
    {
        return service('billing');
    }

    /** @return array{name: string, role: string, id: null} */
    private function actor(): array
    {
        return ['name' => service('tenant')->userName(), 'role' => 'Account owner', 'id' => null];
    }

    /** @return array<string, mixed> tenant + subscription row */
    public function account(): array
    {
        $row = $this->db()->table('tenants t')->select('t.id, t.name, t.owner_name, t.owner_email, t.owner_phone, t.business_type, t.created_at AS since, s.*')
            ->join('subscriptions s', 's.tenant_id = t.id')->where('t.id', $this->tenantId)->get()->getRowArray() ?? throw new NotFoundException('Subscription not found.');
        $row['plan'] = $this->catalog()->plan((int) $row['plan_id']);
        $row['next_plan'] = $row['next_plan_id'] ? $this->catalog()->plan((int) $row['next_plan_id']) : null;

        return $row;
    }

    /**
     * Price for this business type: plan base + billed add-ons, per month and for the cycle.
     *
     * @return array{base: float, addons: list<array{code: string, name: string, price: float}>, monthly: float, cycle: float, yearlyMonths: float}
     */
    public function price(array $acc): array
    {
        if (service('pricing')->active()) {
            $q = service('pricing')->quote($this->tenantId);
            $ym = (float) $this->catalog()->setting('yearlyMonths', 10);

            return ['base' => (float) $q['breakdown']['base'], 'addons' => [], 'monthly' => (float) $q['monthly'], 'cycle' => $acc['billing_cycle'] === 'yearly' ? (float) $q['monthly'] * $ym : (float) $q['monthly'],
                'yearlyMonths' => $ym, 'quote' => $q, 'rows' => \OmniPOS\Core\Services\PricingEngine::summaryRows($q)];
        }
        $type = (string) $acc['business_type'];
        $mods = $this->catalog()->modules();
        $addons = array_map(fn (string $m): array => ['code' => $m, 'name' => (string) ($mods[$m]['name'] ?? $m), 'price' => (float) $this->catalog()->modulePrice($m, $type)],
            $this->billing()->paidAddons($this->tenantId, (int) $acc['plan_id'], $type));

        return [
            'base' => (float) ($this->catalog()->planPrice((int) $acc['plan_id'], $type) ?? 0), 'addons' => $addons,
            'monthly' => $this->billing()->monthlyPrice($this->tenantId, (int) $acc['plan_id'], $type),
            'cycle' => $this->billing()->cyclePrice($this->tenantId, (int) $acc['plan_id'], $type, (string) $acc['billing_cycle']),
            'yearlyMonths' => (float) $this->catalog()->setting('yearlyMonths', 10),
        ];
    }

    /** @return list<array<string, mixed>> active plans offered for the type (plus the current one) with prices */
    public function plans(array $acc, string $cycle): array
    {
        $type = (string) $acc['business_type'];
        $ym = (float) $this->catalog()->setting('yearlyMonths', 10);
        $out = [];
        foreach ($this->catalog()->plans() as $p) {
            $price = $this->catalog()->planPrice((int) $p['id'], $type);
            if ($price === null || (! (bool) $p['is_active'] && (int) $p['id'] !== (int) $acc['plan_id'])) {
                continue;
            }
            $out[] = $p + ['price' => $cycle === 'yearly' ? $price * $ym : $price, 'monthly' => $price];
        }

        return $out;
    }

    /**
     * Module shop: every module offered to the type with its state.
     *
     * @return list<array{code: string, name: string, description: string, icon: string, price: float, state: string, override: ?bool}>
     */
    public function modules(array $acc): array
    {
        $type = (string) $acc['business_type'];
        $db = $this->db();
        $addons = array_column($db->table('tenant_addons')->where('tenant_id', $this->tenantId)->get()->getResultArray(), 'module_code');
        $over = array_map('boolval', array_column($db->table('tenant_module_overrides')->where('tenant_id', $this->tenantId)->get()->getResultArray(), 'is_enabled', 'module_code'));
        $access = service('moduleAccess')->forTenant($this->tenantId);
        $recommended = $this->catalog()->recommended($type);
        $out = [];
        foreach ($this->catalog()->modules() as $code => $m) {
            if ((bool) $m['is_core']) {
                continue;
            }
            $price = $this->catalog()->modulePrice((string) $code, $type);
            if ($price === null) {
                continue;
            }
            $state = array_key_exists($code, $over) ? 'override' : ($this->catalog()->planIncludes($acc['plan'], (string) $code) ? 'included' : (in_array($code, $addons, true) ? 'added' : 'available'));
            $out[] = ['code' => (string) $code, 'name' => (string) $m['name'], 'description' => (string) ($m['description'] ?? ''), 'icon' => (string) $m['icon'], 'price' => $price, 'state' => $state, 'override' => $over[$code] ?? null,
                // dependency rules: what it needs, and why it is off although included / added
                'needs' => array_map(fn (string $n): string => (string) ($this->catalog()->modules()[$n]['name'] ?? $n), (array) $m['needs']),
                'reason' => in_array($state, ['included', 'added'], true) ? $access->reason((string) $code) : null,
                'recommended' => in_array($code, $recommended, true)];
        }

        return $out;
    }

    public function invoicesQuery(): BaseBuilder
    {
        return $this->db()->table('platform_invoices i')
            ->select('i.id, i.number, i.billing_cycle, i.amount, i.vat, i.total, i.period_from, i.period_to, i.issued_at, i.due_at, i.status, i.paid_at, i.note, p.name AS plan,
                      (SELECT pp.method FROM platform_payments pp WHERE pp.invoice_id = i.id ORDER BY pp.id DESC LIMIT 1) AS method')
            ->join('plans p', 'p.id = i.plan_id')
            ->where('i.tenant_id', $this->tenantId);
    }

    /** @return list<array<string, mixed>> open invoices */
    public function openInvoices(): array
    {
        return $this->db()->table('platform_invoices')->where('tenant_id', $this->tenantId)->where('status', 'open')->orderBy('due_at')->get()->getResultArray();
    }

    /** @return array<string, mixed> */
    public function invoice(int $id): array
    {
        $i = $this->db()->table('platform_invoices i')->select('i.*, p.name AS plan')->join('plans p', 'p.id = i.plan_id')->where('i.id', $id)->where('i.tenant_id', $this->tenantId)->get()->getRowArray()
            ?? throw new NotFoundException('Invoice not found.');
        $i['payments'] = $this->db()->table('platform_payments')->where('invoice_id', $id)->where('tenant_id', $this->tenantId)->get()->getResultArray();

        return $i;
    }

    /**
     * Pay open invoices. A paid start invoice (trial conversion / restart) activates the subscription.
     *
     * @param list<int> $ids
     */
    public function pay(array $ids, string $method, string $reference, bool $save): float
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new ValidationException('Choose how you paid.', ['method' => 'Choose bKash, Nagad, Rocket or Card.']);
        }
        $reference = trim($reference);
        if (mb_strlen($reference) < 4) {
            throw new ValidationException('Enter the transaction ID.', ['reference' => 'At least 4 characters, e.g. 8N7A6D5C4B.']);
        }
        $ids = array_values(array_filter(array_map('intval', $ids)));
        $start = $this->db()->table('platform_invoices')->where('tenant_id', $this->tenantId)->whereIn('id', $ids ?: [0])->where('status', 'open')
            ->groupStart()->where('note', 'Trial converted')->orWhere('note', 'Subscription started')->groupEnd()->orderBy('id', 'DESC')->get()->getRowArray();
        $sum = $this->billing()->pay($this->tenantId, $ids, $method, mb_substr($reference, 0, 64), $this->actor());
        if ($start !== null) {
            $this->billing()->activate($this->tenantId, $start);
            service('audit')->platform('Subscription started', $this->catalog()->plan((int) $start['plan_id'])['name'] . ' · ' . $start['billing_cycle'], $this->tenantId, $this->actor());
        }
        if ($save) {
            $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['pay_method' => $method, 'pay_reference' => '••' . mb_substr($reference, -4), 'updated_at' => date('Y-m-d H:i:s')]);
        }
        service('moduleAccess')->invalidate($this->tenantId);

        return $sum;
    }

    /**
     * Change plan and/or cycle.
     *   trial / expired / cancelled → start a paid subscription (start invoice to pay)
     *   upgrade or cycle change     → takes effect now; the rest of this period is billed (prorated)
     *   downgrade                   → scheduled for the next renewal (next_plan_id)
     *
     * @return array{kind: string, invoice: ?array<string, mixed>, plan: string}
     */
    public function changePlan(int $planId, string $cycle): array
    {
        $acc = $this->account();
        $cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
        $plan = $this->catalog()->plan($planId);
        $type = (string) $acc['business_type'];
        if ($this->catalog()->planPrice($planId, $type) === null || ! (bool) $plan['is_active']) {
            throw new BusinessRuleException('This plan is not offered for your business type.');
        }
        $outlets = $this->db()->table('businesses')->where('tenant_id', $this->tenantId)->where('is_active', 1)->countAllResults();
        if ((int) $plan['limit_outlets'] > 0 && $outlets > (int) $plan['limit_outlets']) {
            throw new BusinessRuleException($plan['name'] . ' allows ' . $plan['limit_outlets'] . ' outlet(s) — close outlets first.');
        }
        if (in_array($acc['status'], ['trial', 'expired', 'cancelled'], true)) {
            $inv = $this->billing()->start($this->tenantId, $planId, $cycle);

            return ['kind' => 'start', 'invoice' => $inv, 'plan' => (string) $plan['name']];
        }
        if ($planId === (int) $acc['plan_id'] && $cycle === $acc['billing_cycle']) {
            if ($acc['next_plan_id'] !== null) {
                $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['next_plan_id' => null, 'updated_at' => date('Y-m-d H:i:s')]);
                service('audit')->platform('Downgrade cancelled', $plan['name'], $this->tenantId, $this->actor());

                return ['kind' => 'keep', 'invoice' => null, 'plan' => (string) $plan['name']];
            }
            throw new BusinessRuleException('You are already on ' . $plan['name'] . ' (' . $cycle . ').');
        }
        $billing = $this->billing();
        $curPerMonth = $billing->cyclePrice($this->tenantId, (int) $acc['plan_id'], $type, (string) $acc['billing_cycle']) / BillingService::cycleMonths((string) $acc['billing_cycle']);
        $newPerMonth = $billing->cyclePrice($this->tenantId, $planId, $type, $cycle) / BillingService::cycleMonths($cycle);
        if ($newPerMonth < $curPerMonth - 0.01 && $cycle === $acc['billing_cycle']) {
            $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['next_plan_id' => $planId, 'updated_at' => date('Y-m-d H:i:s')]);
            service('audit')->platform('Downgrade scheduled', $acc['plan']['name'] . ' → ' . $plan['name'] . ' on ' . substr((string) $acc['period_end'], 0, 10), $this->tenantId, $this->actor());

            return ['kind' => 'downgrade', 'invoice' => null, 'plan' => (string) $plan['name']];
        }

        $inv = Tx::run(function (BaseConnection $db) use ($acc, $planId, $cycle, $type, $billing): ?array {
            $now = time();
            $start = $acc['period_start'] ? strtotime((string) $acc['period_start']) : $now;
            $end = $acc['period_end'] ? strtotime((string) $acc['period_end']) : $now;
            $left = $end > $start ? max(0.0, ($end - $now) / ($end - $start)) : 0.0;
            $credit = $billing->cyclePrice($this->tenantId, (int) $acc['plan_id'], $type, (string) $acc['billing_cycle']) * $left;
            $upd = ['plan_id' => $planId, 'next_plan_id' => null, 'updated_at' => date('Y-m-d H:i:s')];
            if ($cycle !== $acc['billing_cycle']) {
                $upd['billing_cycle'] = $cycle;
                $upd['period_start'] = date('Y-m-d H:i:s', $now);
                $upd['period_end'] = date('Y-m-d H:i:s', strtotime('+' . BillingService::cycleMonths($cycle) . ' month', $now));
                $due = $billing->cyclePrice($this->tenantId, $planId, $type, $cycle) - $credit;
                $from = $upd['period_start'];
                $to = $upd['period_end'];
            } else {
                $due = ($billing->cyclePrice($this->tenantId, $planId, $type, $cycle) - $billing->cyclePrice($this->tenantId, (int) $acc['plan_id'], $type, $cycle)) * $left;
                $from = date('Y-m-d H:i:s', $now);
                $to = (string) $acc['period_end'];
            }
            $db->table('subscriptions')->where('tenant_id', $this->tenantId)->update($upd);

            return $due > 1 ? $billing->issueInvoice($this->tenantId, $planId, $cycle, $due, $from, $to, 'Upgrade ' . $acc['plan']['name'] . ' → ' . $this->catalog()->plan($planId)['name'] . ' (prorated)') : null;
        });
        service('audit')->platform('Plan upgraded', $acc['plan']['name'] . ' → ' . $plan['name'] . ' (' . $cycle . ')', $this->tenantId, $this->actor());
        service('moduleAccess')->invalidate($this->tenantId);

        return ['kind' => 'upgrade', 'invoice' => $inv, 'plan' => (string) $plan['name']];
    }

    private function selection(): ModuleSelection
    {
        return new ModuleSelection($this->catalog(), service('moduleAccess'), $this->billing());
    }

    /**
     * Add a module (a priced add-on, or a plan module switched off earlier). Rules, prorating and
     * the invoice lines are in core ModuleSelection / BillingService::prorate.
     *
     * @return ?array<string, mixed> invoice with the prorated charge (none on trial)
     */
    public function addModule(string $code): ?array
    {
        $mod = $this->catalog()->modules()[$code] ?? throw new NotFoundException('Module not found.');
        if ((bool) $mod['is_core']) {
            throw new BusinessRuleException($mod['name'] . ' is always on.');
        }
        $r = $this->selection()->apply($this->tenantId, ['modules' => [$code => true]], $this->actor());
        if ($r['changes'] !== []) {
            service('audit')->activity('Module added', (string) $mod['name']);
        }

        return $r['invoice'];
    }

    public function removeModule(string $code): void
    {
        $mod = $this->catalog()->modules()[$code] ?? throw new NotFoundException('Module not found.');
        $r = $this->selection()->apply($this->tenantId, ['modules' => [$code => false]], $this->actor());
        if ($r['changes'] !== []) {
            service('audit')->activity('Module removed', (string) $mod['name']);
        }
    }

    /** @return array<string, mixed> ModuleSelection::state() */
    public function selectionState(): array
    {
        return $this->selection()->state($this->tenantId);
    }

    /**
     * @param array{modules?: array<string, bool>, features?: array<string, bool>} $want
     * @return array<string, mixed>
     */
    public function quote(array $want, ?int $outlets = null): array
    {
        $r = $this->selection()->quote($this->tenantId, $want);
        $engine = service('pricing');
        if ($engine->active()) {
            // the full, nine-line price of the wanted selection (and, optionally, another outlet count)
            $w = ['modules' => $want['modules'] ?? []];
            if ($outlets !== null) {
                $w['outlets'] = $outlets;
            }
            $q = $engine->quote($this->tenantId, $w);
            $r['breakdown'] = ['rows' => \OmniPOS\Core\Services\PricingEngine::summaryRows($q), 'monthly' => $q['monthly'], 'outlets' => $q['outlets'], 'current_outlets' => $engine->selection($this->tenantId)['outlets']];
        }

        return $r;
    }

    /**
     * @param array{modules?: array<string, bool>, features?: array<string, bool>} $want
     * @return array{changes: list<array<string, string>>, invoice: ?array<string, mixed>, monthly: float}
     */
    public function applySelection(array $want): array
    {
        $r = $this->selection()->apply($this->tenantId, $want, $this->actor());
        if ($r['changes'] !== []) {
            service('audit')->activity('Modules & features changed', implode(', ', array_column($r['changes'], 'label')));
        }

        return $r;
    }

    /** @return list<array<string, mixed>> line items of a platform invoice */
    public function invoiceLines(int $invoiceId): array
    {
        return $this->billing()->invoiceLines($invoiceId);
    }

    public function cancel(): void
    {
        $acc = $this->account();
        if (! in_array($acc['status'], ['active', 'past_due'], true) || ! $acc['period_end']) {
            throw new BusinessRuleException('Only an active paid subscription can be cancelled.');
        }
        $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['cancel_at' => $acc['period_end'], 'auto_pay' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform('Cancellation requested', 'ends ' . substr((string) $acc['period_end'], 0, 10), $this->tenantId, $this->actor());
        service('moduleAccess')->invalidate($this->tenantId);
    }

    public function resume(): void
    {
        $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['cancel_at' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform('Cancellation withdrawn', '', $this->tenantId, $this->actor());
    }

    public function setPayMethod(string $method, bool $autoPay): void
    {
        if (! in_array($method, self::METHODS, true)) {
            throw new ValidationException('Choose a payment method.', ['method' => 'Choose bKash, Nagad, Rocket or Card.']);
        }
        $this->db()->table('subscriptions')->where('tenant_id', $this->tenantId)->update(['pay_method' => $method, 'auto_pay' => $autoPay ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform('Payment method', $method . ($autoPay ? ' · auto-pay' : ''), $this->tenantId, $this->actor());
    }

    /** @return array<string, int> outlets, users, products, orders this month (for the usage meters) */
    public function usage(): array
    {
        return service('usage')->compute($this->tenantId);
    }
}
