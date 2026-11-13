<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Controllers\BaseController;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\InvoiceTable;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class BillingController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $status = $this->pick('status', InvoiceService::FILTERS) ?: 'open';
        $table = InvoiceTable::make('invoices', $this->request, ['status' => $status], true)->keep(['status' => $status]);
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Billing\Views\index', [
            'title' => 'Invoices & payments',
            'tab' => 'invoices',
            'status' => $status,
            'table' => $table->render(),
            'totals' => InvoiceService::totals(),
            'graceDays' => (int) service('catalog')->setting('graceDays', 7),
        ]);
    }

    public function payments(): string|ResponseInterface
    {
        $table = DataTable::make('payments', $this->request)
            ->from(InvoiceService::paymentsQuery())
            ->columns([
                Column::make('at', 'Paid')->nowrap()->sortBy('pp.paid_at')->cell(static fn (array $r): string => esc(datetime_str($r['paid_at'])))->csv(static fn (array $r): string => (string) $r['paid_at']),
                Column::make('tenant', 'Tenant')->sortBy('t.name')->searchIn('t.name')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('tenants/' . $r['tenant_id']), 'attr') . '">' . esc($r['tenant_name']) . '</a>')->csv(static fn (array $r): string => (string) $r['tenant_name']),
                Column::make('invoice', 'Invoice')->sortBy('i.number')->searchIn('i.number')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('billing/invoices/' . $r['invoice_id']), 'attr') . '" target="_blank" rel="noopener">' . esc($r['number']) . '</a>')->csv(static fn (array $r): string => (string) $r['number']),
                Column::make('method', 'Method')->sortBy('pp.method')->searchIn('pp.method', 'pp.reference')
                    ->cell(static fn (array $r): string => esc($r['method']) . ($r['reference'] ? '<br><small class="muted">' . esc($r['reference']) . '</small>' : ''))->csv(static fn (array $r): string => $r['method'] . ($r['reference'] ? ' ' . $r['reference'] : '')),
                Column::make('by', 'Recorded by')->sortBy('pp.recorded_by')->searchIn('pp.recorded_by')->cell(static fn (array $r): string => esc($r['recorded_by']))->csv(static fn (array $r): string => (string) $r['recorded_by']),
                Column::make('amount', 'Amount')->num()->sortBy('pp.amount')->cell(static fn (array $r): string => '<b>' . esc(money($r['amount'], ['decimals' => 0])) . '</b>')->csv(static fn (array $r): string => (string) (float) $r['amount']),
            ])
            ->defaultSort('at', 'desc')
            ->placeholder('Tenant, invoice, method, reference…')
            ->unit('payments')
            ->exportable('payments');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Billing\Views\index', [
            'title' => 'Invoices & payments',
            'tab' => 'payments',
            'status' => '',
            'table' => $table->render(),
            'totals' => InvoiceService::totals(),
            'graceDays' => (int) service('catalog')->setting('graceDays', 7),
        ]);
    }

    public function show(int $id): string
    {
        helper('ui');

        return view('App\Modules\Billing\Views\print', [
            'inv' => InvoiceService::find($id),
            'lines' => service('billing')->invoiceLines($id),
            'platform' => [
                'name' => (string) service('catalog')->setting('name', \OmniPOS\Ui\Brand::name() . ' Cloud'),
                'email' => (string) service('catalog')->setting('supportEmail', ''),
                'phone' => (string) service('catalog')->setting('supportPhone', ''),
            ],
        ]);
    }

    public function pay(int $id): RedirectResponse
    {
        $data = $this->validated([
            'method' => 'required|in_list[' . implode(',', InvoiceService::METHODS) . ']',
            'reference' => 'permit_empty|max_length[64]',
        ]);
        $sum = (new InvoiceService($this->actor()))->pay($id, $data['method'], trim((string) ($data['reference'] ?? '')));

        return $this->done('Payment of ' . money($sum, ['decimals' => 0]) . ' recorded.');
    }

    public function void(int $id): RedirectResponse
    {
        $inv = (new InvoiceService($this->actor()))->void($id);

        return $this->done('Invoice ' . $inv['number'] . ' voided.');
    }

    public function run(): RedirectResponse
    {
        $r = (new InvoiceService($this->actor()))->runBilling();

        return $this->done('Billing run finished: ' . $r['invoices'] . ' invoice(s) issued, ' . $r['changed'] . ' account(s) changed.', site_url('billing'));
    }
}
