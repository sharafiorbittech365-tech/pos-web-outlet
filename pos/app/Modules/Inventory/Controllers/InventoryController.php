<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Inventory\Services\InventoryService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class InventoryController extends BackOfficeController
{
    private const TYPE_TAG = ['serial' => 'IMEI / Serial', 'variant' => 'Options', 'batch' => 'Batch / Expiry', 'simple' => 'Simple'];

    public function index(): string|ResponseInterface
    {
        $svc = InventoryService::make();
        $cur = $this->cur();
        $filter = in_array($f = $this->getStr('f', 10), ['low', 'out', 'expiring'], true) ? $f : 'all';
        $manage = $this->s()->can('inventory.manage');
        $details = [];
        $table = DataTable::make('inv', $this->request)
            ->from($svc->levelsQuery($filter))
            ->keep(['f' => $filter === 'all' ? '' : $filter])
            ->columns([
                Column::make('name', 'Product')->sortBy('p.name')->searchIn('p.name', 'p.sku', 'p.barcode', 'p.generic_name')
                    ->cell(static function (array $r) use (&$details): string {
                        $d = $details[(int) $r['id']] ?? '';

                        return sym_icon($r['icon'] ?: 'package') . ' <b>' . esc($r['name']) . '</b><br><small class="muted">' . esc($r['sku'] ?: ($r['barcode'] ?: ($r['generic_name'] ?? ''))) . '</small>' . ($d !== '' ? '<br><small class="muted">' . esc($d) . '</small>' : '');
                    })->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('category', 'Category')->sortBy('c.name')->cell(static fn (array $r): string => esc($r['category'] ?? '')),
                Column::make('type', 'Tracking')->sortBy('p.stock_type')->cell(static fn (array $r): string => tag_html(self::TYPE_TAG[$r['stock_type']] ?? $r['stock_type']))->csv(static fn (array $r): string => self::TYPE_TAG[$r['stock_type']] ?? ''),
                Column::make('stock', 'In stock')->num()->sortBy('p.stock_qty')->cell(static fn (array $r): string => '<b>' . esc(qty($r['stock_qty'])) . '</b> ' . esc($r['unit'] ?: 'pcs'))->csv(static fn (array $r): string => qty($r['stock_qty'])),
                Column::make('cost', 'Cost')->num()->sortBy('p.cost')->cell(static fn (array $r): string => esc(money($r['cost'], $cur)))->csv(static fn (array $r): string => (string) $r['cost']),
                Column::make('price', 'Price')->num()->sortBy('p.price')->cell(static fn (array $r): string => esc(money($r['price'], $cur)))->csv(static fn (array $r): string => (string) $r['price']),
                Column::make('value', 'Value')->num()->sortBy('value')->cell(static fn (array $r): string => esc(money(max(0, (float) $r['value']), $cur)))->csv(static fn (array $r): string => (string) max(0, (float) $r['value'])),
                Column::make('status', 'Status')->sortBy('p.stock_qty')->cell(static fn (array $r): string => (float) $r['stock_qty'] <= 0 ? tag_html('Out of stock', 'danger') : ((float) $r['stock_qty'] <= (float) $r['low_at'] ? tag_html('Low stock', 'warn') : tag_html('In stock', 'ok')))
                    ->csv(static fn (array $r): string => (float) $r['stock_qty'] <= 0 ? 'Out of stock' : ((float) $r['stock_qty'] <= (float) $r['low_at'] ? 'Low stock' : 'In stock')),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => '<a class="icon-btn" href="' . esc(site_url('products/' . $r['id']), 'attr') . '" title="Edit product">' . icon('edit', 'sm') . '</a> <a class="btn sm" href="' . esc(site_url('inventory/' . $r['id']), 'attr') . '">' . ($manage ? 'Manage' : 'View') . '</a>'),
            ])
            ->defaultSort('name')
            ->placeholder('Product, SKU, barcode, generic…')
            ->unit('items')
            ->exportable('inventory')
            ->emptyHtml('<span class="muted">Nothing here.</span>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        $details = $svc->details($table->result()['rows']);

        return $this->page('App\Modules\Inventory\Views\index', [
            'title' => 'Inventory',
            'table' => $table->render(),
            'stats' => $svc->stats(),
            'filter' => $filter,
            'batches' => ! empty($this->s()->feature('batches')) || $svc->stats()['expiring'] > 0,
            'cur' => $cur,
        ]);
    }

    public function show(int $id): string|ResponseInterface
    {
        $svc = InventoryService::make();
        $p = $svc->product($id);
        $range = DateRange::make('custom', date('Y-m-d', strtotime('-2 years')), date('Y-m-d'));

        return $this->page('App\Modules\Inventory\Views\show', [
            'title' => 'Stock · ' . $p['name'],
            'p' => $p,
            'manage' => $this->s()->can('inventory.manage'),
            'log' => $this->movementTable('pmv', $svc->movementsQuery($id, '', $range->from, $range->to), false)->render(),
            'reasons' => InventoryService::REASONS,
        ]);
    }

    public function movements(): string|ResponseInterface
    {
        $range = DateRange::fromRequest($this->request, '7d');
        $reason = $this->getStr('reason', 16);
        $table = $this->movementTable('mv', InventoryService::make()->movementsQuery(null, $reason, $range->from, $range->to), true)
            ->keep(array_merge($range->params(), ['reason' => $reason]))->exportable('stock-movements');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Inventory\Views\movements', ['title' => 'Stock movements', 'table' => $table->render(), 'range' => $range, 'reason' => $reason, 'reasons' => InventoryService::MOVE_REASONS]);
    }

    private function movementTable(string $id, \CodeIgniter\Database\BaseBuilder $q, bool $withProduct): DataTable
    {
        $labels = InventoryService::MOVE_REASONS;

        return DataTable::make($id, $this->request)->from($q)
            ->columns(array_values(array_filter([
                Column::make('at', 'When')->nowrap()->sortBy('m.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at'])))->csv(static fn (array $r): string => (string) $r['created_at']),
                $withProduct ? Column::make('product', 'Product')->sortBy('p.name')->searchIn('p.name')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('inventory/' . $r['product_id']), 'attr') . '">' . esc($r['product'] ?? '—') . '</a>' . ($r['variant'] ? '<br><small class="muted">' . esc($r['variant']) . '</small>' : ''))->csv(static fn (array $r): string => (string) $r['product']) : null,
                Column::make('reason', 'Type')->sortBy('m.reason')->cell(static fn (array $r): string => tag_html($labels[$r['reason']] ?? $r['reason'], in_array($r['reason'], ['sale', 'web'], true) ? 'primary' : ($r['reason'] === 'adjust' ? 'warn' : '')))->csv(static fn (array $r): string => $labels[$r['reason']] ?? $r['reason']),
                Column::make('change', 'Change')->num()->sortBy('m.qty_change')->cell(static fn (array $r): string => '<b class="' . ((float) $r['qty_change'] < 0 ? 'neg' : 'pos') . '">' . ((float) $r['qty_change'] > 0 ? '+' : '') . esc(qty($r['qty_change'])) . '</b>')->csv(static fn (array $r): string => qty($r['qty_change'])),
                Column::make('after', 'After')->num()->cell(static fn (array $r): string => $r['qty_after'] === null ? '—' : esc(qty($r['qty_after'])))->csv(static fn (array $r): string => $r['qty_after'] === null ? '' : qty($r['qty_after'])),
                Column::make('note', 'Details')->searchIn('m.note', 'o.doc_no')->cell(static fn (array $r): string => ($r['doc_no'] ? '<a class="link" href="' . esc(site_url('orders/' . $r['ref_id']), 'attr') . '">' . esc($r['doc_no']) . '</a> ' : '') . esc((string) $r['note']))->csv(static fn (array $r): string => trim(($r['doc_no'] ?? '') . ' ' . $r['note'])),
                Column::make('user', 'By')->sortBy('u.name')->cell(static fn (array $r): string => esc($r['user_name'] ?? '—'))->csv(static fn (array $r): string => (string) ($r['user_name'] ?? '')),
            ])))
            ->defaultSort('at', 'desc')->perPage(25)->placeholder('Product, note or invoice…')->unit('movements')->compact();
    }

    public function expiry(): string|ResponseInterface
    {
        $cur = $this->cur();
        $days = in_array($d = $this->getInt('days'), [0, 30, 60, 90, 180], true) ? $d : InventoryService::NEAR_DAYS;
        $table = DataTable::make('exp', $this->request)
            ->from(InventoryService::make()->batchesQuery($days))
            ->keep(['days' => (string) $days])
            ->columns([
                Column::make('product', 'Product')->sortBy('p.name')->searchIn('p.name', 'b.batch_no')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('inventory/' . $r['product_id']), 'attr') . '"><b>' . esc($r['product']) . '</b></a>'),
                Column::make('batch', 'Batch')->sortBy('b.batch_no')->cell(static fn (array $r): string => esc($r['batch_no'])),
                Column::make('exp', 'Expires')->nowrap()->sortBy('b.expires_on')->cell(static fn (array $r): string => esc(date_str($r['expires_on'])) . ' ' . ($r['days_left'] === null ? '' : ((int) $r['days_left'] < 0 ? tag_html('Expired', 'danger') : tag_html((int) $r['days_left'] . 'd left', (int) $r['days_left'] <= 30 ? 'warn' : ''))))->csv(static fn (array $r): string => (string) $r['expires_on']),
                Column::make('qty', 'Qty')->num()->sortBy('b.qty')->cell(static fn (array $r): string => esc(qty($r['qty'], $r['unit'] ?: null)))->csv(static fn (array $r): string => qty($r['qty'])),
                Column::make('value', 'Value (cost)')->num()->sortBy('value')->cell(static fn (array $r): string => esc(money($r['value'], $cur)))->csv(static fn (array $r): string => (string) $r['value']),
            ])
            ->defaultSort('exp')->placeholder('Product or batch…')->unit('batches')->exportable('expiring-batches')
            ->emptyHtml('<span class="muted">No batches in this window.</span>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Inventory\Views\expiry', ['title' => 'Batch expiry', 'table' => $table->render(), 'days' => $days]);
    }

    public function serials(): string|ResponseInterface
    {
        $status = $this->getStr('status', 10);
        $labels = ['in_stock' => ['In stock', 'ok'], 'sold' => ['Sold', 'primary'], 'returned' => ['Returned', 'warn'], 'faulty' => ['Faulty / removed', 'danger']];
        $table = DataTable::make('sn', $this->request)
            ->from(InventoryService::make()->serialsQuery($status))
            ->keep(['status' => $status])
            ->columns([
                Column::make('serial', 'IMEI / serial')->sortBy('s.serial')->searchIn('s.serial', 'p.name', 'o.doc_no', 'o.customer_name')->cell(static fn (array $r): string => '<code>' . esc($r['serial']) . '</code>'),
                Column::make('product', 'Product')->sortBy('p.name')->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('inventory/' . $r['product_id']), 'attr') . '">' . esc($r['product']) . '</a>'),
                Column::make('status', 'Status')->sortBy('s.status')->cell(static fn (array $r): string => tag_html(...($labels[$r['status']] ?? [$r['status'], ''])))->csv(static fn (array $r): string => $labels[$r['status']][0] ?? $r['status']),
                Column::make('order', 'Sold on')->cell(static fn (array $r): string => $r['doc_no'] ? '<a class="link" href="' . esc(site_url('orders/' . $r['order_id']), 'attr') . '">' . esc($r['doc_no']) . '</a><br><small class="muted">' . esc($r['customer_name'] ?? '') . '</small>' : '<span class="muted">—</span>')->csv(static fn (array $r): string => (string) ($r['doc_no'] ?? '')),
                Column::make('sold', 'Sold at')->nowrap()->sortBy('s.sold_at')->cell(static fn (array $r): string => esc(datetime_str($r['sold_at']))),
                Column::make('warranty', 'Warranty until')->nowrap()->cell(static fn (array $r): string => $r['sold_at'] && $r['warranty_months'] ? esc(date_str(date('Y-m-d', strtotime($r['sold_at'] . ' +' . (int) $r['warranty_months'] . ' months')))) : '<span class="muted">—</span>')->noCsv(),
                Column::make('added', 'Received')->nowrap()->sortBy('s.created_at')->cell(static fn (array $r): string => esc(date_str($r['created_at']))),
            ])
            ->defaultSort('added', 'desc')->placeholder('IMEI, serial, product, invoice, customer…')->unit('serials')->exportable('serials');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Inventory\Views\serials', ['title' => 'IMEI / serial lookup', 'table' => $table->render(), 'status' => $status, 'labels' => $labels]);
    }

    public function adjust(int $id): RedirectResponse
    {
        $after = InventoryService::make()->adjust($id, (array) $this->request->getPost());

        return $this->done('Stock saved — now ' . qty($after) . '.', site_url('inventory/' . $id));
    }

    public function addSerials(int $id): RedirectResponse
    {
        $list = preg_split('/[\r\n,]+/', (string) $this->request->getPost('serials')) ?: [];
        $n = InventoryService::make()->addSerials($id, $list, mb_substr(trim((string) $this->request->getPost('note')), 0, 120));

        return $this->done($n > 0 ? $n . ' serial number' . ($n === 1 ? '' : 's') . ' added.' : 'No new serial numbers (already on file).', site_url('inventory/' . $id));
    }

    public function removeSerial(int $id, int $serialId): RedirectResponse
    {
        InventoryService::make()->removeSerial($id, $serialId, mb_substr(trim((string) $this->request->getPost('note')), 0, 120));

        return $this->done('Serial taken out of stock.', site_url('inventory/' . $id));
    }

    public function receiveBatch(int $id): RedirectResponse
    {
        $d = $this->validated(['batch_no' => 'required|max_length[48]', 'expires_on' => 'permit_empty|valid_date[Y-m-d]', 'qty' => 'required|numeric|greater_than[0]']);
        InventoryService::make()->receiveBatch($id, $d['batch_no'], ($d['expires_on'] ?? '') !== '' ? $d['expires_on'] : null, (float) $d['qty']);

        return $this->done('Batch received.', site_url('inventory/' . $id));
    }

    public function adjustBatch(int $id, int $batchId): RedirectResponse
    {
        $d = $this->validated(['delta' => 'required|numeric', 'reason' => 'permit_empty|in_list[' . implode(',', array_keys(InventoryService::REASONS)) . ']', 'note' => 'permit_empty|max_length[200]']);
        InventoryService::make()->adjustBatch($id, $batchId, (float) $d['delta'], (string) ($d['reason'] ?? 'count'), (string) ($d['note'] ?? ''));

        return $this->done('Batch updated.', site_url('inventory/' . $id));
    }
}
