<?php

declare(strict_types=1);

namespace App\Modules\Announcements\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Banners shown inside tenant workspaces. Audience: all | plan:<code> | status:<status>.
 */
final class AnnouncementAdmin
{
    public const LEVELS = ['info', 'warn', 'success'];
    public const STATUSES = ['trial' => 'Free trial', 'active' => 'Active', 'past_due' => 'Past due', 'suspended' => 'Suspended', 'expired' => 'Trial ended'];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    public static function listQuery(): BaseBuilder
    {
        return db_connect()->table('announcements a')
            ->select('a.id, a.title, a.body, a.level, a.audience, a.is_active, a.published_at, a.created_at, adm.name AS author')
            ->join('platform_admins adm', 'adm.id = a.created_by', 'left');
    }

    /** @return array<string, string> audience value => label */
    public static function audiences(): array
    {
        $out = ['all' => 'All tenants'];
        foreach (service('catalog')->plans() as $p) {
            $out['plan:' . $p['code']] = $p['name'] . ' plan';
        }
        foreach (self::STATUSES as $k => $l) {
            $out['status:' . $k] = $l . ' accounts';
        }

        return $out;
    }

    public static function audienceLabel(string $a): string
    {
        return self::audiences()[$a] ?? $a;
    }

    /** @param array{title: string, body: string, level: string, audience: string, is_active?: string} $in */
    public function save(?int $id, array $in): int
    {
        if (! array_key_exists($in['audience'], self::audiences())) {
            throw new ValidationException('Choose who sees it.', ['audience' => 'Unknown audience.']);
        }
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $active = ($in['is_active'] ?? '0') === '1';
        $row = ['title' => trim($in['title']), 'body' => trim($in['body']), 'level' => $in['level'], 'audience' => $in['audience'], 'is_active' => $active ? 1 : 0, 'updated_at' => $now];
        if ($id === null) {
            $db->table('announcements')->insert($row + ['published_at' => $active ? $now : null, 'created_by' => $this->actor['id'] ?? null, 'created_at' => $now]);
            $id = (int) $db->insertID();
            service('audit')->platform('Announcement published', $row['title'] . ' · ' . self::audienceLabel($row['audience']) . ($active ? '' : ' · hidden'), null, $this->actor);
        } else {
            $old = $db->table('announcements')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Announcement not found.');
            if ($active && $old['published_at'] === null) {
                $row['published_at'] = $now;
            }
            $db->table('announcements')->where('id', $id)->update($row);
            service('audit')->platform('Announcement changed', $row['title'], null, $this->actor);
        }

        return $id;
    }

    public function toggle(int $id): bool
    {
        $db = db_connect();
        $a = $db->table('announcements')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Announcement not found.');
        $on = ! (bool) $a['is_active'];
        $upd = ['is_active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')];
        if ($on && $a['published_at'] === null) {
            $upd['published_at'] = date('Y-m-d H:i:s');
        }
        $db->table('announcements')->where('id', $id)->update($upd);
        service('audit')->platform($on ? 'Announcement shown' : 'Announcement hidden', (string) $a['title'], null, $this->actor);

        return $on;
    }

    public function delete(int $id): void
    {
        $db = db_connect();
        $a = $db->table('announcements')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Announcement not found.');
        $db->table('announcements')->where('id', $id)->delete();
        service('audit')->platform('Announcement deleted', (string) $a['title'], null, $this->actor);
    }
}
