<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Loyalty\Services\LoyaltyService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class LoyaltyController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = new LoyaltyService();
        $cur = $this->cur();
        $rules = LoyaltyService::rules($this->s()->business()['settings'] ?? []);
        $manage = $this->s()->can('customers.manage');
        $members = DataTable::make('lm', $this->request)->from($svc->membersQuery())
            ->columns([
                Column::make('name', 'Customer')->sortBy('c.name')->searchIn('c.name', 'c.phone')->cell(static fn (array $r): string => '<b>' . esc($r['name']) . '</b>')->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('phone', 'Phone')->cell(static fn (array $r): string => esc($r['phone'] ?? '—')),
                Column::make('pts', 'Points')->num()->sortBy('c.points')->cell(static fn (array $r): string => '<b>' . (int) $r['points'] . '</b>')->csv(static fn (array $r): string => (string) $r['points']),
                Column::make('worth', 'Worth')->num()->sortBy('c.points')->cell(static fn (array $r): string => esc(money((int) $r['points'] * $rules['pointValue'], $cur)))->csv(static fn (array $r): string => (string) ((int) $r['points'] * $rules['pointValue'])),
                Column::make('last', 'Last activity')->nowrap()->sortBy('last_at')->cell(static fn (array $r): string => esc(date_str($r['last_at']))),
                Column::make('act', '')->cls('num')->noCsv()->cell(static fn (array $r): string => $manage ? '<button class="btn sm" type="button" data-modal-open="dlg-adjust" data-fill="' . esc((string) json_encode(['#adj-cust' => (int) $r['id'], '#adj-name' => $r['name']]), 'attr') . '">Adjust</button>' : ''),
            ])->defaultSort('pts', 'desc')->placeholder('Customer or phone…')->unit('members')->exportable('loyalty-members')->emptyHtml('<span class="muted">No named customers yet.</span>');
        $history = DataTable::make('lh', $this->request)->from($svc->historyQuery())
            ->columns([
                Column::make('at', 'When')->nowrap()->sortBy('l.created_at')->cell(static fn (array $r): string => esc(datetime_str($r['created_at'])))->csv(static fn (array $r): string => (string) $r['created_at']),
                Column::make('c', 'Customer')->sortBy('c.name')->searchIn('c.name', 'l.note')->cell(static fn (array $r): string => esc($r['customer'])),
                Column::make('pts', 'Points')->num()->sortBy('l.points')->cell(static fn (array $r): string => '<span class="' . ((int) $r['points'] < 0 ? 'neg' : 'pos') . '">' . ((int) $r['points'] > 0 ? '+' : '') . (int) $r['points'] . '</span>')->csv(static fn (array $r): string => (string) $r['points']),
                Column::make('note', 'Note')->cell(static fn (array $r): string => $r['order_id'] ? '<a class="link" href="' . esc(site_url('orders/' . $r['order_id']), 'attr') . '">' . esc((string) $r['note']) . '</a>' : esc((string) $r['note'])),
            ])->defaultSort('at', 'desc')->perPage(10)->placeholder('Customer or note…')->unit('entries')->exportable('loyalty-history')->emptyHtml('<span class="muted">Points appear here after paid sales to named customers.</span>');
        foreach ([$members, $history] as $t) {
            if ($csv = $t->csvResponse($this->response)) {
                return $csv;
            }
        }

        return $this->page('App\Modules\Loyalty\Views\index', ['title' => 'Loyalty', 'members' => $members->render(), 'history' => $history->render(), 'totals' => $svc->totals(), 'rules' => $rules, 'manage' => $manage, 'cur' => $cur]);
    }

    public function rules(): RedirectResponse
    {
        (new LoyaltyService())->saveRules((array) $this->request->getPost());

        return $this->done('Loyalty rules saved.');
    }

    public function adjust(): RedirectResponse
    {
        $d = $this->validated(['customer_id' => 'required|is_natural_no_zero', 'points' => 'required|integer', 'note' => 'permit_empty|max_length[120]']);
        (new LoyaltyService())->adjust((int) $d['customer_id'], (int) $d['points'], (string) ($d['note'] ?? ''));

        return $this->done('Points adjusted.');
    }
}
