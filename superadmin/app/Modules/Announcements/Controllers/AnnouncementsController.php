<?php

declare(strict_types=1);

namespace App\Modules\Announcements\Controllers;

use App\Controllers\BaseController;
use App\Modules\Announcements\Services\AnnouncementAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class AnnouncementsController extends BaseController
{
    private const RULES = [
        'title' => 'required|max_length[160]',
        'body' => 'required|max_length[2000]',
        'level' => 'required|in_list[info,warn,success]',
        'audience' => 'required|max_length[48]',
        'is_active' => 'permit_empty|in_list[0,1]',
    ];

    public function index(): string|ResponseInterface
    {
        $audiences = AnnouncementAdmin::audiences();
        $table = DataTable::make('ann', $this->request)
            ->from(AnnouncementAdmin::listQuery())
            ->columns([
                Column::make('title', 'Announcement')->sortBy('a.title')->searchIn('a.title', 'a.body')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['title']) . '</b><br><small class="muted">' . esc(mb_strimwidth((string) $r['body'], 0, 140, '…')) . '</small>')
                    ->csv(static fn (array $r): string => $r['title'] . ' — ' . $r['body']),
                Column::make('level', 'Type')->sortBy('a.level')->cell(static fn (array $r): string => tag_html((string) $r['level'], $r['level'] === 'warn' ? 'warn' : ($r['level'] === 'success' ? 'ok' : 'primary'))),
                Column::make('audience', 'Shown to')->sortBy('a.audience')->cell(static fn (array $r): string => esc($audiences[$r['audience']] ?? $r['audience']))
                    ->csv(static fn (array $r): string => (string) ($audiences[$r['audience']] ?? $r['audience'])),
                Column::make('by', 'By')->sortBy('a.created_at')->cell(static fn (array $r): string => esc($r['author'] ?? '—') . '<br><small class="muted">' . esc(date_str($r['published_at'] ?? $r['created_at'])) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['author']),
                Column::make('live', 'Live')->sortBy('a.is_active')
                    ->cell(static fn (array $r): string => '<form class="inline" method="post" action="' . esc(site_url('announcements/' . $r['id'] . '/toggle'), 'attr') . '">' . csrf_field()
                        . '<button class="btn sm ' . ((int) $r['is_active'] ? 'primary' : '') . '" type="submit" title="' . ((int) $r['is_active'] ? 'Live — click to hide' : 'Hidden — click to show') . '">' . icon((int) $r['is_active'] ? 'eye' : 'pause', 'sm') . ((int) $r['is_active'] ? 'Live' : 'Hidden') . '</button></form>')
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'live' : 'hidden'),
                Column::make('act', '')->num()->nowrap()->noCsv()
                    ->cell(static function (array $r): string {
                        return '<button class="btn sm" type="button" data-modal-open="ann-' . (int) $r['id'] . '">' . icon('edit', 'sm') . 'Edit</button> '
                            . '<form class="inline" method="post" action="' . esc(site_url('announcements/' . $r['id'] . '/delete'), 'attr') . '" data-confirm="' . esc('Delete "' . $r['title'] . '"?', 'attr') . '">' . csrf_field()
                            . '<button class="btn sm danger" type="submit" aria-label="Delete">' . icon('trash', 'sm') . '</button></form>';
                    }),
            ])
            ->defaultSort('by', 'desc')
            ->placeholder('Title or message…')
            ->unit('announcements')
            ->emptyHtml('<span class="muted">No announcements yet.</span>')
            ->exportable('announcements');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }
        return $this->page('App\Modules\Announcements\Views\index', ['title' => 'Announcements', 'table' => $table->render(), 'rows' => $table->result()['rows'], 'audiences' => $audiences]);
    }

    public function create(): RedirectResponse
    {
        (new AnnouncementAdmin($this->actor()))->save(null, $this->validated(self::RULES));

        return $this->done('Announcement saved.', site_url('announcements'));
    }

    public function update(int $id): RedirectResponse
    {
        (new AnnouncementAdmin($this->actor()))->save($id, $this->validated(self::RULES));

        return $this->done('Announcement saved.', site_url('announcements'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $on = (new AnnouncementAdmin($this->actor()))->toggle($id);

        return $this->done($on ? 'Announcement is live.' : 'Announcement hidden.', site_url('announcements'));
    }

    public function delete(int $id): RedirectResponse
    {
        (new AnnouncementAdmin($this->actor()))->delete($id);

        return $this->done('Announcement deleted.', site_url('announcements'));
    }
}
