<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Controllers\BaseController;
use App\Modules\BackOffice\Support\NavCounts;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderDocs;
use App\Modules\Orders\Services\OrderQueries;
use App\Modules\Orders\Services\PrintSettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class OrdersController extends BaseController
{
    private function queries(): OrderQueries
    {
        $s = service('posSession');

        return new OrderQueries($s->businessId(), $s->can('orders.all') ? null : $s->userId());
    }

    public function index(): string|ResponseInterface
    {
        $s = service('posSession');
        $cur = $s->currency();
        $f = [
            'status' => (string) ($this->request->getGet('status') ?? ''),
            'channel' => (string) ($this->request->getGet('channel') ?? ''),
            'table' => (string) ($this->request->getGet('table') ?? ''),
            'from' => (string) ($this->request->getGet('from') ?? ''),
            'to' => (string) ($this->request->getGet('to') ?? ''),
        ];
        try {
            \App\Modules\Invoices\Services\InvoiceSync::forCurrent()->syncAll();
        } catch (\Throwable) {
            // the list still works without the invoice numbers
        }
        $q = $this->queries();
        $code = (string) $s->business()['currency_code'];
        $table = DataTable::make('orders', $this->request)
            ->from($q->listQuery($f))
            ->keep(array_filter($f))
            ->columns([
                Column::make('no', 'Invoice')->sortBy('o.number')->searchIn('o.doc_no', 'o.web_ref', 'inv.doc_no')
                    ->cell(static fn ($r) => '<a class="link" href="' . esc(site_url('orders/' . $r['id']), 'attr') . '"><b>' . esc($r['invoice_no'] ?: $r['doc_no']) . '</b></a>'
                        . ($r['invoice_no'] ? '<br><small class="muted">Sale ' . esc($r['doc_no']) . '</small>' : '') . ($r['web_ref'] ? '<br><small class="muted">' . esc($r['web_ref']) . '</small>' : ''))
                    ->csv(static fn ($r) => (string) ($r['invoice_no'] ?: $r['doc_no'])),
                Column::make('at', 'Date')->nowrap()->sortBy('o.created_at')
                    ->cell(static fn ($r) => esc(date_str($r['created_at'])) . '<br><small class="muted">' . esc(time_str($r['created_at'])) . '</small>')->csv(static fn ($r) => (string) $r['created_at']),
                Column::make('type', 'Type')->sortBy('o.order_type')
                    ->cell(static fn ($r) => OrderQueries::source($r) === 'table'
                        ? '<span class="tag ok">' . icon('qr', 'sm') . esc($r['table_ref']) . '</span> ' . esc($r['order_type'] ?: '—') . '<br><small class="muted">Table QR order</small>'
                        : ($r['channel'] !== 'pos' ? '<span class="tag primary">' . icon('globe', 'sm') . esc(OrderQueries::CHANNELS[$r['channel']] ?? $r['channel']) . '</span> ' : '') . esc($r['order_type'] ?: '—') . ($r['table_ref'] ? '<br><small class="muted">' . esc($r['table_ref']) . '</small>' : ''))
                    ->csv(static fn ($r) => trim(OrderQueries::sourceLabel($r) . ' ' . $r['order_type'])),
                Column::make('customer', 'Customer')->sortBy('o.customer_name')->searchIn('o.customer_name', 'o.customer_phone')
                    ->cell(static fn ($r) => esc($r['customer_name']) . ($r['customer_phone'] ? '<br><small class="muted">' . esc($r['customer_phone']) . '</small>' : ''))
                    ->csv(static fn ($r) => trim($r['customer_name'] . ' ' . $r['customer_phone'])),
                Column::make('items', 'Items')->num()->sortBy('o.item_count')->cell(static fn ($r) => esc(qty($r['item_count'])))->csv(static fn ($r) => qty($r['item_count'])),
                Column::make('total', 'Total')->num()->sortBy('o.total')->cell(static fn ($r) => '<b>' . esc(money($r['total'], $cur)) . '</b>')->csv(static fn ($r) => (string) $r['total']),
                Column::make('due', 'Due')->num()->sortBy('o.due_total')
                    ->cell(static fn ($r) => (float) $r['due_total'] > 0 ? '<b style="color:var(--danger)">' . esc(money($r['due_total'], $cur)) . '</b>' : '<span class="muted">—</span>')->csv(static fn ($r) => (string) $r['due_total']),
                Column::make('pay', 'Payment')->cell(static fn ($r) => $r['methods'] ? esc(implode(' + ', array_map(static fn ($m) => pay_label($m, $code), explode(',', (string) $r['methods'])))) : '<span class="muted">—</span>')
                    ->csv(static fn ($r) => (string) $r['methods']),
                Column::make('status', 'Status')->sortBy('o.status')
                    ->cell(static fn ($r) => order_status_tag($r) . ($r['kitchen_status'] !== 'none' && $r['kitchen_status'] !== 'served' ? ' <span class="tag">' . esc($r['kitchen_status']) . '</span>' : '') . ($r['ship_status'] ? ' <span class="tag">' . esc(ship_label($r['ship_status'])) . '</span>' : ''))
                    ->csv(static fn ($r) => (string) $r['status']),
                Column::make('act', '')->cls('num nw')->noCsv()
                    ->cell(static fn ($r) => '<a class="btn sm" href="' . esc(site_url('orders/' . $r['id']), 'attr') . '">View</a>' . ((float) $r['due_total'] > 0 && $r['status'] !== 'cancelled' ? ' <a class="btn sm primary" href="' . esc(site_url('orders/' . $r['id']) . '#pay', 'attr') . '">Pay</a>' : '')),
            ])
            ->defaultSort('at', 'desc')
            ->placeholder('Invoice #, customer, phone…')
            ->unit('invoices')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('receipt') . '</span><b>No orders</b><span>Orders from the POS, website and marketplace appear here.</span></div>')
            ->exportable('orders');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Orders\Views\index', [
            'title' => terms_text('{orders} & {invoice}s'), 'table' => $table->render(), 'filters' => $f, 'counts' => $q->counts($f), 'tables' => $q->tables(),
        ]);
    }

    /** The invoice of a sale (created when missing), or null for an order that has none (open, cancelled, other channels). */
    private function saleInvoice(array $o): ?array
    {
        try {
            $iid = \App\Modules\Invoices\Services\InvoiceSync::forCurrent()->ensure((int) $o['id']);

            return $iid !== null ? \App\Modules\Invoices\Services\InvoiceService::forCurrent()->find($iid) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [order, docs] */
    private function load(int $id): array
    {
        $o = service('orders')->find($id);
        if ($o['status'] === 'held' || ! $this->queries()->visible($o)) {
            throw new NotFoundException('Order not found.');
        }

        return [$o, (new OrderDocs())->build($o, service('posSession')->business())];
    }

    public function show(int $id): string
    {
        [$o, $d] = $this->load($id);
        $b = service('posSession')->business();
        NavCounts::acknowledge($o);
        $invoice = null;
        if (service('posSession')->can('invoices.view')) {
            try {
                $iid = \App\Modules\Invoices\Services\InvoiceSync::forCurrent()->ensure((int) $o['id']);
                $invoice = $iid !== null ? db_connect()->table('invoices')->select('id, doc_no')->where('id', $iid)->get()->getRowArray() : null;
            } catch (\Throwable) {
                $invoice = null;
            }
        }

        // the real invoice (same design as /invoices/N) is what the page previews on A4
        $idoc = null;
        if ($invoice !== null) {
            try {
                $inv = \App\Modules\Invoices\Services\InvoiceService::forCurrent()->find((int) $invoice['id']);
                $idoc = $inv !== null ? \App\Modules\Invoices\Services\InvoiceDocs::build($inv, $b) : null;
            } catch (\Throwable) {
                $idoc = null;
            }
        }

        return $this->page('App\Modules\Orders\Views\show', [
            'invoice' => $invoice, 'idoc' => $idoc,
            'title' => term('order') . ' ' . $o['doc_no'], 'o' => $o, 'd' => $d, 'size' => PrintSettings::isPage(PrintSettings::for($b, 'invoice')['size']) ? 'a4' : '80',
            'print' => PrintSettings::all($b),
            'place' => \App\Modules\Orders\Services\OrderLocation::for($o),
            'methods' => array_values(array_filter((array) ($b['settings']['payments'] ?? ['cash']), static fn ($m) => $m !== 'credit')),
        ]);
    }

    /**
     * Print a document of the order. ?doc=receipt|invoice|challan|kot picks the document (older
     * links: ?mode=invoice|challan|kot); its paper size and copies come from Setup › Invoice
     * (PrintSettings) unless ?size=58|80|a4|a5 / ?copies=N say otherwise.
     */
    public function print(int $id): string|ResponseInterface
    {
        [$o, $d] = $this->load($id);
        $b = service('posSession')->business();
        $mode = (string) ($this->request->getGet('mode') ?? '');
        $doc = (string) ($this->request->getGet('doc') ?? '');
        if (! isset(PrintSettings::DOCS[$doc])) {
            $doc = match ($mode) { 'kot' => 'kot', 'challan' => 'challan', default => $this->request->getGet('size') !== null && PrintSettings::isPage((string) $this->request->getGet('size')) ? 'invoice' : 'receipt' };
        }
        $set = PrintSettings::for($b, $doc);
        $size = (string) ($this->request->getGet('size') ?? $set['size']);
        $size = in_array($size, PrintSettings::DOCS[$doc]['sizes'], true) ? $size : $set['size'];
        $copies = max(1, min(PrintSettings::MAX_COPIES, (int) ($this->request->getGet('copies') ?? $set['copies'])));

        $title = $o['doc_no'];
        $primary = $d['primary'];
        if ($doc === 'kot') {
            $html = view('App\Modules\Orders\Views\docs\kot', ['d' => $d]);
        } elseif ($doc === 'invoice' && ($inv = $this->saleInvoice($o)) !== null) {
            // the sale's invoice (POS-00029 …): the same professional design as every invoice
            $idoc = \App\Modules\Invoices\Services\InvoiceDocs::build($inv, $b);
            $html = PrintSettings::isPage($size)
                ? view('App\Modules\Invoices\Views\docs\invoice_a4', ['d' => $idoc])
                : view('App\Modules\Invoices\Views\docs\invoice_receipt', ['d' => $idoc, 'width' => (int) $size]);
            $title = (string) $inv['doc_no'];
            $primary = $idoc['primary'];
        } elseif (PrintSettings::isPage($size)) {
            $html = view('App\Modules\Orders\Views\docs\a4', ['d' => $d, 'mode' => $doc === 'challan' ? 'challan' : 'invoice']);
        } else {
            $html = view('App\Modules\Orders\Views\docs\receipt', ['d' => $d, 'mode' => $doc === 'challan' ? 'challan' : 'invoice', 'width' => (int) $size]);
        }
        if ($copies > 1) {
            // one copy per page / per cut; each marked so the copies can be told apart
            $parts = [];
            for ($i = 1; $i <= $copies; $i++) {
                $parts[] = '<div class="print-copy" data-copy="' . $i . '"><div class="copy-mark">Copy ' . $i . ' of ' . $copies . '</div>' . $html . '</div>';
            }
            $html = implode('', $parts);
        }
        $kind = PrintSettings::isPage($size) ? $size : 'receipt';

        // ?fragment=1: only the document markup, printed in place by assets/pos/print.js (which reads
        // the paper from these headers)
        if ((string) $this->request->getGet('fragment') === '1') {
            return $this->response->setBody($html)->setHeader('X-Print-Kind', $kind)->setHeader('X-Print-Size', $size)->setHeader('Cache-Control', 'no-store');
        }

        return view('App\Views\print_layout', ['title' => $title, 'content' => $html, 'kind' => $kind, 'width' => (int) $size, 'primary' => $primary]);
    }

    public function pay(int $id): RedirectResponse|ResponseInterface
    {
        $s = service('posSession');
        if (! $s->can('pos.sell') && ! $s->can('customers.receive')) {
            throw new PermissionDeniedException('Your role cannot take payments.');
        }
        $this->load($id);
        $in = $this->input();
        $payments = isset($in['payments']) && is_array($in['payments']) ? $in['payments']
            : [['method' => (string) ($in['method'] ?? 'cash'), 'amount' => (float) ($in['amount'] ?? 0), 'reference' => (string) ($in['reference'] ?? '')]];
        $r = (new OrderActions())->pay($id, $payments);
        if ($this->request->is('json')) {
            return $this->json(['status' => $r['order']['status'], 'due' => (float) $r['order']['due_total'], 'change' => $r['change']]);
        }
        $msg = 'Payment recorded.' . ($r['change'] > 0 ? ' Change: ' . money($r['change'], $s->currency()) . '.' : '');

        return redirect()->to(site_url('orders/' . $id))->with('success', $msg);
    }

    public function returnItems(int $id): RedirectResponse
    {
        $this->load($id);
        $in = $this->input();
        $r = (new OrderActions())->returnItems($id, (array) ($in['qty'] ?? []), (string) ($in['reason'] ?? ''), (string) ($in['refund_as'] ?? 'refund'));
        $cur = service('posSession')->currency();
        $msg = $r['method'] === 'exchange' ? 'Returned · ' . money($r['amount'], $cur) . ' added to the customer\'s exchange credit. Stock restored.' : ($r['method'] === 'credit' ? 'Returned · ' . money($r['amount'], $cur) . ' taken off the customer\'s due. Stock restored.' : 'Refunded ' . money($r['amount'], $cur) . ' · stock restored.');

        return redirect()->to(site_url('orders/' . $id))->with('success', $msg);
    }

    public function ship(int $id): RedirectResponse
    {
        $this->load($id);
        $data = $this->validated(['ship_status' => 'required|in_list[' . implode(',', OrderActions::SHIP) . ']', 'collect' => 'permit_empty|in_list[0,1]']);
        (new OrderActions())->shipStatus($id, $data['ship_status'], ($data['collect'] ?? '') === '1');

        return redirect()->to(site_url('orders/' . $id))->with('success', 'Delivery status: ' . ship_label($data['ship_status']) . '.');
    }
}
