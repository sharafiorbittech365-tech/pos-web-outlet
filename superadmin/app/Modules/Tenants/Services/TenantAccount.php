<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Account changes Super Admin makes on a tenant: subscription, limits, modules, notes, suspension,
 * owner password, deletion. Every change is audited and clears the tenant's ModuleAccess cache.
 * Only account rows are touched — never workspace records.
 */
final class TenantAccount
{
    /** Plan features that can be overridden per tenant: key => label */
    public const FEATURES = [
        'addons' => 'Add-on modules',
        'invoiceDesigns' => 'Logo & letterhead invoices',
        'csv' => 'CSV import / export',
        'multiOutlet' => 'Multi-outlet switching',
        'prioritySupport' => 'Priority support',
    ];

    public const SUSPEND_REASONS = ['Non-payment', 'Terms of service violation', 'Owner request', 'Suspected fraud', 'Other'];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    /** @return array<string, mixed> tenant + subscription row */
    private function load(int $tenantId, bool $lock = false): array
    {
        $db = db_connect();
        $sql = 'SELECT t.id, t.name, t.business_type, t.owner_name, t.owner_email, s.id AS sub_id, s.plan_id, s.billing_cycle, s.status, s.trial_ends_at, s.period_start,
                       s.period_end, s.cancel_at, s.next_plan_id, s.suspend_reason
                  FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id
                 WHERE t.id = ? AND t.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : '');
        $row = $db->query($sql, [$tenantId])->getRowArray();

        return $row ?? throw new NotFoundException('Account not found.');
    }

    private function audit(string $action, string $detail, int $tenantId): void
    {
        service('audit')->platform($action, $detail, $tenantId, $this->actor);
    }

    private function done(int $tenantId): void
    {
        service('moduleAccess')->invalidate($tenantId);
        cache()->delete('omnipos_sa_pastdue');
    }

    /**
     * Change plan / business type / cycle / end date / scheduled plan without an invoice
     * (comps, migrations, corrections).
     *
     * @param array{plan_id: int|string, business_type: string, billing_cycle: string, ends_on?: string, next_plan_id?: int|string|null} $in
     */
    public function updateSubscription(int $tenantId, array $in): void
    {
        $catalog = service('catalog');
        $planId = (int) $in['plan_id'];
        $type = (string) $in['business_type'];
        $plan = $catalog->plan($planId);
        if (! isset($catalog->businessTypes()[$type])) {
            throw new ValidationException('Choose a business type.', ['business_type' => 'Unknown business type.']);
        }
        if ($catalog->planPrice($planId, $type) === null) {
            throw new BusinessRuleException($plan['name'] . ' is not offered for ' . $catalog->typeName($type) . '.');
        }
        $next = (int) ($in['next_plan_id'] ?? 0) ?: null;
        if ($next !== null) {
            $catalog->plan($next);
        }
        Tx::run(function (BaseConnection $db) use ($tenantId, $in, $planId, $type, $plan, $next, $catalog): void {
            $t = $this->load($tenantId, true);
            $now = date('Y-m-d H:i:s');
            $sub = ['plan_id' => $planId, 'billing_cycle' => $in['billing_cycle'] === 'yearly' ? 'yearly' : 'monthly', 'next_plan_id' => $next, 'updated_at' => $now];
            if (! empty($in['ends_on'])) {
                $end = date('Y-m-d 23:59:59', (int) strtotime((string) $in['ends_on']));
                if ($t['status'] === 'trial') {
                    $sub['trial_ends_at'] = $end;
                } else {
                    $sub['period_end'] = $end;
                    if ($t['cancel_at'] !== null) {
                        $sub['cancel_at'] = $end;
                    }
                }
            }
            $db->table('subscriptions')->where('tenant_id', $tenantId)->update($sub);
            if ($type !== $t['business_type']) {
                $db->table('tenants')->where('id', $tenantId)->update(['business_type' => $type, 'updated_at' => $now]);
            }
            $changes = [];
            if ($planId !== (int) $t['plan_id']) {
                $changes[] = 'plan ' . $catalog->plan((int) $t['plan_id'])['name'] . ' → ' . $plan['name'];
            }
            if ($sub['billing_cycle'] !== $t['billing_cycle']) {
                $changes[] = 'cycle ' . $sub['billing_cycle'];
            }
            if ($type !== $t['business_type']) {
                $changes[] = 'type ' . $catalog->typeName($type);
            }
            if (isset($sub['trial_ends_at']) && substr((string) $t['trial_ends_at'], 0, 10) !== substr($sub['trial_ends_at'], 0, 10)) {
                $changes[] = 'trial ends ' . substr($sub['trial_ends_at'], 0, 10);
            }
            if (isset($sub['period_end']) && substr((string) $t['period_end'], 0, 10) !== substr($sub['period_end'], 0, 10)) {
                $changes[] = 'period ends ' . substr($sub['period_end'], 0, 10);
            }
            if ($next !== ((int) $t['next_plan_id'] ?: null)) {
                $changes[] = $next ? 'next plan ' . $catalog->plan($next)['name'] : 'no scheduled plan';
            }
            $this->audit('Subscription changed', $t['name'] . ($changes ? ' · ' . implode(', ', $changes) : ' · no change'), $tenantId);
        });
        $this->done($tenantId);
    }

    public function extendTrial(int $tenantId, int $days): void
    {
        Tx::run(function (BaseConnection $db) use ($tenantId, $days): void {
            $t = $this->load($tenantId, true);
            if (! in_array($t['status'], ['trial', 'expired'], true)) {
                throw new BusinessRuleException('Only a free trial can be extended.');
            }
            $from = $t['trial_ends_at'] !== null && $t['trial_ends_at'] > date('Y-m-d H:i:s') ? $t['trial_ends_at'] : date('Y-m-d H:i:s');
            $end = date('Y-m-d H:i:s', (int) strtotime($from . ' +' . $days . ' days'));
            $db->table('subscriptions')->where('tenant_id', $tenantId)->update(['status' => 'trial', 'trial_ends_at' => $end, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->audit('Trial extended', $t['name'] . ' · +' . $days . ' days → ' . substr($end, 0, 10), $tenantId);
        });
        $this->done($tenantId);
    }

    /** Cancel at period end, or withdraw a scheduled cancellation. Returns true when now scheduled. */
    public function toggleCancel(int $tenantId): bool
    {
        $on = Tx::run(function (BaseConnection $db) use ($tenantId): bool {
            $t = $this->load($tenantId, true);
            if (! in_array($t['status'], ['active', 'past_due'], true)) {
                throw new BusinessRuleException('Only a paid subscription can be cancelled at period end.');
            }
            $on = $t['cancel_at'] === null;
            $upd = ['cancel_at' => $on ? $t['period_end'] : null, 'updated_at' => date('Y-m-d H:i:s')];
            if ($on) {
                $upd['auto_pay'] = 0;
            }
            $db->table('subscriptions')->where('tenant_id', $tenantId)->update($upd);
            $this->audit($on ? 'Cancellation scheduled' : 'Cancellation withdrawn', $t['name'] . ($on ? ' · ends ' . substr((string) $t['period_end'], 0, 10) : ''), $tenantId);

            return $on;
        });
        $this->done($tenantId);

        return $on;
    }

    /**
     * @param string|null                $outlets  '' = plan limit, 0 = unlimited
     * @param string|null                $users
     * @param array<string, string>      $features key => plan|on|off
     */
    public function setLimits(int $tenantId, ?string $outlets, ?string $users, array $features): void
    {
        $ov = [];
        foreach (array_merge(array_keys(self::FEATURES), array_keys(self::posFeatures())) as $k) {
            $v = $features[$k] ?? 'plan';
            if ($v === 'on' || $v === 'off') {
                $ov[$k] = $v === 'on';
            }
        }
        Tx::run(function (BaseConnection $db) use ($tenantId, $outlets, $users, $ov): void {
            $t = $this->load($tenantId, true);
            $row = [
                'limit_outlets' => $outlets === null || $outlets === '' ? null : max(0, (int) $outlets),
                'limit_users' => $users === null || $users === '' ? null : max(0, (int) $users),
                'feature_overrides' => $ov === [] ? null : json_encode($ov),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $db->table('tenants')->where('id', $tenantId)->update($row);
            $fmt = static fn (?int $n): string => $n === null ? 'plan' : ($n === 0 ? 'unlimited' : (string) $n);
            $this->audit('Limits changed', $t['name'] . ' · outlets ' . $fmt($row['limit_outlets']) . ', users ' . $fmt($row['limit_users'])
                . ($ov ? ' · features ' . implode(', ', array_map(static fn ($k, $v): string => $k . ($v ? ' on' : ' off'), array_keys($ov), $ov)) : ''), $tenantId);
        });
        $this->done($tenantId);
    }

    /**
     * POS switches Super Admin can offer or withhold per account, stored with the plan feature
     * overrides as "pos.<key>" (BusinessTypeSetup::allowedFeatures()): on = offered to every outlet
     * even where the business type does not offer it, off = never.
     *
     * @return array<string, string> "pos.<key>" => label
     */
    public static function posFeatures(): array
    {
        $out = [];
        foreach (\OmniPOS\Core\Services\BusinessTypeSetup::FEATURES as [$k, $label]) {
            $out['pos.' . $k] = $label;
        }

        return $out;
    }

    /**
     * Module features (module_features) switched on or off for this account by Super Admin. Such a
     * switch wins over the plan default, the customer's choice and "not offered for the business type",
     * and the customer cannot change it; "plan" hands the feature back to the defaults / the customer.
     *
     * @param array<string, string> $features feature code => plan|on|off
     */
    public function setFeatures(int $tenantId, array $features): void
    {
        $catalog = service('catalog');
        $known = $catalog->features();
        $t = $this->load($tenantId);
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $changed = [];
        foreach ($features as $code => $v) {
            $code = (string) $code;
            if (! isset($known[$code]) || ! in_array($v, ['plan', 'on', 'off'], true)) {
                continue;
            }
            $row = $db->table('tenant_features')->where('tenant_id', $tenantId)->where('feature_code', $code)->get()->getRowArray();
            $admin = $row !== null && (int) ($row['set_by_admin'] ?? 0) === 1;
            if ($v === 'plan') {
                if ($admin) {
                    $db->table('tenant_features')->where('tenant_id', $tenantId)->where('feature_code', $code)->delete();
                    $changed[] = $known[$code]['name'] . ' back to plan';
                }
                continue;
            }
            $on = $v === 'on';
            if ($admin && (bool) (int) $row['is_enabled'] === $on) {
                continue;
            }
            $db->query('INSERT INTO tenant_features (tenant_id, feature_code, is_enabled, set_by_admin, added_at) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), set_by_admin = 1, added_at = VALUES(added_at)',
                [$tenantId, $code, $on ? 1 : 0, $now]);
            $changed[] = $known[$code]['name'] . ($on ? ' on' : ' off');
        }
        if ($changed !== []) {
            $this->audit('Features changed', $t['name'] . ' · ' . implode(', ', $changed), $tenantId);
            $this->done($tenantId);
        }
    }

    /**
     * @param array<string, string> $overrides module => plan|on|off
     * @param list<string>          $addons    bought add-on modules
     */
    public function setModules(int $tenantId, array $overrides, array $addons, array $locks = []): void
    {
        $modules = service('catalog')->modules();
        $addons = array_values(array_unique(array_filter($addons, static fn (string $m): bool => isset($modules[$m]) && ! (bool) $modules[$m]['is_core'])));
        $this->assertCompatible($tenantId, $overrides, $addons);
        Tx::run(function (BaseConnection $db) use ($tenantId, $overrides, $addons, $modules, $locks): void {
            $t = $this->load($tenantId, true);
            $now = date('Y-m-d H:i:s');
            $all = $db->table('tenant_module_overrides')->where('tenant_id', $tenantId)->get()->getResultArray();
            // switches the customer made (Subscription, set_by_owner) stay unless Super Admin sets on/off for that module
            $before = array_column(array_filter($all, static fn (array $r): bool => (int) ($r['set_by_owner'] ?? 0) === 0), 'is_enabled', 'module_code');
            $beforeAddons = array_column($db->table('tenant_addons')->where('tenant_id', $tenantId)->get()->getResultArray(), 'module_code');
            $set = array_keys(array_filter($overrides, static fn ($v): bool => $v === 'on' || $v === 'off'));
            $db->table('tenant_module_overrides')->where('tenant_id', $tenantId)->groupStart()->where('set_by_owner', 0)->orWhereIn('module_code', $set ?: [''])->groupEnd()->delete();
            $rows = [];
            foreach ($overrides as $code => $v) {
                if (isset($modules[$code]) && ($v === 'on' || $v === 'off')) {
                    $rows[] = ['tenant_id' => $tenantId, 'module_code' => $code, 'is_enabled' => $v === 'on' ? 1 : 0, 'set_by' => $this->actor['id'] ?? null, 'updated_at' => $now];
                }
            }
            if ($rows !== []) {
                $db->table('tenant_module_overrides')->insertBatch($rows);
            }
            // self-selection per customer: default (catalogue) / allow / lock
            foreach ($locks as $code => $v) {
                if (! isset($modules[$code]) || ! in_array($v, ['default', 'allow', 'lock'], true)) {
                    continue;
                }
                $old = $db->table('tenant_module_locks')->select('self_select')->where('tenant_id', $tenantId)->where('module_code', $code)->get()->getRow('self_select');
                $oldV = $old === null ? 'default' : ((int) $old ? 'allow' : 'lock');
                if ($oldV === $v) {
                    continue;
                }
                if ($v === 'default') {
                    $db->table('tenant_module_locks')->where('tenant_id', $tenantId)->where('module_code', $code)->delete();
                } else {
                    $db->query('INSERT INTO tenant_module_locks (tenant_id, module_code, self_select, set_by, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE self_select = VALUES(self_select), set_by = VALUES(set_by), updated_at = VALUES(updated_at)',
                        [$tenantId, $code, $v === 'allow' ? 1 : 0, $this->actor['id'] ?? null, $now]);
                }
                $lockDiff[] = $code . ' self-select ' . $v;
            }
            $remove = array_diff($beforeAddons, $addons);
            $add = array_diff($addons, $beforeAddons);
            if ($remove !== []) {
                $db->table('tenant_addons')->where('tenant_id', $tenantId)->whereIn('module_code', array_values($remove))->delete();
            }
            if ($add !== []) {
                $db->table('tenant_addons')->insertBatch(array_map(static fn (string $m): array => ['tenant_id' => $tenantId, 'module_code' => $m, 'added_at' => $now], array_values($add)));
            }
            $after = array_column($rows, 'is_enabled', 'module_code');
            $diff = $lockDiff ?? [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $code) {
                $b = isset($before[$code]) ? ((int) $before[$code] ? 'on' : 'off') : 'plan';
                $a = isset($after[$code]) ? ($after[$code] ? 'on' : 'off') : 'plan';
                if ($a !== $b) {
                    $diff[] = $code . ' ' . $a;
                }
            }
            foreach ($add as $m) {
                $diff[] = '+' . $m . ' add-on';
            }
            foreach ($remove as $m) {
                $diff[] = '-' . $m . ' add-on';
            }
            $this->audit('Modules changed', $t['name'] . ' · ' . ($diff ? implode(', ', $diff) : 'no change'), $tenantId);
        });
        $this->done($tenantId);
    }

    /**
     * Dependency & compatibility rules before saving: every module switched on or bought must
     * actually come on (its needs on, no conflict, available for the business type).
     *
     * @param array<string, string> $overrides module => plan|on|off
     * @param list<string>          $addons
     */
    private function assertCompatible(int $tenantId, array $overrides, array $addons): void
    {
        $modules = service('catalog')->modules();
        $sw = [];
        foreach ($overrides as $code => $v) {
            if (isset($modules[$code]) && ($v === 'on' || $v === 'off')) {
                $sw[(string) $code] = $v === 'on' ? 1 : 0;
            }
        }
        $x = service('moduleAccess')->preview($tenantId, $sw, $addons);
        // switched on here, or newly bought (older add-ons that no longer apply are left alone)
        $had = array_column(db_connect()->table('tenant_addons')->select('module_code')->where('tenant_id', $tenantId)->get()->getResultArray(), 'module_code');
        $wanted = array_unique(array_merge(array_keys(array_filter($sw)), array_filter(array_diff($addons, $had), static fn (string $m): bool => ! array_key_exists($m, $sw))));
        $errors = [];
        foreach ($wanted as $code) {
            if (! in_array($code, $x['on'], true)) {
                $errors[] = $modules[$code]['name'] . ': ' . ($x['reasons'][$code] ?? 'cannot be switched on.');
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Some modules cannot be switched on: ' . implode(' ', $errors), ['mod' => implode(' ', $errors)]);
        }
    }

    public function addNote(int $tenantId, string $body): void
    {
        $t = $this->load($tenantId);
        db_connect()->table('tenant_notes')->insert(['tenant_id' => $tenantId, 'admin_id' => $this->actor['id'] ?? null, 'author' => $this->actor['name'],
            'body' => trim($body), 'created_at' => date('Y-m-d H:i:s')]);
        $this->audit('Note added', $t['name'] . ' · ' . mb_substr(trim($body), 0, 80), $tenantId);
    }

    public function suspend(int $tenantId, string $reason, string $note = ''): void
    {
        if (! in_array($reason, self::SUSPEND_REASONS, true)) {
            throw new ValidationException('Choose a reason.', ['reason' => 'Choose a reason.']);
        }
        Tx::run(function (BaseConnection $db) use ($tenantId, $reason, $note): void {
            $t = $this->load($tenantId, true);
            if ($t['status'] === 'suspended') {
                throw new BusinessRuleException('This account is already suspended.');
            }
            $db->table('subscriptions')->where('tenant_id', $tenantId)->update(['status' => 'suspended', 'suspend_reason' => $reason, 'updated_at' => date('Y-m-d H:i:s')]);
            if (trim($note) !== '') {
                $db->table('tenant_notes')->insert(['tenant_id' => $tenantId, 'admin_id' => $this->actor['id'] ?? null, 'author' => $this->actor['name'],
                    'body' => 'Suspended: ' . trim($note), 'created_at' => date('Y-m-d H:i:s')]);
            }
            $this->audit('Tenant suspended', $t['name'] . ' · ' . $reason, $tenantId);
        });
        $this->done($tenantId);
    }

    /**
     * Suspended → active (or past due while invoices are open); expired / cancelled → a fresh paid
     * period on the current plan with an open start invoice.
     *
     * @return string the new status
     */
    public function reactivate(int $tenantId): string
    {
        $t = $this->load($tenantId);
        if ($t['status'] === 'suspended') {
            $status = Tx::run(function (BaseConnection $db) use ($tenantId): string {
                $t = $this->load($tenantId, true);
                $open = $db->table('platform_invoices')->where('tenant_id', $tenantId)->where('status', 'open')->countAllResults();
                $now = date('Y-m-d H:i:s');
                if ($t['period_end'] === null && $t['trial_ends_at'] !== null) {
                    // never paid: back to the free trial (or trial ended)
                    $upd = ['status' => $t['trial_ends_at'] > $now ? 'trial' : 'expired', 'suspend_reason' => null, 'updated_at' => $now];
                    $db->table('subscriptions')->where('tenant_id', $tenantId)->update($upd);
                    $this->audit('Tenant reactivated', $t['name'] . ' · ' . $upd['status'], $tenantId);

                    return $upd['status'];
                }
                $upd = ['status' => $open > 0 ? 'past_due' : 'active', 'suspend_reason' => null, 'updated_at' => $now];
                if ($t['period_end'] === null || $t['period_end'] < $now) {
                    $upd['period_start'] = $now;
                    $upd['period_end'] = date('Y-m-d H:i:s', (int) strtotime($now . ' +' . ($t['billing_cycle'] === 'yearly' ? 12 : 1) . ' month'));
                }
                $db->table('subscriptions')->where('tenant_id', $tenantId)->update($upd);
                $this->audit('Tenant reactivated', $t['name'] . ' · ' . $upd['status'], $tenantId);

                return $upd['status'];
            });
        } elseif (in_array($t['status'], ['expired', 'cancelled'], true)) {
            $status = Tx::run(function (BaseConnection $db) use ($tenantId, $t): string {
                $billing = service('billing');
                $inv = $billing->start($tenantId, (int) $t['plan_id'], (string) $t['billing_cycle']);
                $billing->activate($tenantId, $inv);
                $db->table('subscriptions')->where('tenant_id', $tenantId)->update(['status' => 'past_due', 'updated_at' => date('Y-m-d H:i:s')]);
                $this->audit('Tenant reactivated', $t['name'] . ' · new period, invoice ' . $inv['number'], $tenantId);

                return 'past_due';
            });
        } else {
            throw new BusinessRuleException('This account is not locked.');
        }
        $this->done($tenantId);

        return $status;
    }

    /** New one-time password for the owner; the current one stops working. */
    public function resetOwnerPassword(int $tenantId): string
    {
        $t = $this->load($tenantId);
        $password = TenantProvisioner::oneTimePassword();
        $db = db_connect();
        $owner = $db->table('users')->select('id')->where('tenant_id', $tenantId)->where('is_owner', 1)->orderBy('id')->limit(1)->get()->getRowArray()
            ?? throw new NotFoundException('This account has no owner user.');
        $db->table('users')->where('id', $owner['id'])->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'failed_logins' => 0,
            'locked_until' => null, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit('Owner password reset', $t['name'] . ' · one-time password issued', $tenantId);

        return $password;
    }

    /**
     * Soft delete: the account disappears from Super Admin and the POS at once, open invoices are
     * voided; omnipos:purge-tenants erases the workspace data after the retention period.
     */
    public function delete(int $tenantId, string $confirm): void
    {
        $t = $this->load($tenantId);
        if (mb_strtolower(trim($confirm)) !== mb_strtolower(trim((string) $t['name']))) {
            throw new ValidationException('Type the account name to confirm.', ['confirm' => 'Type the account name exactly.']);
        }
        Tx::run(function (BaseConnection $db) use ($tenantId, $t): void {
            $now = date('Y-m-d H:i:s');
            $voided = $db->table('platform_invoices')->where('tenant_id', $tenantId)->where('status', 'open')->countAllResults();
            $db->table('platform_invoices')->where('tenant_id', $tenantId)->where('status', 'open')->update(['status' => 'void', 'note' => 'Account deleted', 'updated_at' => $now]);
            $db->table('subscriptions')->where('tenant_id', $tenantId)->update(['cancel_at' => $now, 'auto_pay' => 0, 'updated_at' => $now]);
            $db->table('tenants')->where('id', $tenantId)->update(['deleted_at' => $now, 'updated_at' => $now]);
            $this->audit('Tenant deleted', $t['name'] . ' · ' . $voided . ' open invoice(s) voided · data purged after retention', $tenantId);
        });
        $this->done($tenantId);
        service('sharedCache')->delete('omnipos_srv_t' . $tenantId);
    }
}
