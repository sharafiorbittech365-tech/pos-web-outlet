<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Purchases\Services\PurchaseService;
use App\Modules\Purchases\Services\PurchaseSettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class PurchasesController extends BackOfficeController
{
    private const STATE = ['paid' => ['Paid', 'ok'], 'partial' => ['Partially paid', 'warn'], 'due' => ['Due (credit)', 'danger']];

    public function index(): string|ResponseInterface
    {
        $svc = new PurchaseService();
        $cur = $this->cur();
        $on = PurchaseSettings::all();
        $avail = array_keys(array_filter(['purchases' => $on['purchase'], 'suppliers' => $on['supplier'], 'payments' => $on['payment']]));
        $t = $this->getStr('tab', 12);
        $tab = in_array($t, $avail, true) ? $t : ($avail[0] ?? 'purchases');
        $range = DateRange::fromRequest($this->request, 'month');
        $status = $this->getStr('status', 8);
        $methods = PurchaseService::METHODS;

        if ($tab === 'suppliers') {
            $table = DataTable::make('sup', $this->request)->from($svc->suppliersQuery())->keep(['tab' => 'suppliers'])
                ->columns([
                    Column::make('name', 'Supplier')->sortBy('s.name')->searchIn('s.name', 's.phone', 's.address', 's.email')
                        ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('purchases/suppliers/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a><br><small class="muted">' . esc($r['phone'] ?? '—') . '</small>')
                        ->csv(static fn (array $r): string => (string) $r['name']),
                    Column::make('address', 'Address')->cell(static fn (array $r): string => '<small>' . esc($r['address'] ?? '—') . '</small>'),
                    Column::make('n', 'Purchases')->num()->sortBy('n')->cell(static fn (array $r): string => (string) (int) $r['n']),
                    Column::make('purchased', 'Total purchased')->num()->sortBy('purchased')->cell(static fn (array $r): string => esc(money($r['purchased'], $cur)))->csv(static fn (array $r): string => (string) $r['purchased']),
                    Column::make('last', 'Last purchase')->nowrap()->sortBy('last_at')->cell(static fn (array $r): string => esc(date_str($r['last_at'])))->csv(static fn (array $r): string => (string) $r['last_at']),
                    Column::make('due', 'Due now')->num()->sortBy('s.balance_due')->cell(static fn (array $r): string => (float) $r['balance_due'] > 0 ? '<b style="color:var(--danger)">' . esc(money($r['balance_due'], $cur)) . '</b>' : '<span class="tag ok">Clear</span>')->csv(static fn (array $r): string => (string) $r['balance_due']),
                    Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => '<a class="btn sm" href="' . esc(site_url('purchases/suppliers/' . $r['id']), 'attr') . '">' . icon('book', 'sm') . 'Ledger</a> <a class="btn sm" href="' . esc(site_url('purchases/new?supplier=' . $r['id']), 'attr') . '">' . icon('plus', 'sm') . 'Purchase</a>'),
                ])->defaultSort('due', 'desc')->placeholder('Name, phone, address…')->unit('suppliers')->exportable('suppliers')
                ->emptyHtml('<span class="muted">No suppliers yet. Add your first supplier.</span>');
        } elseif ($tab === 'payments') {
            $table = DataTable::make('spay', $this->request)->from($svc->paymentsQuery($range->from, $range->to))->keep(array_merge(['tab' => 'payments'], $range->params()))
                ->columns([
                    Column::make('at', 'Date')->nowrap()->sortBy('sp.paid_at')->cell(static fn (array $r): string => esc(datetime_str($r['paid_at'])))->csv(static fn (array $r): string => (string) $r['paid_at']),
                    Column::make('supplier', 'Supplier')->sortBy('s.name')->searchIn('s.name', 'sp.reference', 'p.reference')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('purchases/suppliers/' . $r['supplier_id']), 'attr') . '">' . esc($r['supplier']) . '</a>'),
                    Column::make('method', 'Method')->sortBy('sp.method')->cell(static fn (array $r): string => esc($methods[$r['method']] ?? $r['method']) . ($r['reference'] ? '<br><small class="muted">' . esc($r['reference']) . '</small>' : ''))->csv(static fn (array $r): string => $methods[$r['method']] ?? $r['method']),
                    Column::make('against', 'Against')->cell(static fn (array $r): string => $r['purchase_ref'] ? '<a class="link" href="' . esc(site_url('purchases/' . $r['purchase_id']), 'attr') . '">' . esc($r['purchase_ref']) . '</a>' : '<span class="muted">Balance</span>')->csv(static fn (array $r): string => (string) ($r['purchase_ref'] ?? '')),
                    Column::make('by', 'By')->cell(static fn (array $r): string => esc($r['user_name'] ?? '—'))->csv(static fn (array $r): string => (string) ($r['user_name'] ?? '')),
                    Column::make('amount', 'Paid')->num()->sortBy('sp.amount')->cell(static fn (array $r): string => '<b>' . esc(money($r['amount'], $cur)) . '</b>')->csv(static fn (array $r): string => (string) $r['amount']),
                ])->defaultSort('at', 'desc')->placeholder('Supplier, reference…')->unit('payments')->exportable('supplier-payments')
                ->emptyHtml('<span class="muted">No supplier payments in this period.</span>');
        } else {
            $table = DataTable::make('pur', $this->request)->from($svc->purchasesQuery(['status' => $status], $range->from, $range->to))->keep(array_merge($range->params(), ['status' => $status]))
                ->columns([
                    Column::make('ref', 'Purchase')->sortBy('p.id')->searchIn('p.reference', 'p.bill_no', 's.name')
                        ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('purchases/' . $r['id']), 'attr') . '"><b>' . esc($r['reference']) . '</b></a>' . ($r['bill_no'] ? '<br><small class="muted">Bill ' . esc($r['bill_no']) . '</small>' : ''))
                        ->csv(static fn (array $r): string => (string) $r['reference']),
                    Column::make('at', 'Date')->nowrap()->sortBy('p.purchased_at')->cell(static fn (array $r): string => esc(date_str($r['purchased_at'])))->csv(static fn (array $r): string => (string) $r['purchased_at']),
                    Column::make('supplier', 'Supplier')->sortBy('s.name')->cell(static fn (array $r): string => esc($r['supplier'])),
                    Column::make('items', 'Items')->cell(static fn (array $r): string => '<small>' . esc(mb_strimwidth((string) $r['items'], 0, 90, '…')) . '</small>')->csv(static fn (array $r): string => (string) $r['items']),
                    Column::make('total', 'Total')->num()->sortBy('p.total')->cell(static fn (array $r): string => '<b>' . esc(money($r['total'], $cur)) . '</b>')->csv(static fn (array $r): string => (string) $r['total']),
                    Column::make('paid', 'Paid')->num()->sortBy('p.paid')->cell(static fn (array $r): string => esc(money($r['paid'], $cur)))->csv(static fn (array $r): string => (string) $r['paid']),
                    Column::make('due', 'Due')->num()->sortBy('due')->cell(static fn (array $r): string => (float) $r['due'] > 0.009 ? '<b style="color:var(--danger)">' . esc(money($r['due'], $cur)) . '</b>' : '—')->csv(static fn (array $r): string => (string) $r['due']),
                    Column::make('status', 'Status')->sortBy('p.status')->cell(static fn (array $r): string => tag_html(...self::STATE[$r['status']]))->csv(static fn (array $r): string => self::STATE[$r['status']][0]),
                ])->defaultSort('at', 'desc')->placeholder('PO no, bill no, supplier…')->unit('purchases')->exportable('purchases')
                ->emptyHtml('<span class="muted">No purchases in this period.</span>');
        }
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Purchases\Views\index', [
            'title' => 'Purchases & ' . mb_strtolower(term('supplier')) . 's',
            'table' => $table->render(),
            'tab' => $tab,
            'range' => $range,
            'status' => $status,
            'stats' => $svc->stats($range->from, $range->to),
            'suppliers' => $svc->supplierOptions(),
            'methods' => PurchaseService::METHODS,
            'states' => self::STATE,
            'on' => $on,
            'tabs' => $avail,
            'stockLinked' => PurchaseSettings::stockLinked(),
        ]);
    }

    public function create(): string
    {
        $svc = new PurchaseService();

        return $this->page('App\Modules\Purchases\Views\form', [
            'title' => 'New purchase',
            'suppliers' => $svc->supplierOptions(),
            'products' => $svc->productOptions(),
            'supplierId' => $this->getInt('supplier'),
            'methods' => PurchaseService::METHODS,
            'cur' => $this->cur(),
            'stockLinked' => PurchaseSettings::stockLinked(),
            'canPay' => PurchaseSettings::on('payment'),
        ]);
    }

    public function store(): RedirectResponse
    {
        $id = (new PurchaseService())->createPurchase((array) $this->request->getPost());

        return $this->done('Purchase recorded.', site_url('purchases/' . $id));
    }

    public function show(int $id): string
    {
        return $this->page('App\Modules\Purchases\Views\show', ['title' => 'Purchase', 'p' => (new PurchaseService())->purchase($id), 'cur' => $this->cur(), 'methods' => PurchaseService::METHODS, 'states' => self::STATE]);
    }

    public function supplier(int $id): string
    {
        $svc = new PurchaseService();

        return $this->page('App\Modules\Purchases\Views\supplier', ['title' => term('supplier'), 's' => $svc->supplier($id), 'rows' => $svc->ledger($id), 'cur' => $this->cur(), 'methods' => PurchaseService::METHODS, 'canPay' => PurchaseSettings::on('payment'), 'canBuy' => PurchaseSettings::on('purchase')]);
    }

    public function storeSupplier(): RedirectResponse
    {
        $id = (new PurchaseService())->saveSupplier(null, (array) $this->request->getPost());

        return $this->done('Supplier added.', $this->request->getPost('next') === 'purchase' ? site_url('purchases/new?supplier=' . $id) : site_url('purchases?tab=suppliers'));
    }

    public function updateSupplier(int $id): RedirectResponse
    {
        (new PurchaseService())->saveSupplier($id, (array) $this->request->getPost());

        return $this->done('Supplier saved.', site_url('purchases/suppliers/' . $id));
    }

    public function deleteSupplier(int $id): RedirectResponse
    {
        (new PurchaseService())->deleteSupplier($id);

        return $this->done('Supplier deleted.', site_url('purchases?tab=suppliers'));
    }

    public function pay(): RedirectResponse
    {
        $d = $this->validated(['supplier_id' => 'required|is_natural_no_zero', 'amount' => 'required|numeric|greater_than[0]', 'method' => 'permit_empty|max_length[24]',
            'reference' => 'permit_empty|max_length[64]', 'paid_at' => 'permit_empty|valid_date[Y-m-d]']);
        $r = (new PurchaseService())->pay((int) $d['supplier_id'], (float) $d['amount'], (string) ($d['method'] ?? 'cash'), (string) ($d['reference'] ?? ''), (string) ($d['paid_at'] ?? ''));

        return $this->done('Payment of ' . money($r['amount'], $this->cur()) . ' recorded. Still owed: ' . money($r['after'], $this->cur()) . '.', site_url('purchases/suppliers/' . $d['supplier_id']));
    }
}
