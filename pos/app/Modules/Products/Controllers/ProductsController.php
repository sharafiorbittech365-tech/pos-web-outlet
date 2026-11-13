<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\Icons;
use App\Modules\Products\Services\ProductCsv;
use App\Modules\Products\Services\ProductService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class ProductsController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = ProductService::make();
        $s = $this->s();
        $cur = $this->cur();
        $manage = $s->can('products.manage');
        $filters = ['cat' => $this->getInt('cat'), 'type' => $this->getStr('type', 16), 'stock' => $this->getStr('stock', 8)];
        $types = ['none' => '', 'simple' => 'Count', 'variant' => 'Options', 'serial' => 'IMEI / SN', 'batch' => 'Batch'];
        $low = (float) ($s->business()['low_stock_alert'] ?? 0);
        $table = DataTable::make('products', $this->request)
            ->from($svc->listQuery($filters))
            ->keep(array_filter($filters))
            ->fullText('p.name', 'p.brand', 'p.sku', 'p.generic_name')
            ->columns(array_values(array_filter([
                $manage ? Column::make('sel', '')->width('36px')->noCsv()->cell(static fn (array $r): string => '<input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" form="bulk-form" aria-label="Select ' . esc($r['name'], 'attr') . '">') : null,
                Column::make('name', 'Product')->sortBy('p.name')->searchIn('p.name', 'p.sku', 'p.barcode', 'p.brand', 'p.generic_name')
                    ->cell(static fn (array $r): string => '<div class="pl-cell"><span class="lthumb">' . sym_icon($r['icon'] ?: 'package') . '</span><div class="grow"><a class="pname" href="' . esc(site_url('products/' . $r['id']), 'attr') . '">' . esc($r['name'])
                        . ($r['is_featured'] ? ' <span class="feat-star">' . icon('star', 'sm') . '</span>' : '') . '</a><small class="muted">' . (esc(implode(' · ', array_filter([$r['sku'] ?: $r['barcode'], $r['brand'], $r['generic_name']]))) ?: '—') . '</small></div></div>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('category', 'Category')->sortBy('c.name')->cell(static fn (array $r): string => esc($r['category'] ?? '—')),
                Column::make('price', 'Price')->num()->sortBy('p.price')
                    ->cell(static fn (array $r): string => '<b>' . esc(money($r['price'], $cur)) . '</b>' . ($r['unit'] ? '<small class="muted">/' . esc($r['unit']) . '</small>' : '') . ((int) $r['variants'] ? '<br><small class="muted">' . (int) $r['variants'] . ' options</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['price']),
                Column::make('vat', $s->business()['tax_label'] ?? 'VAT')->sortBy('p.tax_mode')->cell(static fn (array $r): string => '<small class="tax-tag">' . esc($svc->taxLabel($r['tax_mode'], $r['tax_rate'])) . '</small>')
                    ->csv(static fn (array $r): string => $svc->taxLabel($r['tax_mode'], $r['tax_rate'])),
                Column::make('cost', 'Cost')->num()->sortBy('p.cost')->cell(static fn (array $r): string => (float) $r['cost'] > 0 ? esc(money($r['cost'], $cur)) : '<span class="muted">—</span>')->csv(static fn (array $r): string => (string) $r['cost']),
                Column::make('margin', 'Margin')->num()->sortBy('(p.price - p.cost) / NULLIF(p.price, 0)')
                    ->cell(static function (array $r): string {
                        if ((float) $r['price'] <= 0 || (float) $r['cost'] <= 0) {
                            return '<span class="muted">—</span>';
                        }
                        $m = (int) round(((float) $r['price'] - (float) $r['cost']) / (float) $r['price'] * 100);

                        return '<span class="' . ($m < 10 ? 'neg' : ($m >= 30 ? 'pos' : '')) . '">' . $m . '%</span>';
                    })->noCsv(),
                Column::make('stock', 'Stock')->num()->sortBy('p.stock_qty')
                    ->cell(static fn (array $r): string => $r['stock_type'] === 'none' ? '<span class="muted">Not tracked</span>'
                        : '<b class="' . ((float) $r['stock_qty'] <= 0 ? 'neg' : '') . '">' . esc(qty($r['stock_qty'])) . '</b> <small class="muted">' . esc($r['unit'] ?: 'pcs') . '</small><br><span class="tag">' . esc($types[$r['stock_type']] ?? '') . '</span>')
                    ->csv(static fn (array $r): string => $r['stock_type'] === 'none' ? '' : qty($r['stock_qty'])),
                Column::make('status', 'Status')->sortBy('p.is_active')
                    ->cell(static function (array $r) use ($low): string {
                        if (! $r['is_active']) {
                            return tag_html('Hidden');
                        }
                        if ($r['stock_type'] !== 'none') {
                            if ((float) $r['stock_qty'] <= 0) {
                                return tag_html('Out', 'danger');
                            }
                            if ((float) $r['stock_qty'] <= (float) ($r['low_stock_at'] ?? $low)) {
                                return tag_html('Low', 'warn');
                            }
                        }

                        return tag_html('Active', 'ok') . ($r['is_online'] ? '' : ' ' . tag_html('Offline'));
                    })->csv(static fn (array $r): string => $r['is_active'] ? 'Active' : 'Hidden'),
                Column::make('act', '')->cls('num nw')->noCsv()
                    ->cell(static fn (array $r): string => '<a class="icon-btn" href="' . esc(site_url('products/' . $r['id']), 'attr') . '" title="' . ($manage ? 'Edit' : 'View') . '">' . icon($manage ? 'edit' : 'eye', 'sm') . '</a>'),
            ])))
            ->defaultSort('name')
            ->placeholder('Name, SKU, barcode, brand, generic…')
            ->unit('products')
            ->emptyHtml('<div class="empty"><span class="empty-ic">' . icon('tag') . '</span><b>No products found</b><span>Try another category or type — or add a new product.</span></div>');

        return $this->page('App\Modules\Products\Views\index', [
            'title' => terms_text('{catalog}'),
            'table' => $table->render(),
            'stats' => $svc->stats(),
            'cats' => $svc->categories(),
            'filters' => $filters,
            'manage' => $manage,
            'csv' => $s->access()->feature('csv'),
            'stockTypes' => ProductService::STOCK_TYPES,
        ]);
    }

    public function create(): string
    {
        // "Add as product" from Pharmacy › Medicine database: start from the medicine's names
        $mid = $this->getInt('medicine');
        if ($mid > 0 && $this->s()->moduleOn('pharmacy') && ($m = \App\Modules\Pharmacy\Services\MedicineCatalog::find($mid)) !== null) {
            return $this->form(null, $m);
        }

        return $this->form(null);
    }

    public function edit(int $id): string
    {
        return $this->form(ProductService::make()->find($id));
    }

    /**
     * @param array<string, mixed>|null $p
     * @param array<string, mixed>|null $fromMedicine medicines row to prefill a new product
     */
    private function form(?array $p, ?array $fromMedicine = null): string
    {
        $svc = ProductService::make();
        $s = $this->s();
        $b = $s->business();

        return $this->page('App\Modules\Products\Views\form', [
            'title' => $p === null ? 'Add ' . mb_strtolower(term('item')) : ($s->can('products.manage') ? 'Edit ' . mb_strtolower(term('item')) : (string) $p['name']),
            'p' => $p,
            'readOnly' => ! $s->can('products.manage'),
            'cats' => $svc->categories(),
            'brands' => $svc->brands(),
            'rates' => $svc->taxPolicy()->rates(),
            'biz' => $b,
            'features' => (array) ($b['settings']['features'] ?? []),
            'priceGroups' => (array) ($b['settings']['priceGroups'] ?? []),
            'icons' => Icons::choices(),
            'stockTypes' => ProductService::STOCK_TYPES,
            'cur' => $this->cur(),
            'stockModule' => $s->moduleOn('inventory'),
            'pharmacy' => $pharmacy = $s->moduleOn('pharmacy'),
            'medicine' => $pharmacy ? ($fromMedicine ?? (! empty($p['medicine_id']) ? \App\Modules\Pharmacy\Services\MedicineCatalog::find((int) $p['medicine_id']) : null)) : null,
            'scales' => \App\Modules\Products\Services\MatrixGenerator::config(),
            'web' => $s->moduleOn('website'),
            'extra' => ($ex = new \App\Modules\Products\Services\ProductExtras())->find((int) ($p['id'] ?? 0)),
            'xdefs' => $ex->definitions(),
            'pics' => $p === null ? [] : \App\Modules\Products\Services\ProductPhotos::rows((int) $p['id']),
            'reviewCount' => $p === null ? 0 : (int) db_connect()->table('product_reviews')->where('product_id', (int) $p['id'])->countAllResults(),
        ]);
    }

    public function store(): RedirectResponse
    {
        $photos = new \App\Modules\Products\Services\ProductPhotos();
        $files = $photos->check($this->request->getFileMultiple('photos') ?: []);
        $post = (array) $this->request->getPost();
        $extras = new \App\Modules\Products\Services\ProductExtras();
        $prepared = $extras->prepare($post, null, (string) ($post['name'] ?? ''));
        $id = ProductService::make()->save(null, $post);
        $extras->persist($id, $prepared);
        $photos->apply($id, $files, $post);

        return $this->done('Product added.', $this->request->getPost('again') ? site_url('products/new') : site_url('products/' . $id));
    }

    public function update(int $id): RedirectResponse
    {
        $photos = new \App\Modules\Products\Services\ProductPhotos();
        $files = $photos->check($this->request->getFileMultiple('photos') ?: []);
        $post = (array) $this->request->getPost();
        $extras = new \App\Modules\Products\Services\ProductExtras();
        $prepared = $extras->prepare($post, $id, (string) ($post['name'] ?? ''));
        ProductService::make()->save($id, $post);
        $extras->persist($id, $prepared);
        $photos->apply($id, $files, $post);

        return $this->done('Product saved.', site_url('products/' . $id));
    }

    public function delete(int $id): RedirectResponse
    {
        ProductService::make()->delete($id);

        return $this->done('Product deleted — past invoices keep their lines.', site_url('products'));
    }

    public function bulk(): RedirectResponse
    {
        $ids = array_map('intval', $this->postArray('ids'));
        $n = ProductService::make()->bulk($ids, (string) $this->request->getPost('action'), (int) $this->request->getPost('category_id'));

        return $this->done($n . ' product' . ($n === 1 ? '' : 's') . ' updated.');
    }

    public function export(): ResponseInterface
    {
        $this->assertCsv();

        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="products-' . date('Y-m-d') . '.csv"')
            ->setBody((new ProductCsv())->export());
    }

    public function importForm(): string
    {
        $this->assertCsv();

        return $this->page('App\Modules\Products\Views\import', ['title' => 'Import ' . mb_strtolower(term('catalog')), 'columns' => ProductCsv::COLUMNS, 'result' => session()->getFlashdata('import')]);
    }

    public function import(): RedirectResponse
    {
        $this->assertCsv();
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid() || $file->getSize() > 5 * 1024 * 1024 || ! in_array(strtolower($file->getClientExtension()), ['csv', 'txt'], true)) {
            throw new BusinessRuleException('Choose a CSV file (at most 5 MB).');
        }
        $result = (new ProductCsv())->import($file->getTempName());

        return redirect()->to(site_url('products/import'))->with('import', $result)
            ->with('success', 'Import finished: ' . $result['created'] . ' created, ' . $result['updated'] . ' updated.');
    }

    private function assertCsv(): void
    {
        if (! $this->s()->access()->feature('csv')) {
            throw new BusinessRuleException('CSV import & export is part of the Business and Enterprise plans.');
        }
    }
}
