<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\Icons;
use App\Modules\Products\Services\CategoryService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class CategoriesController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $manage = $this->s()->can('products.manage');
        $icons = Icons::choices();
        $table = DataTable::make('cats', $this->request)
            ->from((new CategoryService())->listQuery())
            ->columns([
                Column::make('name', 'Category')->sortBy('c.name')->searchIn('c.name')
                    ->cell(static fn (array $r): string => '<span class="cat-ic cat-fav">' . sym_icon($r['icon'], '') . '</span> <b>' . esc($r['name']) . '</b>'),
                Column::make('n', 'Products')->num()->sortBy('n')->cell(static fn (array $r): string => '<a class="link tap" href="' . esc(site_url('products?cat=' . $r['id']), 'attr') . '">' . (int) $r['n'] . '</a>'),
                Column::make('sort', 'Order')->num()->sortBy('c.sort_order')->width('60px')->cell(static fn (array $r): string => (string) (int) $r['sort_order']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => $manage
                    ? '<button class="icon-btn" type="button" data-modal-open="cat-' . (int) $r['id'] . '" title="Edit">' . icon('edit', 'sm') . '</button>'
                      . '<form class="inline" method="post" action="' . esc(site_url('products/categories/' . $r['id'] . '/delete'), 'attr') . '" data-confirm="Delete ' . esc($r['name'], 'attr') . '? Its products stay, without a category.">' . csrf_field()
                      . '<button class="icon-btn" type="submit" title="Delete" style="color:var(--danger)">' . icon('trash', 'sm') . '</button></form>' : ''),
            ])
            ->defaultSort('sort')
            ->perPage(50)
            ->placeholder('Category…')
            ->unit('categories');

        return $this->page('App\Modules\Products\Views\categories', [
            'title' => 'Categories',
            'table' => $table->render(),
            'rows' => $table->result()['rows'],
            'icons' => $icons,
            'manage' => $manage,
        ]);
    }

    public function store(): RedirectResponse
    {
        (new CategoryService())->save(null, (array) $this->request->getPost());

        return $this->done('Category added.');
    }

    public function update(int $id): RedirectResponse
    {
        (new CategoryService())->save($id, (array) $this->request->getPost());

        return $this->done('Category saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        (new CategoryService())->delete($id);

        return $this->done('Category deleted.');
    }
}
