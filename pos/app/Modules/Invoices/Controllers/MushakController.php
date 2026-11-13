<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Controllers\BaseController;
use App\Modules\Invoices\Services\FiscalYear;
use App\Modules\Invoices\Services\InvoiceQueries;
use App\Modules\Invoices\Services\MushakService;
use App\Modules\Invoices\Services\MushakSettings;
use App\Modules\Invoices\Services\SettingChanges;
use App\Modules\Orders\Services\PrintSettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** NBR Mushak-6.3: register (per fiscal year), settings, issue for a sale, print (A4 / 80 mm). */
final class MushakController extends BaseController
{
    /** @var list<string> */
    protected $helpers = ['url', 'form', 'ui', 'pos', 'invoice'];

    public function index(): string|ResponseInterface
    {
        $q = InvoiceQueries::forCurrent();
        $years = $q->mushakYears();
        $fy = (string) ($this->request->getGet('fy') ?? FiscalYear::label());
        $cur = service('posSession')->currency();
        $table = DataTable::make('mushak', $this->request)
            ->from($q->mushakQuery($fy))
            ->keep(['fy' => $fy])
            ->columns([
                Column::make('no', 'Mushak-6.3 no.')->sortBy('m.serial')->searchIn('m.doc_no')
                    ->cell(static fn ($r) => '<a class="link" href="' . esc(site_url('mushak/' . $r['id'] . '/print'), 'attr') . '" target="_blank" rel="noopener" data-preview><b>' . esc($r['doc_no']) . '</b></a>')->csv(static fn ($r) => (string) $r['doc_no']),
                Column::make('at', 'Issued')->nowrap()->sortBy('m.issued_at')->cell(static fn ($r) => esc(datetime_str($r['issued_at'])))->csv(static fn ($r) => (string) $r['issued_at']),
                Column::make('sale', 'Sale / invoice')->searchIn('o.doc_no')
                    ->cell(static fn ($r) => $r['invoice_id'] ? '<a class="link" href="' . esc(site_url('invoices/' . $r['invoice_id']), 'attr') . '">' . esc($r['sale_no']) . '</a>' : '<a class="link" href="' . esc(site_url('orders/' . $r['order_id']), 'attr') . '">' . esc($r['sale_no']) . '</a>')
                    ->csv(static fn ($r) => (string) $r['sale_no']),
                Column::make('buyer', 'Buyer')->sortBy('m.buyer_name')->searchIn('m.buyer_name', 'm.buyer_bin')
                    ->cell(static fn ($r) => esc($r['buyer_name']) . ($r['buyer_bin'] ? '<br><small class="muted">BIN ' . esc($r['buyer_bin']) . '</small>' : ''))->csv(static fn ($r) => trim($r['buyer_name'] . ' ' . $r['buyer_bin'])),
                Column::make('value', 'Value (excl. tax)')->num()->sortBy('m.value_total')->cell(static fn ($r) => esc(money($r['value_total'], $cur)))->csv(static fn ($r) => (string) $r['value_total']),
                Column::make('vat', 'VAT')->num()->sortBy('m.vat_total')->cell(static fn ($r) => esc(money($r['vat_total'], $cur)))->csv(static fn ($r) => (string) $r['vat_total']),
                Column::make('total', 'Total incl. tax')->num()->sortBy('m.grand_total')->cell(static fn ($r) => '<b>' . esc(money($r['grand_total'], $cur)) . '</b>')->csv(static fn ($r) => (string) $r['grand_total']),
                Column::make('by', 'By')->cell(static fn ($r) => esc($r['user_name'] ?? ''))->csv(static fn ($r) => (string) ($r['user_name'] ?? '')),
            ])
            ->defaultSort('no', 'desc')
            ->placeholder('Mushak no., buyer, BIN, sale no.…')
            ->unit('Mushak-6.3')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('file-text') . '</span><b>No Mushak-6.3 in FY ' . esc($fy) . '</b><span>Issue one from an invoice or an order.</span></div>')
            ->exportable('mushak-6.3-' . $fy);
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        if (! in_array($fy, $years, true) && FiscalYear::valid($fy)) {
            array_unshift($years, $fy);
        }

        return $this->page('App\Modules\Invoices\Views\mushak_index', ['title' => 'Mushak-6.3 register', 'table' => $table->render(), 'fy' => $fy, 'years' => $years, 'on' => MushakSettings::forCurrent()->enabled()]);
    }

    public function settings(): string
    {
        return $this->page('App\Modules\Invoices\Views\mushak_settings', ['title' => 'Mushak-6.3 settings', 'cfg' => MushakSettings::forCurrent()->get(), 'history' => SettingChanges::latest('mushak', 20)]);
    }

    public function saveSettings(): RedirectResponse
    {
        MushakSettings::forCurrent()->save((array) $this->request->getPost());

        return $this->done('Mushak-6.3 settings saved.', site_url('mushak/settings'));
    }

    /** Issue form for a sale (or straight to its Mushak-6.3 when it has one). */
    public function order(int $orderId): string|RedirectResponse
    {
        $svc = MushakService::forCurrent();
        $have = $svc->forOrder($orderId);
        if ($have !== null) {
            return redirect()->to(site_url('mushak/' . $have['id'] . '/print'));
        }
        $o = service('orders')->find($orderId);
        $inv = db_connect()->table('invoices')->select('id, bill_name, bill_bin, bill_address, ship_to')->where('business_id', service('posSession')->businessId())->where('order_id', $orderId)->get()->getRowArray();

        return $this->page('App\Modules\Invoices\Views\mushak_issue', ['title' => 'Issue Mushak-6.3', 'o' => $o, 'inv' => $inv, 'on' => MushakSettings::forCurrent()->enabled(), 'fy' => FiscalYear::label()]);
    }

    public function issue(int $orderId): RedirectResponse
    {
        $m = MushakService::forCurrent()->issue($orderId, (array) $this->request->getPost());
        $back = (string) ($this->request->getPost('back') ?? '');

        if ($back === 'print') {
            return redirect()->to(site_url('mushak/' . $m['id'] . '/print'));
        }

        return $this->done('Mushak-6.3 ' . $m['doc_no'] . ' issued.', preg_match('#^(invoices|orders)/\d+$#', $back) ? site_url($back) : site_url('mushak'));
    }

    /**
     * Print a Mushak-6.3: document "mushak" in Setup › Invoice › Printing (A4 or 80 mm, 2 copies by
     * default — the buyer's original and the supplier's copy); ?size=a4|80, ?copies=N, ?fragment=1.
     */
    public function print(int $id): string|ResponseInterface
    {
        $s = service('posSession');
        if (! $s->can('mushak.issue') && ! $s->can('invoices.view') && ! $s->can('orders.view')) {
            throw new PermissionDeniedException('Your role cannot print VAT invoices.');
        }
        $m = MushakService::forCurrent()->find($id);
        $b = $s->business();
        $set = PrintSettings::for($b, 'mushak');
        $size = (string) ($this->request->getGet('size') ?? $set['size']);
        $size = in_array($size, PrintSettings::DOCS['mushak']['sizes'], true) ? $size : $set['size'];
        $copies = max(1, min(PrintSettings::MAX_COPIES, (int) ($this->request->getGet('copies') ?? $set['copies'])));
        $html = view($size === 'a4' ? 'App\Modules\Invoices\Views\docs\mushak_a4' : 'App\Modules\Invoices\Views\docs\mushak_80', ['m' => $m, 'b' => $b]);
        $labels = [];
        for ($i = 1; $i <= $copies; $i++) {
            $labels[] = match ($i) { 1 => 'মূল কপি — Original (buyer)', 2 => 'দ্বিতীয় কপি — Duplicate (supplier)', default => 'Copy ' . $i . ' of ' . $copies };
        }

        return InvoicesController::output($html, $size, $copies, 'Mushak-6.3 ' . $m['doc_no'], (string) ($b['settings']['theme']['primary'] ?? '#4F46E5'), $labels);
    }
}
