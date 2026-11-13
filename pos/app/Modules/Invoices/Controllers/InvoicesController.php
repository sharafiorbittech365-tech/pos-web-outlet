<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Controllers\BaseController;
use App\Libraries\Mailer;
use App\Modules\Invoices\Services\InvoiceDocs;
use App\Modules\Invoices\Services\InvoicePdf;
use App\Modules\Invoices\Services\InvoiceQueries;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettings;
use App\Modules\Invoices\Services\InvoiceSync;
use App\Modules\Invoices\Services\MushakSettings;
use App\Modules\Orders\Services\PrintSettings;
use App\Modules\Settings\Services\SmtpSettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\OmniException;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Invoices: list, editor (draft / issued), issue, payments, void, print. */
final class InvoicesController extends BaseController
{
    /** @var list<string> */
    protected $helpers = ['url', 'form', 'ui', 'pos', 'invoice'];

    public function index(): string|ResponseInterface
    {
        $s = service('posSession');
        $cur = $s->currency();
        InvoiceSync::forCurrent()->syncAll();
        $f = [
            'state' => (string) ($this->request->getGet('state') ?? ''),
            'src' => (string) ($this->request->getGet('src') ?? ''),
            'table' => (string) ($this->request->getGet('table') ?? ''),
            'from' => (string) ($this->request->getGet('from') ?? ''),
            'to' => (string) ($this->request->getGet('to') ?? ''),
        ];
        $q = InvoiceQueries::forCurrent();
        $table = DataTable::make('invoices', $this->request)
            ->from($q->listQuery($f))
            ->keep(array_filter($f))
            ->columns([
                Column::make('no', 'Invoice')->sortBy('i.id')->searchIn('i.doc_no')
                    ->cell(static fn ($r) => '<a class="link" href="' . esc(site_url('invoices/' . $r['id']), 'attr') . '"><b>' . esc($r['doc_no'] ?? ('Draft #' . $r['id'])) . '</b></a>' . ($r['mushak_no'] ? '<br><small class="muted">Mushak ' . esc($r['mushak_no']) . '</small>' : ''))
                    ->csv(static fn ($r) => (string) ($r['doc_no'] ?? 'Draft #' . $r['id'])),
                Column::make('src', 'Source')->sortBy('o.channel')
                    ->cell(static fn ($r) => invoice_source_tag(InvoiceSync::sourceOf($r['channel'] ?? null, $r['table_ref'] ?? null), (string) ($r['table_ref'] ?? '')))
                    ->csv(static fn ($r) => InvoiceSync::SOURCES[InvoiceSync::sourceOf($r['channel'] ?? null, $r['table_ref'] ?? null)][0] . (! empty($r['table_ref']) ? ' · ' . $r['table_ref'] : '')),
                Column::make('at', 'Date')->nowrap()->sortBy('i.created_at')
                    ->cell(static fn ($r) => esc(date_str($r['issued_at'] ?? $r['created_at'])) . ($r['due_date'] ? '<br><small class="muted">Due ' . esc(date_str($r['due_date'])) . '</small>' : ''))
                    ->csv(static fn ($r) => (string) ($r['issued_at'] ?? $r['created_at'])),
                Column::make('customer', 'Customer')->sortBy('i.bill_name')->searchIn('i.bill_name', 'i.bill_phone')
                    ->cell(static fn ($r) => esc($r['bill_name']) . ($r['bill_phone'] ? '<br><small class="muted">' . esc($r['bill_phone']) . '</small>' : ''))
                    ->csv(static fn ($r) => trim($r['bill_name'] . ' ' . $r['bill_phone'])),
                Column::make('total', 'Total')->num()->sortBy('i.total')->cell(static fn ($r) => '<b>' . esc(money($r['total'] ?? $r['draft_total'], $cur)) . '</b>')->csv(static fn ($r) => (string) ($r['total'] ?? $r['draft_total'])),
                Column::make('paid', 'Paid')->num()->cell(static fn ($r) => $r['paid_total'] !== null ? esc(money($r['paid_total'], $cur)) : '<span class="muted">—</span>')->csv(static fn ($r) => (string) ($r['paid_total'] ?? '')),
                Column::make('due', 'Due')->num()->sortBy('o.due_total')
                    ->cell(static fn ($r) => (float) ($r['due_total'] ?? 0) > 0 ? '<b style="color:var(--danger)">' . esc(money($r['due_total'], $cur)) . '</b>' : '<span class="muted">—</span>')->csv(static fn ($r) => (string) ($r['due_total'] ?? '')),
                Column::make('status', 'Status')->sortBy('i.status')
                    ->cell(static fn ($r) => invoice_state_tag(InvoiceService::state($r, $r['order_status'])))->csv(static fn ($r) => InvoiceService::state($r, $r['order_status'])),
                Column::make('act', '')->cls('num nw')->noCsv()
                    ->cell(static fn ($r) => '<a class="btn sm" href="' . esc(site_url('invoices/' . $r['id']), 'attr') . '">View</a>'),
            ])
            ->defaultSort('no', 'desc')
            ->placeholder('Invoice #, customer, phone…')
            ->unit('invoices')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('file-text') . '</span><b>No invoices yet</b><span>Create an invoice for products, services or any free line — no till needed.</span></div>')
            ->exportable('invoices');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Invoices\Views\index', [
            'title' => terms_text('{invoice}s'), 'table' => $table->render(), 'filters' => $f, 'counts' => $q->counts(), 'sources' => $q->sourceCounts(), 'tables' => $q->tables(), 'mushakOn' => MushakSettings::forCurrent()->enabled() && service('posSession')->access()->hasFeature('invoicing.mushak'),
        ]);
    }

    public function create(): string
    {
        $page = InvoiceSettings::forCurrent();
        $cfg = $page->get();
        $inv = ['id' => 0, 'status' => 'draft', 'state' => 'draft', 'customer_id' => null, 'bill_name' => '', 'bill_phone' => '', 'bill_email' => '', 'bill_address' => '', 'bill_bin' => '',
            'ship_to' => '', 'reference' => '', 'due_date' => '', 'notes' => $cfg['notes'], 'terms' => $cfg['terms'], 'data' => [], 'order' => null, 'doc_no' => null];

        $old = old('lines');

        return $this->editor($inv, is_array($old) ? InvoiceService::forCurrent()->editorLines($inv, $old) : [], 'New invoice', $page->nextNumber());
    }

    public function edit(int $id): string|RedirectResponse
    {
        $svc = InvoiceService::forCurrent();
        $inv = $svc->find($id);
        if (! in_array($inv['state'], ['draft', 'due', 'partial', 'paid'], true)) {
            return redirect()->to(site_url('invoices/' . $id))->with('error', 'A ' . strtolower(InvoiceService::STATES[$inv['state']] ?? $inv['state']) . ' invoice cannot be edited.');
        }
        if ($inv['mushak'] !== null) {
            return redirect()->to(site_url('invoices/' . $id))->with('error', 'A Mushak-6.3 was issued for this invoice — it can no longer be changed.');
        }

        $old = old('lines');

        return $this->editor($inv, $svc->editorLines($inv, is_array($old) ? $old : null), ($inv['doc_no'] ?? 'Draft #' . $id) . ' · edit', null);
    }

    private function editor(array $inv, array $lines, string $title, ?string $nextNo): string
    {
        $svc = InvoiceService::forCurrent();
        $s = service('posSession');
        $b = $s->business();
        $o = $inv['order'];
        $page = InvoiceSettings::forCurrent();
        $policy = \OmniPOS\Core\Pricing\TaxPolicy::fromBusiness($b);
        $features = (array) ($b['settings']['features'] ?? []);

        return $this->page('App\Modules\Invoices\Views\form', [
            'title' => $title, 'inv' => $inv, 'lines' => $lines, 'canPrice' => $svc->canEditPrice(), 'vat' => $svc->vatChoices(), 'methods' => $svc->methods(),
            'tiles' => $svc->paymentTiles(), 'nextNo' => $nextNo ?? ($inv['status'] === 'draft' ? $page->nextNumber() : null),
            'discount' => $o !== null ? ['type' => $o['discount_type'] === 'amount' ? 'fixed' : 'percent', 'value' => (float) $o['discount_value']] : (array) ($inv['data']['discount'] ?? ['type' => 'percent', 'value' => 0]),
            'cur' => $s->currency(), 'code' => (string) $b['currency_code'], 'business' => $b, 'pageCfg' => $page->get(),
            'images' => ['header' => $page->layoutImage('header'), 'footer' => $page->layoutImage('footer')],
            'invoiceDate' => (string) ($inv['data']['invoice_date'] ?? ($inv['issued_at'] ?? null ? substr((string) $inv['issued_at'], 0, 10) : date('Y-m-d'))),
            'canBackdate' => $s->can('pos.backdate'), 'canSettings' => $s->can('invoices.settings'),
            'nbr' => MushakSettings::forCurrent()->enabled(), 'mushakCfg' => MushakSettings::forCurrent()->get(),
            // field errors of the last save (lines.N.field …) — the editor marks the cells
            'errors' => (array) (session('errors') ?? []),
            // the live preview repeats OrderCalculator's rules in the browser; the server re-prices on save
            'calc' => [
                'decimals' => (int) ($b['currency_decimals'] ?? 2), 'symbol' => (string) ($b['currency_symbol'] ?? ''), 'inclusive' => (bool) $b['tax_inclusive'],
                'defaultRate' => (float) $b['tax_rate'], 'serviceCharge' => (float) $b['service_charge_pct'], 'roundTo' => (float) $b['round_to'],
                'discountAllowed' => (bool) ($features['discount'] ?? true), 'taxLabel' => (string) ($b['tax_label'] ?? 'VAT'), 'rates' => $policy->rates(),
            ],
        ]);
    }

    /** POST invoices: save a new draft, or (action=issue) save and issue it at once. */
    public function store(): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $svc = InvoiceService::forCurrent();
        $id = $svc->saveDraft(null, $in);
        if (($in['action'] ?? '') === 'issue') {
            return $this->issueNow($svc, $id, $in);
        }

        return $this->done('Draft saved.', site_url('invoices/' . $id));
    }

    /** POST invoices/:id — a draft is saved (action=issue also issues it); an issued invoice is revised. */
    public function update(int $id): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $svc = InvoiceService::forCurrent();
        $inv = $svc->find($id);
        if ($inv['status'] === 'draft') {
            $svc->saveDraft($id, $in);
            if (($in['action'] ?? '') === 'issue') {
                return $this->issueNow($svc, $id, $in);
            }

            return $this->done('Draft saved.', site_url('invoices/' . $id));
        }
        $svc->revise($id, $in);

        return $this->done('Invoice updated. The change is in its history.', site_url('invoices/' . $id));
    }

    public function issue(int $id): RedirectResponse
    {
        return $this->issueNow(InvoiceService::forCurrent(), $id, (array) $this->request->getPost());
    }

    private function issueNow(InvoiceService $svc, int $id, array $in): RedirectResponse
    {
        try {
            $inv = $svc->issue($id, [['method' => (string) ($in['pay_method'] ?? 'cash'), 'amount' => (float) ($in['pay_amount'] ?? 0), 'reference' => (string) ($in['pay_reference'] ?? '')]]);
        } catch (OmniException $e) {
            // the draft is kept; say why it was not issued
            return redirect()->to(site_url('invoices/' . $id))->with('error', 'Saved as a draft, not issued: ' . $e->getMessage());
        }

        return $this->done('Invoice ' . $inv['doc_no'] . ' issued.' . ($inv['mushak'] ? ' Mushak-6.3 ' . $inv['mushak']['doc_no'] . ' issued.' : ''), site_url('invoices/' . $id));
    }

    public function show(int $id): string
    {
        $svc = InvoiceService::forCurrent();
        $inv = $svc->find($id);
        $b = service('posSession')->business();

        return $this->page('App\Modules\Invoices\Views\show', [
            'title' => ($inv['doc_no'] ?? 'Draft #' . $id), 'inv' => $inv, 'd' => InvoiceDocs::build($inv, $b), 'revisions' => $svc->revisions($id),
            'methods' => $svc->methods(), 'print' => PrintSettings::all($b), 'mushakOn' => MushakSettings::forCurrent()->enabled() && service('posSession')->access()->hasFeature('invoicing.mushak'),
            'tiles' => $svc->paymentTiles(), 'payments' => $payments = $svc->paymentHistory($inv), 'timeline' => $svc->timeline($inv, null, $payments),
            'canBackdate' => service('posSession')->can('pos.backdate'),
        ]);
    }

    public function pay(int $id): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $r = InvoiceService::forCurrent()->pay($id, [['method' => (string) ($in['method'] ?? 'cash'), 'amount' => (float) ($in['amount'] ?? 0), 'reference' => (string) ($in['reference'] ?? '')]], (string) ($in['date'] ?? ''));

        return $this->done('Payment recorded.' . ($r['change'] > 0 ? ' Change: ' . money($r['change'], service('posSession')->currency()) . '.' : ''), site_url('invoices/' . $id));
    }

    public function void(int $id): RedirectResponse
    {
        InvoiceService::forCurrent()->void($id, (string) $this->request->getPost('reason'));

        return $this->done('Invoice voided.', site_url('invoices/' . $id));
    }

    /**
     * GET invoices/adjust?mode=up|down|target&unit=pct|amount&value=&round=none|1|5|10&lines[i][unit_price|qty|discount_type|discount_value]
     * — the bulk "Adjust prices" tool (invoices.edit_price): new unit prices, applied in the editor and re-priced on save.
     */
    public function adjust(): ResponseInterface
    {
        $in = (array) $this->request->getGet();
        $rows = InvoiceService::adjustPrices(array_values(array_filter((array) ($in['lines'] ?? []), 'is_array')), (string) ($in['mode'] ?? ''), (string) ($in['unit'] ?? 'pct'),
            (float) ($in['value'] ?? 0), (string) ($in['round'] ?? 'none'), (int) (service('posSession')->business()['currency_decimals'] ?? 2));

        return $this->json($rows);
    }

    /** GET invoices/quote?… — live totals of the editor (OrderCalculator; nothing is saved). */
    public function quote(): ResponseInterface
    {
        $svc = InvoiceService::forCurrent();
        $in = (array) $this->request->getGet();
        $id = (int) ($in['invoice_id'] ?? 0);
        $existing = [];
        $disc = ['type' => 'percent', 'value' => 0];
        if ($id > 0) {
            $inv = $svc->find($id);
            $existing = $inv['order'] !== null ? InvoiceService::orderLines($inv['order']) : (array) ($inv['data']['lines'] ?? []);
            $disc = $inv['order'] !== null ? ['type' => $inv['order']['discount_type'] === 'amount' ? 'fixed' : 'percent', 'value' => (float) $inv['order']['discount_value']] : (array) ($inv['data']['discount'] ?? $disc);
        }
        try {
            $q = $svc->quote($svc->normalize($in, $existing, $disc));
        } catch (\OmniPOS\Core\Exceptions\ValidationException $e) {
            return $this->json(['error' => $e->getMessage(), 'lines' => []]);
        }
        $cur = service('posSession')->currency();
        $fmt = static fn ($n): string => money($n, $cur);

        return $this->json($q + ['fmt' => [
            'subtotal' => $fmt($q['subtotal']), 'discount' => $fmt($q['discount']), 'service' => $fmt($q['service']), 'tax' => $fmt($q['tax']), 'round_off' => $fmt($q['round_off']), 'total' => $fmt($q['total']),
            'lines' => array_map(static fn ($l) => $fmt($l['amount']), $q['lines']),
        ]]);
    }

    public function products(): ResponseInterface
    {
        return $this->json(InvoiceService::forCurrent()->products((string) ($this->request->getGet('q') ?? '')));
    }

    public function catalog(): ResponseInterface
    {
        return $this->json(InvoiceService::forCurrent()->catalog());
    }

    public function customers(): ResponseInterface
    {
        return $this->json(InvoiceService::forCurrent()->customers((string) ($this->request->getGet('q') ?? '')));
    }

    /** GET invoices/{id}/pdf — the invoice as an A4 PDF file, downloaded straight away (?inline=1 shows it in the browser). */
    public function pdf(int $id): ResponseInterface
    {
        $inv = InvoiceService::forCurrent()->find($id);
        $d = InvoiceDocs::build($inv, service('posSession')->business());
        $bytes = InvoicePdf::render($d);
        $disp = (string) $this->request->getGet('inline') === '1' ? 'inline' : 'attachment';

        return service('response')->setContentType('application/pdf')->setHeader('Content-Disposition', $disp . '; filename="' . InvoicePdf::fileName($inv) . '"')
            ->setHeader('Cache-Control', 'no-store')->setBody($bytes);
    }

    /** POST invoices/{id}/email — e-mails the invoice PDF through the outlet's SMTP (Setup › Email). */
    public function email(int $id): RedirectResponse
    {
        $back = site_url('invoices/' . $id);
        if (! SmtpSettings::configured()) {
            return redirect()->to($back)->with('error', 'Email is not set up yet — add the SMTP details in Setup › Email.');
        }
        $in = (array) $this->request->getPost();
        $to = trim((string) ($in['to'] ?? ''));
        $inv = InvoiceService::forCurrent()->find($id);
        $b = service('posSession')->business();
        $no = (string) ($inv['doc_no'] ?? '') !== '' ? (string) $inv['doc_no'] : 'Draft #' . $id;
        $subject = mb_substr(trim((string) ($in['subject'] ?? '')) ?: 'Invoice ' . $no . ' from ' . $b['name'], 0, 190);
        $note = mb_substr(trim((string) ($in['message'] ?? '')), 0, 2000);
        $d = InvoiceDocs::build($inv, $b);
        $o = $d['order'];
        $body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1f2937;line-height:1.55">'
            . '<p>Dear ' . esc((string) ($inv['bill_name'] ?: 'customer')) . ',</p>'
            . ($note !== '' ? '<p>' . nl2br(esc($note)) . '</p>' : '<p>Please find your invoice <b>' . esc($no) . '</b> attached.</p>')
            . '<table style="border-collapse:collapse;margin:12px 0"><tr><td style="padding:4px 18px 4px 0;color:#6b7280">Invoice</td><td><b>' . esc($no) . '</b></td></tr>'
            . '<tr><td style="padding:4px 18px 4px 0;color:#6b7280">Total</td><td><b>' . esc(money($o['total'], $d['cur'])) . '</b></td></tr>'
            . ((float) ($o['due_total'] ?? 0) > 0 && ! $d['draft'] ? '<tr><td style="padding:4px 18px 4px 0;color:#6b7280">Balance due</td><td><b>' . esc(money($o['due_total'], $d['cur'])) . '</b></td></tr>' : '')
            . '</table><p>Thank you,<br>' . esc((string) $b['name']) . (! empty($b['phone']) ? '<br>' . esc((string) $b['phone']) : '') . '</p></div>';
        $r = Mailer::send($to, $subject, $body, ['name' => InvoicePdf::fileName($inv), 'data' => InvoicePdf::render($d), 'mime' => 'application/pdf']);
        if (! $r['ok']) {
            return redirect()->to($back)->with('error', 'Invoice not sent: ' . $r['error']);
        }
        service('audit')->activity('Invoice emailed', $no . ' → ' . $to);

        return $this->done('Invoice ' . $no . ' sent to ' . $to . '.', $back);
    }

    /**
     * Print an invoice through the print pipeline: paper and copies from Setup › Invoice › Printing
     * (document "invoice": A4 / A5 page with the invoice page design, 80 / 58 mm receipt) unless
     * ?size / ?copies say otherwise; ?fragment=1 for in-place printing (assets/pos/print.js).
     */
    public function print(int $id): string|ResponseInterface
    {
        $inv = InvoiceService::forCurrent()->find($id);
        $b = service('posSession')->business();
        $d = InvoiceDocs::build($inv, $b);
        $set = PrintSettings::for($b, 'invoice');
        $size = (string) ($this->request->getGet('size') ?? $set['size']);
        $size = in_array($size, PrintSettings::DOCS['invoice']['sizes'], true) ? $size : $set['size'];
        $copies = max(1, min(PrintSettings::MAX_COPIES, (int) ($this->request->getGet('copies') ?? $set['copies'])));
        $html = PrintSettings::isPage($size)
            ? view('App\Modules\Invoices\Views\docs\invoice_a4', ['d' => $d])
            : view('App\Modules\Invoices\Views\docs\invoice_receipt', ['d' => $d, 'width' => (int) $size]);

        if ((string) $this->request->getGet('embed') === '1') {
            // the document viewer on the invoice page (iframe): one copy, no print toolbar
            return view('App\Modules\Invoices\Views\docs\embed', ['title' => (string) ($inv['doc_no'] ?? 'Draft #' . $id), 'content' => $html, 'kind' => PrintSettings::isPage($size) ? $size : 'receipt', 'primary' => $d['primary']]);
        }

        return self::output($html, $size, $copies, (string) ($inv['doc_no'] ?? 'Draft #' . $id), $d['primary']);
    }

    /** Copies, fragment (in-place printing) or the print page — shared with MushakController. */
    public static function output(string $html, string $size, int $copies, string $title, string $primary, array $labels = []): string|ResponseInterface
    {
        if ($copies > 1) {
            $parts = [];
            for ($i = 1; $i <= $copies; $i++) {
                $parts[] = '<div class="print-copy" data-copy="' . $i . '"><div class="copy-mark">' . esc($labels[$i - 1] ?? ('Copy ' . $i . ' of ' . $copies)) . '</div>' . $html . '</div>';
            }
            $html = implode('', $parts);
        } elseif ($labels !== []) {
            $html = '<div class="print-copy" data-copy="1"><div class="copy-mark">' . esc($labels[0]) . '</div>' . $html . '</div>';
        }
        $kind = PrintSettings::isPage($size) ? $size : 'receipt';
        $req = service('request');
        if ((string) $req->getGet('fragment') === '1') {
            return service('response')->setBody($html)->setHeader('X-Print-Kind', $kind)->setHeader('X-Print-Size', $size)->setHeader('Cache-Control', 'no-store');
        }

        return view('App\Views\print_layout', ['title' => $title, 'content' => $html, 'kind' => $kind, 'width' => (int) $size, 'primary' => $primary]);
    }
}
