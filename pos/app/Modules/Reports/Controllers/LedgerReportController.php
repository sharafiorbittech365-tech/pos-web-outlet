<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\DateRange;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/**
 * Credit ledger (customer due book: credit sales, payments, returns) and exchange ledger (credit kept from returned
 * items and used on new invoices) for a date range — one entry per row, with totals and who still holds a balance.
 */
final class LedgerReportController extends BackOfficeController
{
    public function credit(): string|ResponseInterface
    {
        return $this->build('credit');
    }

    public function exchange(): string|ResponseInterface
    {
        return $this->build('exchange');
    }

    private function build(string $kind): string|ResponseInterface
    {
        $range = DateRange::fromRequest($this->request, 'month');
        $db = db_connect();
        $bid = $this->s()->businessId();
        $cur = $this->cur();
        $code = (string) ($this->s()->business()['currency_code'] ?? 'BDT');
        $isEx = $kind === 'exchange';
        $tbl = $isEx ? 'exchange_ledger' : 'customer_ledger';

        $base = static fn () => $db->table($tbl . ' l')->join('customers c', 'c.id = l.customer_id')->join('orders o', "o.id = l.ref_id AND l.ref_type = 'order'", 'left')
            ->where('l.business_id', $bid)->where('l.created_at >=', $range->from)->where('l.created_at <', $range->to);

        $in = (float) ($base()->select('COALESCE(SUM(CASE WHEN l.amount > 0 THEN l.amount ELSE 0 END), 0) AS v', false)->get()->getRow('v') ?? 0);
        $out = (float) ($base()->select('COALESCE(SUM(CASE WHEN l.amount < 0 THEN -l.amount ELSE 0 END), 0) AS v', false)->get()->getRow('v') ?? 0);
        $col = $isEx ? 'exchange_balance' : 'balance_due';
        $hold = $db->table('customers')->select("COUNT(*) AS n, COALESCE(SUM({$col}), 0) AS v", false)->where('business_id', $bid)->where($col . ' >', 0)->get()->getRowArray();
        $top = $db->table('customers')->select("id, name, phone, {$col} AS bal")->where('business_id', $bid)->where($col . ' >', 0)->orderBy($col, 'DESC')->limit(8)->get()->getResultArray();

        $table = DataTable::make('lg', $this->request)->from($base()->select('l.id, l.customer_id, l.entry_type, l.amount, l.balance_after, l.ref_id, l.note, l.created_at, ' . ($isEx ? 'NULL AS method' : 'l.method') . ', c.name AS customer, o.doc_no', false))
            ->keep($range->params())->placeholder('Customer or note…')
            ->columns([
                Column::make('at', 'Date')->nowrap()->sortBy('l.id')->cell(static fn ($r) => esc(datetime_str($r['created_at'])))->csv(static fn ($r) => (string) $r['created_at']),
                Column::make('cust', 'Customer')->sortBy('c.name')->searchIn('c.name', 'l.note', 'o.doc_no')
                    ->cell(static fn ($r) => '<a class="link" href="' . esc(site_url('customers/' . $r['customer_id']), 'attr') . '">' . esc($r['customer']) . '</a>')->csv(static fn ($r) => (string) $r['customer']),
                Column::make('what', 'Particulars')->cell(static function ($r) use ($isEx, $code): string {
                    $doc = $r['doc_no'] ? ' · <a class="link" href="' . esc(site_url('orders/' . $r['ref_id']), 'attr') . '">' . esc($r['doc_no']) . '</a>' : '';
                    $label = $isEx
                        ? match ($r['entry_type']) { 'issue' => 'Credit from returned item' . $doc, 'redeem' => 'Used to pay invoice' . $doc, default => 'Adjustment' }
                        : match ($r['entry_type']) { 'sale' => 'Credit sale' . $doc, 'payment' => 'Payment received' . ($r['method'] ? ' · ' . esc(pay_label($r['method'], $code)) : '') . $doc, 'opening' => 'Previous due', 'return' => 'Return' . $doc, default => 'Adjustment' };

                    return $label . ($r['note'] ? '<br><small class="muted">' . esc($r['note']) . '</small>' : '');
                })->csv(static fn ($r) => $r['entry_type'] . ' ' . $r['note']),
                Column::make('in', $isEx ? 'Credit given' : 'Debit (due)')->num()->cell(static fn ($r) => (float) $r['amount'] > 0 ? esc(money($r['amount'], $cur)) : '')->csv(static fn ($r) => (float) $r['amount'] > 0 ? (string) $r['amount'] : ''),
                Column::make('out', $isEx ? 'Used' : 'Credit (paid)')->num()->cell(static fn ($r) => (float) $r['amount'] < 0 ? esc(money(-(float) $r['amount'], $cur)) : '')->csv(static fn ($r) => (float) $r['amount'] < 0 ? (string) -(float) $r['amount'] : ''),
                Column::make('bal', 'Balance')->num()->cell(static fn ($r) => '<b>' . esc(money($r['balance_after'], $cur)) . '</b>')->csv(static fn ($r) => (string) $r['balance_after']),
            ])->defaultSort('at', 'desc')->perPage(25)->unit('entries')->compact()->exportable($kind . '-ledger')
            ->emptyHtml('<span class="muted">No entries in this period.</span>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Reports\Views\ledger', [
            'title' => $isEx ? 'Exchange ledger' : 'Credit ledger', 'range' => $range, 'kind' => $kind, 'cur' => $cur, 'in' => $in, 'out' => $out, 'hold' => $hold, 'top' => $top, 'table' => $table->render(),
        ]);
    }
}
