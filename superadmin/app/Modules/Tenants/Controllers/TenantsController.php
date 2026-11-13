<?php

declare(strict_types=1);

namespace App\Modules\Tenants\Controllers;

use App\Controllers\BaseController;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\InvoiceTable;
use App\Modules\Servers\Services\ServerAdmin;
use App\Modules\Subscriptions\Services\MrrCalculator;
use App\Modules\Tenants\Services\TenantAccount;
use App\Modules\Tenants\Services\TenantDirectory;
use App\Modules\Tenants\Services\TenantModules;
use App\Modules\Tenants\Services\TenantProvisioner;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class TenantsController extends BaseController
{
    public const TABS = ['overview', 'subscription', 'limits', 'modules', 'invoices', 'notes', 'server', 'actions'];

    public function index(): string|ResponseInterface
    {
        $dir = new TenantDirectory();
        $filters = [
            'status' => (string) $this->request->getGet('status'),
            'plan' => (string) $this->request->getGet('plan'),
            'server' => (string) $this->request->getGet('server'),
        ];
        $mrr = [];
        $limit = static fn (mixed $own, mixed $plan): int => $own === null ? (int) $plan : (int) $own;
        $usage = static function (array $r, string $k, int $lim): string {
            $u = (int) $r[$k];

            return '<span class="' . ($lim && $u > $lim ? 'neg' : '') . '">' . number_format($u) . '</span><small class="muted">/' . ($lim ? number_format($lim) : '∞') . '</small>';
        };
        $table = DataTable::make('tenants', $this->request)
            ->from($dir->listQuery($filters))
            ->keep(array_filter($filters))
            ->columns([
                Column::make('name', 'Account')->sortBy('t.name')->searchIn('t.name', 't.owner_name', 't.owner_email', 't.code')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted">' . esc($r['owner_name']) . ' · ' . esc($r['owner_email']) . '</small>')
                    ->csv(static fn (array $r): string => $r['name']),
                Column::make('type', 'Business type')->sortBy('bt.name')->cell(static fn (array $r): string => sym_icon($r['type_icon']) . ' ' . esc($r['type_name']))->csv(static fn (array $r): string => $r['type_name']),
                Column::make('plan', 'Plan')->sortBy('p.sort_order')->cell(static fn (array $r): string => '<span class="dot-c" style="--c:' . css_color($r['plan_color']) . '"></span>' . esc($r['plan_name']) . ' <small class="muted">' . esc($r['billing_cycle']) . '</small>')->csv(static fn (array $r): string => $r['plan_name']),
                Column::make('status', 'Status')->sortBy('s.status')->cell(static fn (array $r): string => status_tag($r['status'])),
                Column::make('server', 'POS server')->sortBy('srv.code')->cell(static fn (array $r): string => $r['server_name'] ? esc($r['server_name']) : '<span class="tag warn">Unassigned</span>')->csv(static fn (array $r): string => (string) $r['server_code']),
                Column::make('outlets', 'Outlets')->num()->sortBy('u.outlets')
                    ->cell(static fn (array $r): string => $usage($r, 'outlets', $limit($r['limit_outlets'], (int) $r['plan_outlets'])))->csv(static fn (array $r): string => (string) (int) $r['outlets']),
                Column::make('users', 'Users')->num()->sortBy('u.users')
                    ->cell(static fn (array $r): string => $usage($r, 'users', $limit($r['limit_users'], (int) $r['plan_users'])))->csv(static fn (array $r): string => (string) (int) $r['users']),
                Column::make('orders', 'Orders / month')->num()->sortBy('u.orders_month')->cell(static fn (array $r): string => number_format((int) $r['orders_month']))->csv(static fn (array $r): string => (string) (int) $r['orders_month']),
                Column::make('mrr', 'MRR')->num()->cell(static function (array $r) use (&$mrr): string {
                    return esc(money($mrr[(int) $r['id']] ?? 0, ['decimals' => 0]));
                })->noCsv(),
                Column::make('renews', 'Renews / ends')->nowrap()->sortBy('renew_at')
                    ->cell(static fn (array $r): string => ($r['status'] === 'trial' ? 'Trial · ' : '') . esc(date_str($r['status'] === 'trial' ? $r['trial_ends_at'] : ($r['cancel_at'] ?: $r['period_end']))))
                    ->csv(static fn (array $r): string => (string) ($r['status'] === 'trial' ? $r['trial_ends_at'] : $r['period_end'])),
                Column::make('created', 'Since')->nowrap()->sortBy('t.created_at')->cell(static fn (array $r): string => esc(date_str($r['created_at'])))->csv(static fn (array $r): string => (string) $r['created_at']),
            ])
            ->defaultSort('created', 'desc')
            ->placeholder('Account, owner or email…')
            ->unit('accounts')
            ->exportable('tenants');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $mrr = (new MrrCalculator())->forTenants(array_map(static fn (array $r): int => (int) $r['id'], $table->result()['rows']));

        return $this->page('App\Modules\Tenants\Views\index', [
            'title' => 'Customers & tenants',
            'table' => $table->render(),
            'counts' => $dir->statusCounts(),
            'filters' => $filters,
            'plans' => array_values(service('catalog')->plans()),
            'servers' => ServerAdmin::options(),
        ]);
    }

    public function new(): string
    {
        $catalog = service('catalog');

        return $this->page('App\Modules\Tenants\Views\new', [
            'title' => 'New tenant', 'back' => [site_url('tenants'), 'Customers & tenants'],
            'plans' => array_values($catalog->plans(true)), 'types' => $catalog->businessTypes(), 'trialDays' => (int) $catalog->setting('trialDays', 14),
            'trialPlan' => (string) $catalog->setting('trialPlan', 'business'),
        ]);
    }

    public function create(): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[160]',
            'owner_name' => 'required|max_length[100]',
            'owner_email' => 'required|valid_email|max_length[160]',
            'owner_phone' => 'permit_empty|max_length[32]|regex_match[/^[0-9+\-\s()]+$/]',
            'business_type' => 'required|max_length[32]',
            'plan_id' => 'required|is_natural_no_zero',
            'billing_cycle' => 'required|in_list[monthly,yearly]',
            'start' => 'required|in_list[trial,invoice]',
        ]);
        $r = (new TenantProvisioner())->create($data, $this->actor());
        session()->setFlashdata('otp', ['title' => 'Tenant created', 'name' => $data['owner_name'], 'email' => mb_strtolower(trim($data['owner_email'])), 'password' => $r['password']]);

        return $this->done('Tenant created and assigned to ' . $r['server'] . '.', site_url('tenants/' . $r['tenant_id']));
    }

    public function show(int $id): string|ResponseInterface
    {
        $dir = new TenantDirectory();
        $t = $dir->find($id);
        $tab = $this->pick('tab', self::TABS) ?: 'overview';
        $session = service('adminSession');
        $catalog = service('catalog');
        $data = [
            'title' => $t['name'], 'back' => [site_url('tenants'), 'Customers & tenants'], 'tenant' => $t, 'tab' => $tab,
            'counts' => $dir->tabCounts($id), 'mrr' => (new MrrCalculator())->forTenants([$id])[$id] ?? 0.0,
            'plans' => array_values($catalog->plans()), 'types' => $catalog->businessTypes(),
        ];
        switch ($tab) {
            case 'overview':
                $data['outletTypes'] = $dir->outletTypes($id);
                $data['lastLogin'] = $dir->ownerLastLogin($id);
                break;
            case 'subscription':
                $billing = service('billing');
                $data['monthly'] = $billing->monthlyPrice($id, (int) $t['plan_id'], (string) $t['business_type']);
                $data['cycleTotal'] = $billing->cyclePrice($id, (int) $t['plan_id'], (string) $t['business_type'], (string) $t['billing_cycle']);
                $data['addons'] = array_map(static fn (string $m): array => ['name' => $catalog->modules()[$m]['name'] ?? $m, 'price' => (float) $catalog->modulePrice($m, (string) $t['business_type'])],
                    $billing->paidAddons($id, (int) $t['plan_id'], (string) $t['business_type']));
                $data['methods'] = InvoiceService::METHODS;
                break;
            case 'modules':
                $data['modules'] = (new TenantModules())->rows($t);
                break;
            case 'invoices':
                if ($session->can('billing.view')) {
                    $table = InvoiceTable::make('ti', $this->request, ['tenant' => $id], false)->keep(['tab' => 'invoices']);
                    if ($csv = $table->csvResponse($this->response)) {
                        return $csv;
                    }
                    $data['table'] = $table->render();
                }
                break;
            case 'notes':
                $table = DataTable::make('tn', $this->request)->from($dir->notesQuery($id))->keep(['tab' => 'notes'])
                    ->columns([
                        Column::make('at', 'When')->nowrap()->width('180px')->sortBy('n.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at']))),
                        Column::make('author', 'By')->width('160px')->sortBy('n.author')->searchIn('n.author')->cell(static fn (array $r): string => '<b>' . esc($r['author']) . '</b>'),
                        Column::make('body', 'Note')->searchIn('n.body')->cell(static fn (array $r): string => nl2br(esc($r['body']))),
                    ])
                    ->defaultSort('at', 'desc')->perPage(10)->placeholder('Search notes…')->unit('notes')->emptyHtml('<span class="muted">No notes yet.</span>');
                $data['table'] = $table->render();
                break;
            case 'server':
                $data['servers'] = ServerAdmin::options();
                $data['table'] = DataTable::make('th', $this->request)->from($dir->serverHistoryQuery($id))->keep(['tab' => 'server'])
                    ->columns([
                        Column::make('at', 'When')->nowrap()->sortBy('h.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at']))),
                        Column::make('move', 'Move')->cell(static fn (array $r): string => esc($r['from_name'] ?? 'New account') . ' → <b>' . esc($r['to_name'] ?? '?') . '</b>'),
                        Column::make('by', 'By')->sortBy('h.actor_name')->cell(static fn (array $r): string => esc($r['actor_name'])),
                        Column::make('reason', 'Reason')->searchIn('h.reason')->cell(static fn (array $r): string => esc($r['reason'] ?? '')),
                    ])
                    ->defaultSort('at', 'desc')->perPage(10)->compact()->noSearch()->unit('moves')->render();
                break;
        }

        return $this->page('App\Modules\Tenants\Views\show', $data);
    }

    private function account(): TenantAccount
    {
        return new TenantAccount($this->actor());
    }

    private function back(int $id, string $tab): string
    {
        return site_url('tenants/' . $id) . '?tab=' . $tab;
    }

    public function subscription(int $id): RedirectResponse
    {
        $data = $this->validated([
            'plan_id' => 'required|is_natural_no_zero',
            'business_type' => 'required|max_length[32]',
            'billing_cycle' => 'required|in_list[monthly,yearly]',
            'ends_on' => 'permit_empty|valid_date[Y-m-d]',
            'next_plan_id' => 'permit_empty|is_natural',
        ]);
        $this->account()->updateSubscription($id, $data);

        return $this->done('Subscription saved.', $this->back($id, 'subscription'));
    }

    public function extendTrial(int $id): RedirectResponse
    {
        $data = $this->validated(['days' => 'required|is_natural_no_zero|less_than_equal_to[90]']);
        $this->account()->extendTrial($id, (int) $data['days']);

        return $this->done('Trial extended by ' . (int) $data['days'] . ' days.', $this->back($id, 'subscription'));
    }

    public function convert(int $id): RedirectResponse
    {
        $data = $this->validated([
            'plan_id' => 'required|is_natural_no_zero',
            'billing_cycle' => 'required|in_list[monthly,yearly]',
            'pay_now' => 'permit_empty|in_list[0,1]',
            'method' => 'permit_empty|in_list[' . implode(',', InvoiceService::METHODS) . ']',
            'reference' => 'permit_empty|max_length[64]',
        ]);
        $payNow = ($data['pay_now'] ?? '0') === '1';
        $inv = (new InvoiceService($this->actor()))->convert($id, (int) $data['plan_id'], $data['billing_cycle'], $payNow, (string) ($data['method'] ?? 'Cash') ?: 'Cash', trim((string) ($data['reference'] ?? '')));

        return $this->done($payNow ? 'Paid subscription started (' . $inv['number'] . ').' : 'Invoice ' . $inv['number'] . ' issued — the subscription starts when it is paid.', $this->back($id, 'subscription'));
    }

    public function cancel(int $id): RedirectResponse
    {
        $on = $this->account()->toggleCancel($id);

        return $this->done($on ? 'The subscription ends at the end of the paid period.' : 'Cancellation withdrawn.', $this->back($id, 'actions'));
    }

    public function limits(int $id): RedirectResponse
    {
        $rules = ['limit_outlets' => 'permit_empty|is_natural|less_than_equal_to[10000]', 'limit_users' => 'permit_empty|is_natural|less_than_equal_to[10000]'];
        foreach (array_keys(TenantAccount::FEATURES) as $k) {
            $rules['feature_' . $k] = 'permit_empty|in_list[plan,on,off]';
        }
        // POS switches "pos.<key>" post as posf_<key> (PHP turns dots in field names into underscores)
        foreach (array_keys(TenantAccount::posFeatures()) as $k) {
            $rules['posf_' . substr($k, 4)] = 'permit_empty|in_list[plan,on,off]';
        }
        $data = $this->validated($rules);
        $features = [];
        foreach (array_keys(TenantAccount::FEATURES) as $k) {
            $features[$k] = (string) ($data['feature_' . $k] ?? 'plan');
        }
        foreach (array_keys(TenantAccount::posFeatures()) as $k) {
            $features[$k] = (string) ($data['posf_' . substr($k, 4)] ?? 'plan');
        }
        $this->account()->setLimits($id, $data['limit_outlets'] ?? null, $data['limit_users'] ?? null, $features);

        return $this->done('Limits & features saved.', $this->back($id, 'limits'));
    }

    public function modules(int $id): RedirectResponse
    {
        $this->validated(['mod' => 'permit_empty', 'mod.*' => 'permit_empty|in_list[plan,on,off]', 'addons' => 'permit_empty', 'addons.*' => 'permit_empty|alpha_dash|max_length[32]', 'lock' => 'permit_empty', 'lock.*' => 'permit_empty|in_list[default,allow,lock]',
            'feat' => 'permit_empty', 'feat.*' => 'permit_empty|in_list[plan,on,off]']);
        $mods = (array) ($this->request->getPost('mod') ?? []);
        $addons = array_values(array_map('strval', (array) ($this->request->getPost('addons') ?? [])));
        $this->account()->setModules($id, array_map('strval', $mods), $addons, array_map('strval', (array) ($this->request->getPost('lock') ?? [])));
        // feature codes contain a dot (invoicing.mushak): posted as feat[invoicing:mushak]
        $feat = [];
        foreach ((array) ($this->request->getPost('feat') ?? []) as $code => $v) {
            $feat[str_replace(':', '.', (string) $code)] = (string) $v;
        }
        $this->account()->setFeatures($id, $feat);

        return $this->done('Modules saved.', $this->back($id, 'modules'));
    }

    public function note(int $id): RedirectResponse
    {
        $data = $this->validated(['body' => 'required|max_length[2000]']);
        $this->account()->addNote($id, $data['body']);

        return $this->done('Note added.', $this->back($id, 'notes'));
    }

    public function server(int $id): RedirectResponse
    {
        $data = $this->validated(['server_id' => 'required|is_natural_no_zero', 'reason' => 'required|max_length[160]']);
        $name = (new ServerAdmin($this->actor()))->move($id, (int) $data['server_id'], trim($data['reason']));

        return $this->done('Account moved to ' . $name . '. No data was moved.', $this->back($id, 'server'));
    }

    public function suspend(int $id): RedirectResponse
    {
        $data = $this->validated(['reason' => 'required|in_list[' . implode(',', TenantAccount::SUSPEND_REASONS) . ']', 'note' => 'permit_empty|max_length[500]']);
        $this->account()->suspend($id, $data['reason'], (string) ($data['note'] ?? ''));

        return $this->done('Account suspended.', $this->back($id, 'actions'));
    }

    public function reactivate(int $id): RedirectResponse
    {
        $status = $this->account()->reactivate($id);

        return $this->done('Account reactivated (' . str_replace('_', ' ', $status) . ').', $this->back($id, 'actions'));
    }

    public function resetPassword(int $id): RedirectResponse
    {
        $t = (new TenantDirectory())->find($id);
        $pass = $this->account()->resetOwnerPassword($id);
        session()->setFlashdata('otp', ['title' => 'Password reset', 'name' => $t['owner_name'], 'email' => $t['owner_email'], 'password' => $pass]);

        return $this->done('A one-time password was issued.', $this->back($id, 'actions'));
    }

    public function delete(int $id): RedirectResponse
    {
        $data = $this->validated(['confirm' => 'required|max_length[160]']);
        $this->account()->delete($id, $data['confirm']);

        return $this->done('Account deleted. Its workspace data is erased after the retention period.', site_url('tenants'));
    }
}
