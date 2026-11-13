<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * POS server allocation: move one tenant, drain a server, capacity and status rules.
 *
 * @internal
 */
final class ServersTest extends FeatureTestCase
{
    private function serverOf(int $tenantId): int
    {
        return (int) db_connect()->table('tenant_server_assignments')->where('tenant_id', $tenantId)->get()->getRow('server_id');
    }

    public function testMoveOneTenantRecordsHistoryAndAudit(): void
    {
        $tid = $this->makeTenant();
        $from = $this->serverOf($tid);
        $to = $from === $this->serverId('pos-1') ? $this->serverId('pos-2') : $this->serverId('pos-1');
        $this->postForm('tenants/' . $tid . '/server', ['server_id' => $to, 'reason' => 'Rebalancing'])->assertRedirect();
        $this->assertSame($to, $this->serverOf($tid));
        $this->seeInDatabase('server_assignment_history', ['tenant_id' => $tid, 'from_server_id' => $from, 'to_server_id' => $to, 'reason' => 'Rebalancing']);
        $this->seeInDatabase('platform_audit', ['tenant_id' => $tid, 'action' => 'POS server changed']);
        $this->assertSame($to, (int) service('servers')->serverFor($tid)['id'], 'the allocation cache was cleared');
        // moving to the same server again is refused
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('tenants/' . $tid . '/server', ['server_id' => $to, 'reason' => 'again']));
    }

    public function testCreateServerAndDrainSelectedThenAllTenants(): void
    {
        $this->postForm('servers', ['code' => 'pos-t' . random_int(100, 999), 'name' => 'Test Server', 'base_url' => 'http://localhost:8099/', 'region' => 'dhaka',
            'capacity' => '50', 'status' => 'active', 'accepts_new' => '0'])->assertRedirect();
        $new = (int) db_connect()->table('pos_servers')->where('name', 'Test Server')->orderBy('id', 'desc')->get()->getRow('id');
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $src = $this->serverOf($a);
        db_connect()->table('tenant_server_assignments')->where('tenant_id', $b)->update(['server_id' => $src]);

        $this->postForm('servers/' . $src . '/drain', ['to' => $new, 'reason' => 'Upgrade', 'scope' => 'selected', 'tenant_ids' => [$a]])->assertRedirect();
        $this->assertSame($new, $this->serverOf($a));
        $this->assertSame($src, $this->serverOf($b));

        $this->postForm('servers/' . $new . '/drain', ['to' => $src, 'reason' => 'Back', 'scope' => 'all'])->assertRedirect();
        $this->assertSame($src, $this->serverOf($a));
        $this->seeInDatabase('platform_audit', ['action' => 'POS server drained']);
        $this->getPage('servers/' . $new)->assertOK();
        $this->postForm('servers/' . $new . '/delete')->assertRedirect();
        $this->dontSeeInDatabase('pos_servers', ['id' => $new]);
    }

    public function testTargetMustBeActiveWithFreeCapacity(): void
    {
        $tid = $this->makeTenant();
        $from = $this->serverOf($tid);
        $to = $from === $this->serverId('pos-1') ? $this->serverId('pos-2') : $this->serverId('pos-1');
        $db = db_connect();
        $db->table('pos_servers')->where('id', $to)->update(['status' => 'maintenance']);
        try {
            $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('tenants/' . $tid . '/server', ['server_id' => $to, 'reason' => 'x']));
        } finally {
            $db->table('pos_servers')->where('id', $to)->update(['status' => 'active']);
        }
        $count = $db->table('tenant_server_assignments')->where('server_id', $to)->countAllResults();
        $capacity = (int) $db->table('pos_servers')->where('id', $to)->get()->getRow('capacity');
        $db->table('pos_servers')->where('id', $to)->update(['capacity' => max(1, $count)]);
        try {
            $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('tenants/' . $tid . '/server', ['server_id' => $to, 'reason' => 'x']));
        } finally {
            $db->table('pos_servers')->where('id', $to)->update(['capacity' => $capacity]);
        }
        $this->assertSame($from, $this->serverOf($tid));
    }

    public function testServerPagesRender(): void
    {
        $res = $this->getPage('servers');
        $res->assertOK();
        $res->assertSee('POS Server 1');
        $this->getPage('servers/new')->assertOK();
        $res = $this->getPage('servers/' . $this->serverId('pos-1'));
        $res->assertOK();
        $res->assertSee('Assignment history');
    }
}
