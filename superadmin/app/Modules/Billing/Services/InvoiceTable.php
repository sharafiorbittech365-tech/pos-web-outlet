<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use CodeIgniter\HTTP\IncomingRequest;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/**
 * The invoices data table, used on Invoices & payments and on a tenant's Invoices tab.
 * Action cells carry their own small dialogs (record payment) and confirm forms (void).
 */
final class InvoiceTable
{
    /** @param array{status?: string, tenant?: int} $filters */
    public static function make(string $id, IncomingRequest $request, array $filters, bool $showTenant): DataTable
    {
        $canManage = service('adminSession')->can('billing.manage');
        $now = date('Y-m-d H:i:s');
        $state = static fn (array $r): string => $r['status'] === 'open' && $r['due_at'] < $now ? 'Overdue' : ucfirst((string) $r['status']);
        $cols = [
            Column::make('no', 'Invoice')->sortBy('i.issued_at')->searchIn('i.number', 'i.note')
                ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('billing/invoices/' . $r['id']), 'attr') . '" target="_blank" rel="noopener"><b>' . esc($r['number']) . '</b></a><br><small class="muted">' . esc(date_str($r['issued_at'])) . ($r['note'] ? ' · ' . esc($r['note']) : '') . '</small>')
                ->csv(static fn (array $r): string => (string) $r['number']),
        ];
        if ($showTenant) {
            $cols[] = Column::make('tenant', 'Tenant')->sortBy('t.name')->searchIn('t.name')
                ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['tenant_id'] . '?tab=invoices'), 'attr') . '">' . esc($r['tenant_name']) . '</a>')
                ->csv(static fn (array $r): string => (string) $r['tenant_name']);
        }
        array_push(
            $cols,
            Column::make('period', 'Period')->nowrap()->sortBy('i.period_from')
                ->cell(static fn (array $r): string => esc(date_str($r['period_from'])) . ' – ' . esc(date_str($r['period_to'])))
                ->csv(static fn (array $r): string => substr((string) $r['period_from'], 0, 10) . ' - ' . substr((string) $r['period_to'], 0, 10)),
            Column::make('plan', 'Plan')->sortBy('p.name')->cell(static fn (array $r): string => esc($r['plan_name']) . ' <small class="muted">' . esc($r['billing_cycle']) . '</small>')
                ->csv(static fn (array $r): string => (string) $r['plan_name']),
            Column::make('due', 'Due')->nowrap()->sortBy('i.due_at')->cell(static fn (array $r): string => esc(date_str($r['due_at'])))->csv(static fn (array $r): string => substr((string) $r['due_at'], 0, 10)),
            Column::make('total', 'Total')->num()->sortBy('i.total')->cell(static fn (array $r): string => '<b>' . esc(money($r['total'], ['decimals' => 0])) . '</b>')
                ->csv(static fn (array $r): string => (string) (float) $r['total']),
            Column::make('status', 'Status')->sortBy('i.status')
                ->cell(static function (array $r) use ($state): string {
                    $s = $state($r);
                    $kind = match ($s) { 'Paid' => 'ok', 'Overdue' => 'danger', 'Open' => 'warn', default => '' };

                    return tag_html($s, $kind) . ($r['paid_at'] ? '<br><small class="muted">' . esc((string) $r['pay_method']) . ' · ' . esc(date_str($r['paid_at'])) . '</small>' : '');
                })
                ->csv($state),
            Column::make('act', '')->num()->nowrap()->noCsv()
                ->cell(static fn (array $r): string => $r['status'] === 'open' && $canManage ? self::actions($r) : ''),
        );

        return DataTable::make($id, $request)
            ->from(InvoiceService::listQuery($filters))
            ->columns($cols)
            ->defaultSort('no', 'desc')
            ->perPage($showTenant ? 25 : 10)
            ->placeholder($showTenant ? 'Invoice no, tenant, note…' : 'Invoice no or note…')
            ->unit('invoices')
            ->emptyHtml('<span class="muted">No invoices.</span>')
            ->exportable('invoices');
    }

    /** Record-payment dialog + void form for one open invoice. */
    private static function actions(array $r): string
    {
        $id = (int) $r['id'];
        $opts = implode('', array_map(static fn (string $m): string => '<option>' . esc($m) . '</option>', InvoiceService::METHODS));

        return '<button class="btn sm primary" type="button" data-modal-open="pay-' . $id . '">Record payment</button> '
            . '<form class="inline" method="post" action="' . esc(site_url('billing/invoices/' . $id . '/void'), 'attr') . '" data-confirm="' . esc('Void invoice ' . $r['number'] . '?', 'attr') . '">' . csrf_field()
            . '<button class="btn sm danger" type="submit">Void</button></form>'
            . '<dialog class="modal-dlg sm" id="pay-' . $id . '"><form method="post" action="' . esc(site_url('billing/invoices/' . $id . '/pay'), 'attr') . '">' . csrf_field()
            . '<div class="modal-h"><h3>Record payment · ' . esc($r['number']) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>'
            . '<div class="modal-b"><div class="kv"><div><small>Amount</small><b>' . esc(money($r['total'], ['decimals' => 0])) . '</b></div><div><small>Period</small><b>' . esc(date_str($r['period_from'])) . ' – ' . esc(date_str($r['period_to'])) . '</b></div></div>'
            . '<label class="field">Method<select class="select" name="method">' . $opts . '</select></label>'
            . '<label class="field">Reference / transaction id<input class="input" name="reference" maxlength="64" placeholder="Optional"></label></div>'
            . '<div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">' . icon('check', 'sm') . 'Mark paid</button></div>'
            . '</form></dialog>';
    }
}
