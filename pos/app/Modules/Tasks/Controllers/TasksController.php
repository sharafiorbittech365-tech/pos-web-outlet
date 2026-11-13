<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\Tasks\Services\TaskService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class TasksController extends BackOfficeController
{
    public function index(): string|ResponseInterface
    {
        $svc = new TaskService();
        $manage = $this->s()->can('tasks.manage');
        $all = $manage && $this->getStr('scope', 4) === 'all';
        $status = in_array($s = $this->getStr('status', 8), ['open', 'done', 'overdue', 'any'], true) ? $s : 'open';
        $me = $this->s()->userId();
        $table = DataTable::make('tasks', $this->request)->from($svc->listQuery($all, $status))->keep(['scope' => $all ? 'all' : '', 'status' => $status === 'open' ? '' : $status])
            ->rowAttr(static fn (array $r): string => $r['status'] === 'done' ? 'class="row-off"' : '')
            ->columns([
                Column::make('done', '')->width('44px')->noCsv()->cell(static fn (array $r): string => ($manage || (int) $r['assignee_id'] === $me)
                    ? '<form class="inline" method="post" action="' . esc(site_url('tasks/' . $r['id'] . '/toggle'), 'attr') . '">' . csrf_field() . '<button class="task-check" type="submit" aria-label="' . ($r['status'] === 'done' ? 'Mark as not done' : 'Mark done') . '">' . ($r['status'] === 'done' ? icon('check', 'sm') : '') . '</button></form>' : ''),
                Column::make('title', 'Task')->sortBy('t.title')->searchIn('t.title', 'u.name')->cell(static fn (array $r): string => '<b>' . esc($r['title']) . '</b>' . ($r['source'] === 'low_stock' ? ' ' . tag_html('Stock alert', 'warn') : '') . ($r['creator'] ? '<br><small class="muted">by ' . esc($r['creator']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['title']),
                Column::make('who', 'Assigned to')->sortBy('u.name')->cell(static fn (array $r): string => $r['assignee'] ? '<span class="av sm">' . esc(initials($r['assignee'])) . '</span> ' . esc($r['assignee']) : '<span class="muted">Anyone</span>')->csv(static fn (array $r): string => (string) $r['assignee']),
                Column::make('due', 'Due')->nowrap()->sortBy('t.due_at')->cell(static fn (array $r): string => $r['due_at'] ? esc(datetime_str($r['due_at'])) . ($r['status'] === 'open' && strtotime($r['due_at']) < time() ? ' ' . tag_html('Overdue', 'danger') : '') : '<span class="muted">—</span>')->csv(static fn (array $r): string => (string) $r['due_at']),
                Column::make('status', 'Status')->sortBy('t.status')->cell(static fn (array $r): string => $r['status'] === 'done' ? tag_html('Done', 'ok') . '<br><small class="muted">' . esc(datetime_str($r['done_at'])) . '</small>' : tag_html('Open', 'primary'))->csv(static fn (array $r): string => (string) $r['status']),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => $manage ? '<form class="inline" method="post" action="' . esc(site_url('tasks/' . $r['id'] . '/delete'), 'attr') . '" data-confirm="Delete this task?">' . csrf_field() . '<button class="x-btn" type="submit" aria-label="Delete">' . icon('trash', 'sm') . '</button></form>' : ''),
            ])->defaultSort('due')->placeholder('Task or person…')->unit('tasks')->exportable('tasks')
            ->emptyHtml('<div class="kcol-empty">' . icon('circle-check-big', 'sm') . ' Nothing here — all caught up</div>');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Tasks\Views\index', ['title' => 'Tasks', 'table' => $table->render(), 'counts' => $svc->counts($all), 'all' => $all, 'status' => $status, 'manage' => $manage, 'team' => $manage ? $svc->team() : [], 'me' => $me]);
    }

    public function store(): RedirectResponse
    {
        (new TaskService())->save(null, (array) $this->request->getPost());

        return $this->done('Task assigned.');
    }

    public function update(int $id): RedirectResponse
    {
        (new TaskService())->save($id, (array) $this->request->getPost());

        return $this->done('Task saved.');
    }

    public function toggle(int $id): RedirectResponse
    {
        $st = (new TaskService())->toggle($id);

        return $this->done($st === 'done' ? 'Task done — nice work.' : 'Task re-opened.');
    }

    public function delete(int $id): RedirectResponse
    {
        (new TaskService())->delete($id);

        return $this->done('Task deleted.');
    }

    public function auto(): RedirectResponse
    {
        $n = (new TaskService())->fromLowStock((int) $this->request->getPost('assignee_id'));

        return $this->done($n > 0 ? $n . ' restock task' . ($n === 1 ? '' : 's') . ' created from stock alerts.' : 'No new stock alerts — nothing to create.');
    }
}
