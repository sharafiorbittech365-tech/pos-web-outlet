<?php

declare(strict_types=1);

namespace App\Modules\Pharmacy\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Pharmacy\Services\MedicineCatalog;
use App\Modules\Pharmacy\Services\PharmacyService;
use App\Modules\Pharmacy\Services\PharmacySettings;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Pharmacy › Alerts (near expiry, expired, low stock), settings, medicine database. */
final class PharmacyController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = PharmacyService::make();
        $s = $this->s();
        $cur = $this->cur();
        $view = array_key_exists($v = $this->getStr('view', 12), PharmacyService::VIEWS) ? $v : 'near';
        $manage = $s->can('inventory.manage');
        if ($view === 'low') {
            $table = DataTable::make('ph', $this->request)->from($svc->lowQuery())->keep(['view' => $view])
                ->columns([
                    Column::make('name', 'Product')->sortBy('p.name')->searchIn('p.name', 'p.generic_name')
                        ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('inventory/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a>' . ($r['generic_name'] ? '<br><small class="muted">' . esc($r['generic_name']) . '</small>' : ''))
                        ->csv(static fn (array $r): string => (string) $r['name']),
                    Column::make('stock', 'In stock')->num()->sortBy('p.stock_qty')->cell(static fn (array $r): string => '<b class="' . ((float) $r['stock_qty'] <= 0 ? 'neg' : '') . '">' . esc(qty($r['stock_qty'], $r['unit'] ?: null)) . '</b>')->csv(static fn (array $r): string => qty($r['stock_qty'])),
                    Column::make('low', 'Alert at')->num()->cell(static fn (array $r): string => esc(qty($r['low_at'])))->csv(static fn (array $r): string => qty($r['low_at'])),
                    Column::make('st', 'Status')->cell(static fn (array $r): string => (float) $r['stock_qty'] <= 0 ? tag_html('Out', 'danger') : tag_html('Low', 'warn'))->noCsv(),
                ])
                ->defaultSort('stock')->placeholder('Product or generic…')->unit('products')->exportable('low-stock')
                ->emptyHtml('<span class="muted">Nothing is low on stock.</span>');
        } else {
            $table = DataTable::make('ph', $this->request)->from($svc->batchesQuery($view))->keep(['view' => $view])
                ->columns(array_values(array_filter([
                    Column::make('product', 'Product')->sortBy('p.name')->searchIn('p.name', 'p.generic_name', 'b.batch_no')
                        ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('inventory/' . $r['product_id']), 'attr') . '"><b>' . esc($r['product']) . '</b></a>' . ($r['generic_name'] ? '<br><small class="muted">' . esc($r['generic_name']) . '</small>' : ''))
                        ->csv(static fn (array $r): string => (string) $r['product']),
                    Column::make('batch', 'Batch')->sortBy('b.batch_no')->cell(static fn (array $r): string => esc($r['batch_no']))->csv(static fn (array $r): string => (string) $r['batch_no']),
                    Column::make('exp', 'Expires')->nowrap()->sortBy('b.expires_on')
                        ->cell(static fn (array $r): string => esc(date_str($r['expires_on'])) . ' ' . ((int) $r['days_left'] < 0 ? tag_html('Expired ' . abs((int) $r['days_left']) . 'd ago', 'danger') : tag_html((int) $r['days_left'] . 'd left', 'warn')))
                        ->csv(static fn (array $r): string => (string) $r['expires_on']),
                    Column::make('qty', 'Qty')->num()->sortBy('b.qty')->cell(static fn (array $r): string => esc(qty($r['qty'], $r['unit'] ?: null)))->csv(static fn (array $r): string => qty($r['qty'])),
                    Column::make('value', 'Value (cost)')->num()->sortBy('value')->cell(static fn (array $r): string => esc(money($r['value'], $cur)))->csv(static fn (array $r): string => (string) $r['value']),
                    $view === 'expired' && $manage ? Column::make('act', '')->cls('num nw')->noCsv()
                        ->cell(static fn (array $r): string => '<form class="inline" method="post" action="' . esc(site_url('pharmacy/batches/' . $r['id'] . '/write-off'), 'attr') . '" data-confirm="Write off batch ' . esc($r['batch_no'], 'attr') . ' (' . esc(qty($r['qty']), 'attr') . ')? Stock goes to zero for this batch.">'
                            . csrf_field() . '<button class="btn sm danger" type="submit">' . icon('trash', 'sm') . 'Write off</button></form>') : null,
                ])))
                ->defaultSort('exp')->placeholder('Product, generic or batch…')->unit('batches')->exportable($view === 'expired' ? 'expired-batches' : 'near-expiry')
                ->emptyHtml('<span class="muted">' . ($view === 'expired' ? 'No expired stock on the shelf.' : 'Nothing expires in this window.') . '</span>');
        }
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Pharmacy\Views\index', [
            'title' => 'Pharmacy', 'view' => $view, 'table' => $table->render(), 'stats' => $svc->stats(), 'settings' => PharmacySettings::current(),
            'canSettings' => $s->can('settings.manage'), 'medicines' => MedicineCatalog::count(), 'cur' => $cur,
        ]);
    }

    public function medicines(): string|ResponseInterface
    {
        $canAdd = $this->s()->can('products.manage');
        $table = DataTable::make('med', $this->request)->from(MedicineCatalog::browseQuery())
            ->columns(array_values(array_filter([
                Column::make('brand', 'Brand')->sortBy('m.brand_name')->searchIn('m.brand_name', 'm.generic', 'm.manufacturer', 'm.brand_id')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['brand_name']) . '</b>' . ($r['strength'] ? ' ' . esc($r['strength']) : '') . '<br><small class="muted">' . esc((string) ($r['dosage_form'] ?? '')) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['brand_name']),
                Column::make('generic', 'Generic')->sortBy('m.generic')->cell(static fn (array $r): string => esc((string) ($r['generic'] ?? '—')))->csv(static fn (array $r): string => (string) ($r['generic'] ?? '')),
                Column::make('mfr', 'Manufacturer')->sortBy('m.manufacturer')->cell(static fn (array $r): string => esc((string) ($r['manufacturer'] ?? '—')))->csv(static fn (array $r): string => (string) ($r['manufacturer'] ?? '')),
                Column::make('pack', 'Package')->cell(static fn (array $r): string => esc(trim(($r['package_container'] ?? '') . ' ' . ($r['package_size'] ?? '')) ?: '—'))->noCsv(),
                $canAdd ? Column::make('act', '')->cls('num nw')->noCsv()
                    ->cell(static fn (array $r): string => '<a class="btn sm" href="' . esc(site_url('products/new') . '?medicine=' . (int) $r['id'], 'attr') . '">' . icon('plus', 'sm') . 'Add as product</a>') : null,
            ])))
            ->defaultSort('brand')->placeholder('Brand, generic, manufacturer…')->unit('medicines')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('pill') . '</span><b>The medicine database is empty</b><span>The platform imports it from the official dataset; until then add products by hand.</span></div>');

        return $this->page('App\Modules\Pharmacy\Views\medicines', ['title' => 'Medicine database', 'table' => $table->render()]);
    }

    /** GET pharmacy/medicines/search?q= — JSON for "Add from medicine database". */
    public function search(): ResponseInterface
    {
        return $this->response->setJSON(['data' => MedicineCatalog::search($this->getStr('q', 80), $this->s()->businessId())]);
    }

    public function writeOff(int $batchId): RedirectResponse
    {
        $r = PharmacyService::make()->writeOff($batchId, (string) ($this->request->getPost('note') ?? ''));

        return $this->done('Batch ' . $r['batch'] . ' of ' . $r['product'] . ' written off.', site_url('pharmacy?view=expired'));
    }

    public function saveSettings(): RedirectResponse
    {
        PharmacySettings::save((array) $this->request->getPost());

        return $this->done('Pharmacy settings saved.', site_url('pharmacy'));
    }
}
