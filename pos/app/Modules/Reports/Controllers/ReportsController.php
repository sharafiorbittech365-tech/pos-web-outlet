<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use App\Modules\Reports\Services\ReportService;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class ReportsController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $range = DateRange::fromRequest($this->request, 'today');
        $r = new ReportService($range->from, $range->to);
        $cur = $this->cur();
        $keep = $range->params();
        $label = (string) ($this->s()->business()['tax_label'] ?? 'VAT');

        $vatReg = DataTable::make('vreg', $this->request)->from($r->vatRegisterQuery())->keep($keep)
            ->columns([
                Column::make('doc', 'Invoice')->nowrap()->sortBy('o.number')->searchIn('o.doc_no', 'o.customer_name')
                    ->cell(static fn (array $x): string => '<a class="link" href="' . esc(site_url('orders/' . $x['id']), 'attr') . '">' . esc($x['doc_no']) . '</a>' . ((float) $x['returned_total'] > 0 ? ' <span class="tag">returns</span>' : ''))
                    ->csv(static fn (array $x): string => (string) $x['doc_no']),
                Column::make('at', 'Date')->nowrap()->sortBy('o.created_at')->cell(static fn (array $x): string => esc(date_str($x['created_at'])))->csv(static fn (array $x): string => (string) $x['created_at']),
                Column::make('cust', 'Customer')->sortBy('o.customer_name')->cell(static fn (array $x): string => esc($x['customer_name']))->csv(static fn (array $x): string => (string) $x['customer_name']),
                Column::make('net', 'Net')->num()->sortBy('net')->cell(static fn (array $x): string => esc(money($x['net'], $cur)))->csv(static fn (array $x): string => (string) $x['net']),
                Column::make('incl', 'Incl.')->num()->sortBy('incl')->cell(static fn (array $x): string => esc(money($x['incl'], $cur)))->csv(static fn (array $x): string => (string) $x['incl']),
                Column::make('added', 'Added')->num()->sortBy('added')->cell(static fn (array $x): string => esc(money($x['added'], $cur)))->csv(static fn (array $x): string => (string) $x['added']),
                Column::make('tax', $label)->num()->sortBy('tax')->cell(static fn (array $x): string => '<b>' . esc(money($x['tax'], $cur)) . '</b>')->csv(static fn (array $x): string => (string) $x['tax']),
                Column::make('total', 'Total')->num()->sortBy('gross')->cell(static fn (array $x): string => esc(money($x['gross'], $cur)))->csv(static fn (array $x): string => (string) $x['gross']),
            ])->defaultSort('at', 'desc')->perPage(10)->placeholder('Invoice or customer…')->unit('invoices')->compact()->exportable('vat-register')
            ->emptyHtml('<span class="muted">No sales in this period</span>');

        $days = DataTable::make('days', $this->request)->from($r->daysQuery())->keep($keep)->noSearch()
            ->columns([
                Column::make('d', 'Date')->nowrap()->sortBy('x.d')->cell(static fn (array $x): string => esc(date_str($x['d'])))->csv(static fn (array $x): string => (string) $x['d']),
                Column::make('n', 'Invoices')->num()->sortBy('n')->cell(static fn (array $x): string => (string) (int) $x['n']),
                Column::make('sales', 'Sales')->num()->sortBy('sales')->cell(static fn (array $x): string => esc(money($x['sales'], $cur)))->csv(static fn (array $x): string => (string) $x['sales']),
                Column::make('col', 'Collected')->num()->sortBy('collected')->cell(static fn (array $x): string => esc(money($x['collected'], $cur)))->csv(static fn (array $x): string => (string) $x['collected']),
                Column::make('ret', 'Returns')->num()->sortBy('returns')->cell(static fn (array $x): string => esc(money($x['returns'], $cur)))->csv(static fn (array $x): string => (string) $x['returns']),
                Column::make('exp', 'Expenses')->num()->sortBy('expenses')->cell(static fn (array $x): string => esc(money($x['expenses'], $cur)))->csv(static fn (array $x): string => (string) $x['expenses']),
            ])->defaultSort('d', 'desc')->perPage(10)->unit('days')->compact()->exportable('day-wise-summary')
            ->emptyHtml('<span class="muted">No data in this period</span>');

        $prods = DataTable::make('prods', $this->request)->from($r->productsQuery())->keep($keep)
            ->columns([
                Column::make('name', 'Product')->sortBy('oi.name')->searchIn('oi.name')->cell(static fn (array $x): string => esc($x['name'])),
                Column::make('qty', 'Qty')->num()->sortBy('qty')->cell(static fn (array $x): string => esc(qty($x['qty'], $x['unit'] ?: null)))->csv(static fn (array $x): string => qty($x['qty'])),
                Column::make('rev', 'Sales (before ' . $label . ')')->num()->sortBy('rev')->cell(static fn (array $x): string => esc(money($x['rev'], $cur)))->csv(static fn (array $x): string => (string) round((float) $x['rev'], 2)),
                Column::make('profit', 'Profit')->num()->sortBy('profit')->cell(static fn (array $x): string => '<span class="' . ((float) $x['profit'] < 0 ? 'neg' : '') . '">' . esc(money($x['profit'], $cur)) . '</span>')->csv(static fn (array $x): string => (string) round((float) $x['profit'], 2)),
                Column::make('m', 'Margin')->num()->sortBy('profit / NULLIF(rev, 0)')->cell(static fn (array $x): string => ((float) $x['rev'] > 0 ? (int) round((float) $x['profit'] / (float) $x['rev'] * 100) : 0) . '%')
                    ->csv(static fn (array $x): string => (string) ((float) $x['rev'] > 0 ? round((float) $x['profit'] / (float) $x['rev'] * 100) : 0)),
            ])->defaultSort('rev', 'desc')->perPage(10)->placeholder('Product…')->unit('products')->compact()->exportable('products-sold')
            ->emptyHtml('<span class="muted">No data in this period</span>');

        foreach ([$vatReg, $days, $prods] as $t) {
            if ($csv = $t->csvResponse($this->response)) {
                return $csv;
            }
        }

        // trend: hourly for a single day, daily otherwise (gaps filled with zero)
        $days1 = (strtotime($range->to) - strtotime($range->from)) <= 86400 + 3600;
        $trend = [];
        if ($days1) {
            $hours = $r->byHour();
            if ($hours !== []) {
                for ($h = min(array_keys($hours)); $h <= max(array_keys($hours)); $h++) {
                    $s = $hours[$h]['sales'] ?? 0.0;
                    $trend[] = [sprintf('%02d:00', $h), $s, 0.0, sprintf('%02d:00 · %s · %d invoices', $h, money($s, $cur), $hours[$h]['n'] ?? 0)];
                }
            }
        } else {
            $byDay = [];
            foreach ($r->daysQuery()->orderBy('x.d', 'ASC')->get()->getResultArray() as $d) {
                $byDay[(string) $d['d']] = $d;
            }
            $t = strtotime($range->from);
            $end = min(strtotime($range->to), $t + 92 * 86400);
            for (; $t < $end; $t += 86400) {
                $k = date('Y-m-d', $t);
                $d = $byDay[$k] ?? ['sales' => 0, 'expenses' => 0, 'n' => 0];
                $trend[] = [date('j M', $t), (float) $d['sales'], (float) $d['expenses'], date('D j M', $t) . ' · sales ' . money($d['sales'], $cur) . ' · expenses ' . money($d['expenses'], $cur) . ' · ' . (int) $d['n'] . ' invoices'];
            }
        }

        return $this->page('App\Modules\Reports\Views\index', [
            'title' => 'Reports',
            'trend' => $trend,
            'trendUnit' => $days1 ? 'Sales by hour' : 'Sales & expenses by day',
            'top' => $r->topProducts(8),
            'range' => $range,
            'sum' => $r->summary(),
            'vat' => $r->vatByRate(),
            'vatReg' => $vatReg->render(),
            'days' => $days->render(),
            'prods' => $prods->render(),
            'cats' => $r->byCategory(),
            'methods' => $r->byMethod(),
            'users' => $r->byUser(),
            'staff' => $r->byStaff(),
            'channels' => $r->byChannel(),
            'cur' => $cur,
            'label' => $label,
        ]);
    }
}
