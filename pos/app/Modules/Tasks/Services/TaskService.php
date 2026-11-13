<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Models\Generated\TasksModel;

/**
 * Team tasks of the current outlet. Managers (tasks.manage) assign and see everyone's tasks;
 * others see and complete their own. "Auto-create from alerts" opens one task per low / out-of-stock product.
 */
final class TaskService
{
    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    private function manage(): bool
    {
        return service('posSession')->can('tasks.manage');
    }

    public function listQuery(bool $all, string $status): BaseBuilder
    {
        $b = db_connect()->table('tasks t')->select('t.id, t.title, t.assignee_id, t.due_at, t.status, t.source, t.created_at, t.done_at, u.name AS assignee, c.name AS creator')
            ->join('users u', 'u.id = t.assignee_id', 'left')->join('users c', 'c.id = t.created_by', 'left')
            ->where('t.business_id', $this->bid());
        if (! $all || ! $this->manage()) {
            $b->where('t.assignee_id', service('tenant')->userId());
        }
        if (in_array($status, ['open', 'done'], true)) {
            $b->where('t.status', $status);
        } elseif ($status === 'overdue') {
            $b->where('t.status', 'open')->where('t.due_at <', date('Y-m-d H:i:s'));
        }

        return $b;
    }

    /** @return array{open: int, today: int, overdue: int, done: int} */
    public function counts(bool $all): array
    {
        $uid = service('tenant')->userId();
        $sql = 'SELECT SUM(status = \'open\') AS o, SUM(status = \'open\' AND due_at >= ? AND due_at < ?) AS t, SUM(status = \'open\' AND due_at < ?) AS od, SUM(status = \'done\') AS d FROM tasks WHERE business_id = ?';
        $p = [date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day')), date('Y-m-d H:i:s'), $this->bid()];
        if (! $all || ! $this->manage()) {
            $sql .= ' AND assignee_id = ?';
            $p[] = $uid;
        }
        $r = db_connect()->query($sql, $p)->getRowArray() ?? [];

        return ['open' => (int) ($r['o'] ?? 0), 'today' => (int) ($r['t'] ?? 0), 'overdue' => (int) ($r['od'] ?? 0), 'done' => (int) ($r['d'] ?? 0)];
    }

    /** @return list<array{id: int, name: string}> active team members of this outlet */
    public function team(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'] . ' — ' . $r['role']],
            db_connect()->table('users u')->select('u.id, u.name, r.name AS role')->join('user_businesses ub', 'ub.user_id = u.id')->join('roles r', 'r.id = ub.role_id')
                ->where('ub.business_id', $this->bid())->where('u.is_active', 1)->orderBy('u.name')->get()->getResultArray());
    }

    /** @param array<string, mixed> $in title, assignee_id, due_at */
    public function save(?int $id, array $in): int
    {
        if (! $this->manage()) {
            throw new PermissionDeniedException('Only managers assign tasks.');
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 200);
        if ($title === '') {
            throw new ValidationException('Describe the task.', ['title' => 'Task is required.']);
        }
        $assignee = (int) ($in['assignee_id'] ?? 0);
        if ($assignee > 0 && ! in_array($assignee, array_column($this->team(), 'id'), true)) {
            throw new ValidationException('Choose someone of this outlet.', ['assignee_id' => 'Not in this outlet\'s team.']);
        }
        $due = trim((string) ($in['due_at'] ?? ''));
        $dueAt = $due !== '' && strtotime($due) !== false ? date('Y-m-d H:i:s', (int) strtotime($due)) : null;
        $m = new TasksModel();
        $row = ['title' => $title, 'assignee_id' => $assignee ?: null, 'due_at' => $dueAt];
        if ($id !== null) {
            $m->find($id) ?? throw new NotFoundException('Task not found.');
            $m->update($id, $row);

            return $id;
        }
        $newId = (int) $m->insert($row + ['status' => 'open', 'source' => 'manual', 'created_by' => service('tenant')->userId()]);
        service('audit')->activity('Task assigned', $title);

        return $newId;
    }

    public function toggle(int $id): string
    {
        $m = new TasksModel();
        $t = $m->find($id) ?? throw new NotFoundException('Task not found.');
        if (! $this->manage() && (int) $t['assignee_id'] !== service('tenant')->userId()) {
            throw new PermissionDeniedException('This task is assigned to someone else.');
        }
        $done = $t['status'] !== 'done';
        $m->update($id, ['status' => $done ? 'done' : 'open', 'done_at' => $done ? date('Y-m-d H:i:s') : null]);
        if ($done) {
            service('audit')->activity('Task done', (string) $t['title']);
        }

        return $done ? 'done' : 'open';
    }

    public function delete(int $id): void
    {
        if (! $this->manage()) {
            throw new PermissionDeniedException('Only managers delete tasks.');
        }
        $m = new TasksModel();
        $m->find($id) ?? throw new NotFoundException('Task not found.');
        $m->delete($id);
    }

    /** One open task per low / out-of-stock product (skips products that already have one). Returns how many were created. */
    public function fromLowStock(int $assigneeId = 0): int
    {
        $db = db_connect();
        $bid = $this->bid();
        $low = (float) (service('posSession')->business()['low_stock_alert'] ?? 0);
        $rows = $db->query("SELECT id, name, stock_qty, unit FROM products WHERE business_id = ? AND deleted_at IS NULL AND is_active = 1 AND stock_type <> 'none'
                             AND stock_qty <= COALESCE(low_stock_at, ?) ORDER BY stock_qty LIMIT 50", [$bid, $low])->getResultArray();
        if ($rows === []) {
            return 0;
        }
        $open = array_column($db->table('tasks')->select('title')->where('business_id', $bid)->where('status', 'open')->where('source', 'low_stock')->get()->getResultArray(), 'title');
        $m = new TasksModel();
        $n = 0;
        foreach ($rows as $p) {
            $title = ((float) $p['stock_qty'] <= 0 ? 'Restock (out of stock): ' : 'Restock (low): ') . $p['name'];
            if (in_array($title, $open, true)) {
                continue;
            }
            $m->insert(['title' => mb_substr($title, 0, 200), 'assignee_id' => $assigneeId ?: service('tenant')->userId(), 'due_at' => date('Y-m-d 18:00:00'), 'status' => 'open', 'source' => 'low_stock', 'created_by' => service('tenant')->userId()]);
            $n++;
        }
        if ($n > 0) {
            service('audit')->activity('Tasks from alerts', $n . ' restock tasks');
        }

        return $n;
    }
}
