<?php

declare(strict_types=1);

namespace App\Modules\Customers\Controllers;

use App\Controllers\BaseController;
use App\Modules\Customers\Services\CustomerService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class CustomersController extends BaseController
{
    private function svc(): CustomerService
    {
        return new CustomerService();
    }

    public function index(): string|ResponseInterface
    {
        $s = service('posSession');
        $cur = $s->currency();
        $filter = (string) ($this->request->getGet('cust_f') ?? '');
        $groups = (array) ($s->business()['settings']['priceGroups'] ?? []);
        $table = DataTable::make('cust', $this->request)
            ->from($this->svc()->listQuery($filter))
            ->keep(['cust_f' => $filter])
            ->columns([
                Column::make('name', 'Customer')->sortBy('c.name')->searchIn('c.name', 'c.phone')
                    ->cell(static fn ($r) => (bool) $r['is_walk_in'] ? '<b>' . esc($r['name']) . '</b> <span class="tag">Walk-in</span>' : '<a class="link" href="' . esc(site_url('customers/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a>'),
                Column::make('phone', 'Phone')->sortBy('c.phone')->cell(static fn ($r) => esc($r['phone'] ?: '—')),
                Column::make('group', 'Group')->sortBy('c.price_group')->cell(static fn ($r) => $r['price_group'] ? '<span class="tag primary">' . esc($r['price_group']) . '</span>' : '<span class="muted">—</span>')->csv(static fn ($r) => (string) $r['price_group']),
                Column::make('invoices', 'Invoices')->num()->cell(static fn ($r) => (string) (int) $r['invoices']),
                Column::make('due', 'Due')->num()->sortBy('c.balance_due')
                    ->cell(static fn ($r) => (float) $r['balance_due'] > 0 ? '<b style="color:var(--danger)">' . esc(money($r['balance_due'], $cur)) . '</b>' : '<span class="muted">—</span>')->csv(static fn ($r) => (string) $r['balance_due']),
                Column::make('limit', 'Credit limit')->num()->sortBy('c.credit_limit')
                    ->cell(static fn ($r) => (float) $r['credit_limit'] > 0 ? esc(money($r['credit_limit'], $cur)) . ((float) $r['balance_due'] > (float) $r['credit_limit'] * 0.8 ? ' <span class="tag warn">80%+</span>' : '') : '<span class="muted">—</span>')->csv(static fn ($r) => (string) $r['credit_limit']),
                Column::make('last', 'Last purchase')->nowrap()->cell(static fn ($r) => $r['last_at'] ? esc(date_str($r['last_at'])) : '<span class="muted">—</span>')->csv(static fn ($r) => (string) $r['last_at']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn ($r) => (bool) $r['is_walk_in'] ? '' : '<a class="btn sm" href="' . esc(site_url('customers/' . $r['id']), 'attr') . '">' . icon('book', 'sm') . 'Due book</a>'
                    . ((float) $r['balance_due'] > 0 && service('posSession')->can('customers.receive') ? ' <a class="btn sm primary" href="' . esc(site_url('customers/' . $r['id']) . '#receive', 'attr') . '">Receive</a>' : '')),
            ])
            ->defaultSort('due', 'desc')
            ->placeholder('Name or phone…')
            ->unit('customers')
            ->emptyHtml('<span class="muted">No customers match.</span>')
            ->exportable('customers');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Customers\Views\index', [
            'title' => terms_text('{customers} & dues'), 'table' => $table->render(), 'filter' => $filter, 'stats' => $this->svc()->stats(), 'groups' => $groups,
        ]);
    }

    public function create(): RedirectResponse|ResponseInterface
    {
        $c = $this->svc()->create($this->input());
        if ($this->request->is('json')) {
            return $this->json($c, [], 201);
        }

        return redirect()->to(site_url('customers/' . $c['id']))->with('success', 'Customer added.');
    }

    public function show(int $id): string|ResponseInterface
    {
        $s = service('posSession');
        $cur = $s->currency();
        $svc = $this->svc();
        $c = $svc->find($id);
        $code = (string) $s->business()['currency_code'];
        $table = DataTable::make('led', $this->request)
            ->from($svc->ledgerQuery($id))
            ->noSearch()
            ->columns([
                Column::make('at', 'Date')->nowrap()->sortBy('l.id')->cell(static fn ($r) => esc(datetime_str($r['created_at'])))->csv(static fn ($r) => (string) $r['created_at']),
                Column::make('what', 'Particulars')->cell(static function ($r) use ($code): string {
                    $label = match ($r['entry_type']) {
                        'sale' => 'Credit sale' . ($r['doc_no'] ? ' · <a class="link" href="' . esc(site_url('orders/' . $r['ref_id']), 'attr') . '">' . esc($r['doc_no']) . '</a>' : ''),
                        'payment' => 'Payment received' . ($r['method'] ? ' · ' . esc(pay_label($r['method'], $code)) : '') . ($r['doc_no'] ? ' · ' . esc($r['doc_no']) : ''),
                        'opening' => 'Previous due',
                        'return' => 'Return' . ($r['doc_no'] ? ' · ' . esc($r['doc_no']) : ''),
                        default => 'Adjustment',
                    };

                    return $label . ($r['note'] ? '<br><small class="muted">' . esc($r['note']) . '</small>' : '');
                })->csv(static fn ($r) => $r['entry_type'] . ' ' . $r['note']),
                Column::make('debit', 'Debit (due)')->num()->cell(static fn ($r) => (float) $r['amount'] > 0 ? esc(money($r['amount'], $cur)) : '')->csv(static fn ($r) => (float) $r['amount'] > 0 ? (string) $r['amount'] : ''),
                Column::make('credit', 'Credit (paid)')->num()->cell(static fn ($r) => (float) $r['amount'] < 0 ? esc(money(-(float) $r['amount'], $cur)) : '')->csv(static fn ($r) => (float) $r['amount'] < 0 ? (string) -(float) $r['amount'] : ''),
                Column::make('bal', 'Balance')->num()->cell(static fn ($r) => '<b>' . esc(money($r['balance_after'], $cur)) . '</b>')->csv(static fn ($r) => (string) $r['balance_after']),
            ])
            ->defaultSort('at', 'desc')
            ->unit('entries')
            ->emptyHtml('<span class="muted">No due-book entries yet.</span>')
            ->exportable('ledger-' . $id);
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        // exchange credit (value of returned items kept for the next invoice) — its own history, apart from the due book
        $exTable = DataTable::make('exl', $this->request)
            ->from(db_connect()->table('exchange_ledger l')->select('l.id, l.entry_type, l.amount, l.balance_after, l.ref_id, l.note, l.created_at, o.doc_no')
                ->join('orders o', "o.id = l.ref_id AND l.ref_type = 'order'", 'left')->where('l.business_id', $s->businessId())->where('l.customer_id', $id))
            ->noSearch()
            ->columns([
                Column::make('at', 'Date')->nowrap()->sortBy('l.id')->cell(static fn ($r) => esc(datetime_str($r['created_at']))),
                Column::make('what', 'Particulars')->cell(static function ($r): string {
                    $doc = $r['doc_no'] ? ' · <a class="link" href="' . esc(site_url('orders/' . $r['ref_id']), 'attr') . '">' . esc($r['doc_no']) . '</a>' : '';
                    $label = match ($r['entry_type']) { 'issue' => 'Credit from returned item' . $doc, 'redeem' => 'Used to pay invoice' . $doc, default => 'Adjustment' };

                    return $label . ($r['note'] ? '<br><small class="muted">' . esc($r['note']) . '</small>' : '');
                }),
                Column::make('in', 'Credit given')->num()->cell(static fn ($r) => (float) $r['amount'] > 0 ? esc(money($r['amount'], $cur)) : ''),
                Column::make('out', 'Used')->num()->cell(static fn ($r) => (float) $r['amount'] < 0 ? esc(money(-(float) $r['amount'], $cur)) : ''),
                Column::make('bal', 'Balance')->num()->cell(static fn ($r) => '<b>' . esc(money($r['balance_after'], $cur)) . '</b>'),
            ])->defaultSort('at', 'desc')->perPage(10)->unit('entries')->compact()->emptyHtml('<span class="muted">No exchange credit yet. It appears when a return is kept as exchange credit.</span>');
        $orders = db_connect()->table('orders')->select('COUNT(*) AS n, COALESCE(SUM(total), 0) AS v, COALESCE(SUM(paid_total), 0) AS paid, MAX(created_at) AS last_at')
            ->where('business_id', $s->businessId())->where('customer_id', $id)->whereNotIn('status', ['cancelled', 'held'])->get()->getRowArray();
        $lastPay = db_connect()->table('customer_ledger')->select('amount, created_at')->where('business_id', $s->businessId())->where('customer_id', $id)->where('entry_type', 'payment')
            ->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();

        return $this->page('App\Modules\Customers\Views\show', [
            'title' => $c['name'], 'c' => $c, 'table' => $table->render(), 'exTable' => $exTable->render(), 'open' => $svc->openInvoices($id), 'orders' => $orders, 'lastPay' => $lastPay,
            'methods' => array_values(array_filter((array) ($s->business()['settings']['payments'] ?? ['cash']), static fn ($m) => $m !== 'credit')),
            'groups' => (array) ($s->business()['settings']['priceGroups'] ?? []),
        ]);
    }

    public function update(int $id): RedirectResponse
    {
        $this->svc()->update($id, $this->input());

        return redirect()->to(site_url('customers/' . $id))->with('success', 'Customer saved.');
    }

    public function collect(int $id): RedirectResponse|ResponseInterface
    {
        $data = $this->validated(['amount' => 'required|decimal|greater_than[0]', 'method' => 'required|alpha_dash|max_length[24]', 'reference' => 'permit_empty|max_length[64]', 'note' => 'permit_empty|max_length[200]']);
        $r = $this->svc()->collect($id, (float) $data['amount'], (string) $data['method'], (string) ($data['reference'] ?? ''), (string) ($data['note'] ?? ''));
        $cur = service('posSession')->currency();
        if ($this->request->is('json')) {
            return $this->json(['collected' => $r['collected'], 'balance' => $r['balance'], 'orders' => $r['orders']]);
        }

        return redirect()->to(site_url('customers/' . $id))->with('success', 'Received ' . money($r['collected'], $cur) . ($r['orders'] ? ' · paid ' . implode(', ', array_column($r['orders'], 'doc_no')) : '') . ' · remaining due ' . money($r['balance'], $cur) . '.');
    }

    public function opening(int $id): RedirectResponse
    {
        $data = $this->validated(['amount' => 'required|decimal|greater_than[0]', 'note' => 'permit_empty|max_length[200]']);
        $bal = $this->svc()->opening($id, (float) $data['amount'], (string) ($data['note'] ?? ''));

        return redirect()->to(site_url('customers/' . $id))->with('success', 'Previous due added · balance ' . money($bal, service('posSession')->currency()) . '.');
    }

    public function statement(int $id): string
    {
        $s = service('posSession');
        $period = (string) ($this->request->getGet('period') ?? 'all');
        $c = $this->svc()->find($id);
        $b = $s->business();
        $html = view('App\Modules\Customers\Views\statement', ['c' => $c, 'b' => $b, 'st' => $this->svc()->statement($id, $period), 'cur' => $s->currency(), 'period' => $period]);

        return view('App\Views\print_layout', ['title' => 'Statement · ' . $c['name'], 'content' => $html, 'kind' => 'a4', 'primary' => (string) ($b['settings']['theme']['primary'] ?? '#4F46E5')]);
    }
}
