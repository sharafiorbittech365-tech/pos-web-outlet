<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Expenses\Services\ExpenseService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class ExpensesController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = ExpenseService::make();
        $cur = $this->cur();
        $range = DateRange::fromRequest($this->request, 'month');
        $cat = $this->getStr('cat', 60);
        $method = $this->getStr('method', 16);
        $methods = ExpenseService::METHODS;
        $table = DataTable::make('exp', $this->request)
            ->from($svc->listQuery($range->from, $range->to, $cat, $method))
            ->keep(array_merge($range->params(), ['cat' => $cat, 'method' => $method]))
            ->columns([
                Column::make('at', 'Date')->nowrap()->sortBy('e.spent_at')->cell(static fn (array $r): string => esc(datetime_str($r['spent_at'])))->csv(static fn (array $r): string => (string) $r['spent_at']),
                Column::make('cat', 'Category')->sortBy('e.category')->searchIn('e.category', 'e.note')->cell(static fn (array $r): string => tag_html($r['category']))->csv(static fn (array $r): string => (string) $r['category']),
                Column::make('note', 'Note')->cell(static fn (array $r): string => esc((string) $r['note'])),
                Column::make('method', 'Paid from')->sortBy('e.method')->cell(static fn (array $r): string => esc($methods[$r['method']] ?? $r['method']) . ($r['register_id'] ? ' <small class="muted">· register #' . (int) $r['register_id'] . '</small>' : ''))->csv(static fn (array $r): string => $methods[$r['method']] ?? $r['method']),
                Column::make('by', 'By')->sortBy('u.name')->cell(static fn (array $r): string => esc($r['user_name'] ?? '—'))->csv(static fn (array $r): string => (string) ($r['user_name'] ?? '')),
                Column::make('amount', 'Amount')->num()->sortBy('e.amount')->cell(static fn (array $r): string => '<b>' . esc(money($r['amount'], $cur)) . '</b>')->csv(static fn (array $r): string => (string) $r['amount']),
                Column::make('act', '')->cls('num')->noCsv()->cell(static fn (array $r): string => '<form class="inline" method="post" action="' . esc(site_url('expenses/' . $r['id'] . '/delete'), 'attr') . '" data-confirm="Delete this expense?">' . csrf_field()
                    . '<button class="x-btn" type="submit" aria-label="Delete">' . icon('trash', 'sm') . '</button></form>'),
            ])
            ->defaultSort('at', 'desc')->placeholder('Category, note…')->unit('expenses')->exportable('expenses')
            ->emptyHtml('<span class="muted">No expenses in this period.</span>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Expenses\Views\index', [
            'title' => 'Expenses',
            'table' => $table->render(),
            'range' => $range,
            'sum' => $svc->summary($range->from, $range->to),
            'cats' => $svc->categories(),
            'cat' => $cat,
            'method' => $method,
            'methods' => $methods,
        ]);
    }

    public function store(): RedirectResponse
    {
        ExpenseService::make()->create((array) $this->request->getPost());

        return $this->done('Expense saved.');
    }

    public function delete(int $id): RedirectResponse
    {
        ExpenseService::make()->delete($id);

        return $this->done('Expense deleted.');
    }
}
