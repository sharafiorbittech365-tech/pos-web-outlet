<?php

declare(strict_types=1);

namespace App\Modules\Servers\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * POS server administration: the server registry, moving tenants between servers (one tenant or a
 * whole server's tenants) and heartbeat history. Moving goes through ServerAllocation::assign, so
 * every move is recorded in server_assignment_history and the audit log. Data never moves —
 * only which server may serve the tenant.
 */
final class ServerAdmin
{
    public const STATUSES = ['active', 'draining', 'maintenance', 'offline'];

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    public static function listQuery(): BaseBuilder
    {
        return db_connect()->table('pos_servers s')
            ->select('s.id, s.code, s.name, s.base_url, s.region, s.capacity, s.status, s.accepts_new, s.app_version, s.last_heartbeat_at, s.cpu_load, s.mem_used_pct, s.requests_per_min, s.avg_response_ms, COUNT(a.tenant_id) AS tenants')
            ->select('COUNT(a.tenant_id) / s.capacity AS load_ratio', false)
            ->join('tenant_server_assignments a', 'a.server_id = s.id', 'left')
            ->groupBy('s.id');
    }

    public static function isOnline(?string $lastHeartbeat): bool
    {
        return $lastHeartbeat !== null && strtotime($lastHeartbeat) > time() - config(OmniPOS::class)->heartbeatTimeout;
    }

    /** @return array<string, mixed> */
    public static function find(int $id): array
    {
        $row = self::listQuery()->where('s.id', $id)->get()->getRowArray() ?? throw new NotFoundException('POS server not found.');
        $row['tenants'] = (int) $row['tenants'];
        $row['load_pct'] = (int) $row['capacity'] > 0 ? round($row['tenants'] / (int) $row['capacity'] * 100, 1) : 0;
        $row['online'] = self::isOnline($row['last_heartbeat_at']);

        return $row;
    }

    /** Tenants assigned to one server (account data + usage counts only). */
    public static function tenantsQuery(int $serverId): BaseBuilder
    {
        return db_connect()->table('tenant_server_assignments a')
            ->select('t.id, t.name, t.owner_name, a.assigned_at, a.reason, s.status, p.name AS plan_name, u.outlets, u.orders_month')
            ->join('tenants t', 't.id = a.tenant_id')
            ->join('subscriptions s', 's.tenant_id = t.id')
            ->join('plans p', 'p.id = s.plan_id')
            ->join('tenant_usage u', 'u.tenant_id = t.id', 'left')
            ->where('a.server_id', $serverId)->where('t.deleted_at', null);
    }

    public static function historyQuery(int $serverId): BaseBuilder
    {
        return db_connect()->table('server_assignment_history h')
            ->select('h.id, h.tenant_id, h.created_at, h.actor_name, h.reason, h.from_server_id, h.to_server_id, t.name AS tenant_name, f.name AS from_name, d.name AS to_name')
            ->join('tenants t', 't.id = h.tenant_id', 'left')
            ->join('pos_servers f', 'f.id = h.from_server_id', 'left')
            ->join('pos_servers d', 'd.id = h.to_server_id', 'left')
            ->groupStart()->where('h.to_server_id', $serverId)->orWhere('h.from_server_id', $serverId)->groupEnd();
    }

    public static function unassignedCount(): int
    {
        return db_connect()->table('tenants t')->join('tenant_server_assignments a', 'a.tenant_id = t.id', 'left')
            ->where('t.deleted_at', null)->where('a.tenant_id', null)->countAllResults();
    }

    /** @return list<array{code: string, name: string, id: int, tenants: int, capacity: int, status: string}> servers for "move to" pickers */
    public static function options(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'tenants' => (int) $r['tenants'],
            'capacity' => (int) $r['capacity'], 'status' => $r['status']], self::listQuery()->orderBy('s.id')->get()->getResultArray());
    }

    /**
     * Hourly averages for the last 24 hours.
     *
     * @return list<array{hour: string, label: string, cpu: float|null, mem: float|null, rpm: float|null, ms: float|null, n: int}>
     */
    public static function heartbeats(int $serverId): array
    {
        $from = date('Y-m-d H:00:00', strtotime('-23 hours'));
        $rows = db_connect()->table('server_heartbeats')
            ->select("DATE_FORMAT(created_at, '%Y-%m-%d %H') AS h, AVG(cpu_load) AS cpu, AVG(mem_used_pct) AS mem, AVG(requests_per_min) AS rpm, AVG(avg_response_ms) AS ms, COUNT(*) AS n")
            ->where('server_id', $serverId)->where('created_at >=', $from)->groupBy('h')->get()->getResultArray();
        $by = array_column($rows, null, 'h');
        $out = [];
        for ($i = 23; $i >= 0; $i--) {
            $ts = strtotime('-' . $i . ' hours');
            $k = date('Y-m-d H', $ts);
            $r = $by[$k] ?? null;
            $out[] = ['hour' => $k, 'label' => date('H', $ts), 'cpu' => $r ? (float) $r['cpu'] : null, 'mem' => $r ? (float) $r['mem'] : null,
                'rpm' => $r ? (float) $r['rpm'] : null, 'ms' => $r ? (float) $r['ms'] : null, 'n' => $r ? (int) $r['n'] : 0];
        }

        return $out;
    }

    /** @param array{code: string, name: string, base_url: string, region: string, capacity: int|string, status?: string, accepts_new?: mixed} $in */
    public function create(array $in): int
    {
        $db = db_connect();
        $code = strtolower(trim($in['code']));
        if ($db->table('pos_servers')->where('code', $code)->countAllResults() > 0) {
            throw new ValidationException('That server code is taken.', ['code' => 'That code is already used by another server.']);
        }
        $now = date('Y-m-d H:i:s');
        $db->table('pos_servers')->insert([
            'code' => $code, 'name' => trim($in['name']), 'base_url' => rtrim(trim($in['base_url']), '/') . '/', 'region' => trim($in['region']),
            'capacity' => (int) $in['capacity'], 'status' => in_array($in['status'] ?? 'active', self::STATUSES, true) ? $in['status'] ?? 'active' : 'active',
            'accepts_new' => ! empty($in['accepts_new']) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();
        service('audit')->platform('POS server added', $code . ' · ' . trim($in['name']) . ' · capacity ' . (int) $in['capacity'], null, $this->actor);

        return $id;
    }

    /** @param array{name: string, base_url: string, region: string, capacity: int|string, status: string, accepts_new?: mixed} $in */
    public function update(int $id, array $in): void
    {
        $srv = self::find($id);
        $capacity = (int) $in['capacity'];
        if ($capacity < $srv['tenants']) {
            throw new ValidationException('Capacity is below the current tenant count.', ['capacity' => 'At least ' . $srv['tenants'] . ' (tenants on this server now).']);
        }
        $row = [
            'name' => trim($in['name']), 'base_url' => rtrim(trim($in['base_url']), '/') . '/', 'region' => trim($in['region']), 'capacity' => $capacity,
            'status' => $in['status'], 'accepts_new' => ! empty($in['accepts_new']) && $in['status'] === 'active' ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'),
        ];
        db_connect()->table('pos_servers')->where('id', $id)->update($row);
        $changes = [];
        foreach (['name', 'base_url', 'region', 'capacity', 'status', 'accepts_new'] as $k) {
            if ((string) $row[$k] !== (string) $srv[$k]) {
                $changes[] = $k . ' ' . $row[$k];
            }
        }
        service('audit')->platform('POS server changed', $srv['code'] . ' · ' . ($changes ? implode(', ', $changes) : 'no change'), null, $this->actor);
        // server rows are cached per tenant by ServerAllocation::serverFor (30 s) — clear this server's tenants
        foreach (array_column(db_connect()->table('tenant_server_assignments')->select('tenant_id')->where('server_id', $id)->get()->getResultArray(), 'tenant_id') as $tid) {
            service('sharedCache')->delete('omnipos_srv_t' . $tid);
        }
    }

    /** Remove a server that serves no tenant (its assignment history is kept). */
    public function delete(int $id): string
    {
        $srv = self::find($id);
        if ($srv['tenants'] > 0) {
            throw new BusinessRuleException('Move the ' . $srv['tenants'] . ' tenant(s) off ' . $srv['name'] . ' first.');
        }
        db_connect()->table('pos_servers')->where('id', $id)->delete();
        service('audit')->platform('POS server removed', $srv['code'] . ' · ' . $srv['name'], null, $this->actor);

        return (string) $srv['name'];
    }

    /** Free places on a server that may take tenants now. */
    private function assertCanTake(array $target, int $count): void
    {
        if ($target['status'] !== 'active') {
            throw new BusinessRuleException($target['name'] . ' is ' . $target['status'] . ' — only an active server can take tenants.');
        }
        $free = (int) $target['capacity'] - (int) $target['tenants'];
        if ($count > $free) {
            throw new BusinessRuleException($target['name'] . ' has room for ' . max(0, $free) . ' more tenant(s), not ' . $count . '.');
        }
    }

    /** Move one tenant to another server. */
    public function move(int $tenantId, int $serverId, string $reason): string
    {
        $target = self::find($serverId);
        $current = db_connect()->table('tenant_server_assignments')->select('server_id')->where('tenant_id', $tenantId)->get()->getRowArray();
        if ($current !== null && (int) $current['server_id'] === $serverId) {
            throw new BusinessRuleException('The account is already on ' . $target['name'] . '.');
        }
        $this->assertCanTake($target, 1);
        service('servers')->assign($tenantId, $serverId, $this->actor, $reason);

        return (string) $target['name'];
    }

    /**
     * Move several tenants (or every tenant) off one server. Each move is its own transaction with
     * its own history row; a summary row goes to the audit log.
     *
     * @param list<int>|null $tenantIds null = all tenants on the source server
     * @return int tenants moved
     */
    public function drain(int $fromId, int $toId, string $reason, ?array $tenantIds = null): int
    {
        if ($fromId === $toId) {
            throw new BusinessRuleException('Choose a different server to move the tenants to.');
        }
        $from = self::find($fromId);
        $target = self::find($toId);
        $b = db_connect()->table('tenant_server_assignments')->select('tenant_id')->where('server_id', $fromId);
        if ($tenantIds !== null) {
            if ($tenantIds === []) {
                throw new BusinessRuleException('Select at least one tenant to move.');
            }
            $b->whereIn('tenant_id', $tenantIds);
        }
        $ids = array_map('intval', array_column($b->get()->getResultArray(), 'tenant_id'));
        if ($ids === []) {
            throw new BusinessRuleException('No tenants to move.');
        }
        $this->assertCanTake($target, count($ids));
        $servers = service('servers');
        foreach ($ids as $tid) {
            $servers->assign($tid, $toId, $this->actor, $reason);
        }
        service('audit')->platform('POS server drained', count($ids) . ' tenant(s) ' . $from['name'] . ' → ' . $target['name'] . ' · ' . $reason, null, $this->actor);

        return count($ids);
    }
}
