<?php

declare(strict_types=1);

namespace App\Modules\Register\Controllers;

use App\Controllers\BaseController;
use App\Modules\Register\Services\RegisterService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class RegisterController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $svc = RegisterService::forCurrent();
        $cur = service('posSession')->currency();
        $reg = $svc->current();
        $table = DataTable::make('reg', $this->request)
            ->from($svc->historyQuery()->where('r.closed_at IS NOT NULL', null, false))
            ->noSearch()
            ->columns([
                Column::make('opened', 'Opened')->nowrap()->sortBy('r.opened_at')->cell(static fn ($r) => esc(datetime_str($r['opened_at'])))->csv(static fn ($r) => (string) $r['opened_at']),
                Column::make('closed', 'Closed')->nowrap()->sortBy('r.closed_at')->cell(static fn ($r) => esc(datetime_str($r['closed_at'])))->csv(static fn ($r) => (string) $r['closed_at']),
                Column::make('user', 'By')->cell(static fn ($r) => esc($r['user_name'] ?: '—'))->csv(static fn ($r) => (string) $r['user_name']),
                Column::make('opening', 'Opening')->num()->sortBy('r.opening_cash')->cell(static fn ($r) => esc(money($r['opening_cash'], $cur)))->csv(static fn ($r) => (string) $r['opening_cash']),
                Column::make('expected', 'Expected')->num()->sortBy('r.expected_cash')->cell(static fn ($r) => esc(money($r['expected_cash'], $cur)))->csv(static fn ($r) => (string) $r['expected_cash']),
                Column::make('counted', 'Counted')->num()->sortBy('r.counted_cash')->cell(static fn ($r) => esc(money($r['counted_cash'], $cur)))->csv(static fn ($r) => (string) $r['counted_cash']),
                Column::make('diff', 'Difference')->num()->sortBy('diff')
                    ->cell(static fn ($r) => '<b class="' . ((float) $r['diff'] < 0 ? 'diff-neg' : 'diff-pos') . '">' . esc(money($r['diff'], $cur)) . '</b>')->csv(static fn ($r) => (string) $r['diff']),
                Column::make('note', 'Note')->cell(static fn ($r) => esc($r['note'] ?: ''))->csv(static fn ($r) => (string) $r['note']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn ($r) => '<a class="btn sm" href="' . esc(site_url('register/' . $r['id']), 'attr') . '">Report</a>'),
            ])
            ->defaultSort('closed', 'desc')
            ->unit('closings')
            ->emptyHtml('<span class="muted">No closed register sessions yet.</span>')
            ->exportable('register-closings');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Register\Views\index', [
            'title' => 'Register', 'reg' => $reg, 'R' => $reg ? $svc->summary($reg) : null, 'table' => $table->render(),
        ]);
    }

    public function show(int $id): string
    {
        $svc = RegisterService::forCurrent();
        $reg = $svc->find($id);

        return $this->page('App\Modules\Register\Views\report', ['title' => 'Register report', 'reg' => $reg, 'R' => $svc->summary($reg)]);
    }

    public function open(): RedirectResponse
    {
        $data = $this->validated(['opening_cash' => 'required|decimal|greater_than_equal_to[0]', 'note' => 'permit_empty|max_length[200]']);
        RegisterService::forCurrent()->open((float) $data['opening_cash'], (string) ($data['note'] ?? ''));

        return redirect()->to(site_url('register'))->with('success', 'Register opened.');
    }

    public function close(): RedirectResponse
    {
        $data = $this->validated(['counted_cash' => 'required|decimal|greater_than_equal_to[0]', 'note' => 'permit_empty|max_length[200]']);
        $r = RegisterService::forCurrent()->close((float) $data['counted_cash'], (string) ($data['note'] ?? ''));
        $cur = service('posSession')->currency();
        $diff = $r['difference'];

        return redirect()->to(site_url('register'))->with('success', 'Register closed. Expected ' . money($r['expected'], $cur) . ', counted ' . money($r['counted'], $cur)
            . ($diff == 0.0 ? ' — the drawer matches.' : ' — ' . ($diff < 0 ? 'short by ' : 'over by ') . money(abs($diff), $cur) . '.'));
    }
}
