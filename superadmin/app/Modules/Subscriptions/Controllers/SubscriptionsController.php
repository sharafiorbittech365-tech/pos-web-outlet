<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Controllers;

use App\Controllers\BaseController;
use App\Modules\Subscriptions\Services\MrrCalculator;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Modules\Tenants\Services\TenantDirectory;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class SubscriptionsController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $catalog = service('catalog');
        $filters = [
            'status' => $this->pick('status', TenantDirectory::STATUSES),
            'plan' => $this->pick('plan', array_column($catalog->plans(), 'code')),
            'cycle' => $this->pick('cycle', ['monthly', 'yearly']),
            'due' => $this->pick('due', ['7', '30']),
        ];
        $mrr = [];
        $now = date('Y-m-d H:i:s');
        $table = DataTable::make('subs', $this->request)
            ->from(SubscriptionDirectory::listQuery($filters))
            ->keep(array_filter($filters))
            ->columns([
                Column::make('tenant', 'Account')->sortBy('t.name')->searchIn('t.name', 't.owner_email', 't.code')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['tenant_id'] . '?tab=subscription'), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted">' . esc($r['type_name']) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('plan', 'Plan')->sortBy('p.sort_order')
                    ->cell(static fn (array $r): string => '<span class="dot-c" style="--c:' . css_color($r['plan_color']) . '"></span>' . esc($r['plan_name']) . ($r['next_plan_name'] ? '<br><small class="muted">→ ' . esc($r['next_plan_name']) . ' at renewal</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['plan_name']),
                Column::make('cycle', 'Cycle')->sortBy('s.billing_cycle')->cell(static fn (array $r): string => esc(ucfirst((string) $r['billing_cycle'])) . ((int) $r['auto_pay'] ? '<br><small class="muted">auto-pay · ' . esc((string) $r['pay_method']) . '</small>' : ''))->csv(static fn (array $r): string => (string) $r['billing_cycle']),
                Column::make('status', 'Status')->sortBy('s.status')->cell(static fn (array $r): string => status_tag($r['status']) . ($r['cancel_at'] ? '<br>' . tag_html('Cancels', 'warn') : '')),
                Column::make('renews', 'Renews / ends')->nowrap()->sortBy('renew_at')
                    ->cell(static function (array $r) use ($now): string {
                        $at = $r['status'] === 'trial' ? $r['trial_ends_at'] : ($r['cancel_at'] ?: $r['period_end']);
                        $soon = $at !== null && $at < date('Y-m-d H:i:s', strtotime('+7 days'));

                        return ($r['status'] === 'trial' ? 'Trial · ' : ($r['cancel_at'] ? 'Ends · ' : '')) . '<span class="' . ($soon && $at >= $now ? 'neg' : '') . '">' . esc(date_str($at)) . '</span>';
                    })
                    ->csv(static fn (array $r): string => (string) ($r['status'] === 'trial' ? $r['trial_ends_at'] : ($r['cancel_at'] ?: $r['period_end']))),
                Column::make('open', 'Unpaid')->num()->sortBy('open_total')
                    ->cell(static fn (array $r): string => (float) $r['open_total'] > 0 ? '<span class="neg">' . esc(money($r['open_total'], ['decimals' => 0])) . '</span>' : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => (string) (float) $r['open_total']),
                Column::make('mrr', 'MRR')->num()->cell(static function (array $r) use (&$mrr): string {
                    return '<b>' . esc(money($mrr[(int) $r['tenant_id']] ?? 0, ['decimals' => 0])) . '</b>';
                })->noCsv(),
            ])
            ->defaultSort('renews', 'asc')
            ->placeholder('Account, email, code…')
            ->unit('subscriptions')
            ->exportable('subscriptions');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $calc = new MrrCalculator();
        $mrr = $calc->forTenants(array_map(static fn (array $r): int => (int) $r['tenant_id'], $table->result()['rows']));

        return $this->page('App\Modules\Subscriptions\Views\index', [
            'title' => 'Subscriptions',
            'table' => $table->render(),
            'filters' => $filters,
            'plans' => array_values($catalog->plans()),
            'stats' => SubscriptionDirectory::stats() + ['mrr' => $calc->total()],
        ]);
    }
}
