<?php

declare(strict_types=1);

use App\Modules\Floor\Services\FloorService;
use App\Modules\Kitchen\Services\KitchenService;
use App\Modules\Sales\Services\TillService;
use Tests\Support\PosA\TestCase;

/**
 * Kitchen board and tables on a restaurant outlet.
 *
 * @internal
 */
final class KitchenTest extends TestCase
{
    protected function businessType(): string
    {
        return 'restaurant';
    }

    private function kitchenOrder(string $token, string $table = ''): array
    {
        $ps = db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('is_active', 1)->orderBy('id')->limit(2)->get()->getResultArray();
        $cart = ['lines' => [['product_id' => (int) $ps[0]['id'], 'qty' => 2], ['product_id' => (int) $ps[1]['id'], 'qty' => 1]], 'kitchen' => true, 'table_ref' => $table];

        return (new TillService())->openOrder($cart, $token)['order'];
    }

    public function testTicketBecomesReadyWhenEveryItemIsTicked(): void
    {
        $o = $this->kitchenOrder('tok-kds-00001');
        $this->assertSame('new', $o['kitchen_status']);
        $kds = new KitchenService($this->fx['business_id']);
        $tickets = $kds->tickets();
        $this->assertCount(1, $tickets);
        $backlog = $kds->backlog($tickets);
        $this->assertEqualsWithDelta(3, array_sum(array_column($backlog, 'qty')), 0.001);

        $first = $kds->toggle((int) $o['items'][0]['id'], true);
        $this->assertSame('cooking', $first['status']);
        $this->assertEqualsWithDelta(1, array_sum(array_column($kds->backlog($kds->tickets()), 'qty')), 0.001);
        // still on the board while one item is open
        $this->assertCount(1, array_filter($kds->tickets(), static fn ($t) => $t['kitchen_status'] !== 'served'));

        $all = $kds->toggle((int) $o['items'][1]['id'], true);
        $this->assertSame('ready', $all['status']);
        $this->assertSame([], $kds->backlog($kds->tickets()));

        // unticking moves it back to cooking
        $this->assertSame('cooking', $kds->toggle((int) $o['items'][1]['id'], false)['status']);
        $kds->setStatus((int) $o['id'], 'served');
        $this->assertSame('served', db_connect()->table('orders')->select('kitchen_status')->where('id', $o['id'])->get()->getRow('kitchen_status'));
    }

    public function testFeedEndpointAndToggleRoute(): void
    {
        $o = $this->kitchenOrder('tok-kds-00002');
        $r = $this->getPage('kitchen');
        $r->assertOK();
        $r->assertSee('data-kds-item');
        $res = $this->postForm('kitchen/items/' . $o['items'][0]['id'], ['done' => '1']);
        $res->assertRedirect();
        $this->assertSame(1, (int) db_connect()->table('order_items')->select('kds_done')->where('id', $o['items'][0]['id'])->get()->getRow('kds_done'));
    }

    public function testTableIsBusyWhileItsOrderIsOpen(): void
    {
        $t = db_connect()->table('dining_tables')->where('business_id', $this->fx['business_id'])->orderBy('id')->get()->getRowArray();
        $this->assertNotNull($t);
        $floor = new FloorService($this->fx['business_id']);
        $this->assertSame('free', $floor->tables()[0]['state']);
        $o = $this->kitchenOrder('tok-floor-0001', $t['name']);
        $this->assertSame($t['name'], $o['table_ref']);
        $this->assertSame('busy', $floor->tables()[0]['state']);
        $__r = $this->getPage('floor');
        $__r->assertOK();
        $__r->assertSee($o['doc_no']);
    }

    public function testHourlyRoomChargeIsAddedFromCheckIn(): void
    {
        $db = db_connect();
        $t = $db->table('dining_tables')->where('business_id', $this->fx['business_id'])->orderBy('id')->get()->getRowArray();
        $db->table('dining_tables')->where('id', $t['id'])->update(['hourly_rate' => 600]);
        $b = $db->table('businesses')->where('id', $this->fx['business_id'])->get()->getRowArray();
        $s = json_decode($b['settings'], true);
        $s['features']['hourly'] = true;
        $s['features']['minMinutes'] = 0;
        $db->table('businesses')->where('id', $b['id'])->update(['settings' => json_encode($s), 'tax_rate' => 0, 'service_charge_pct' => 0]);
        \App\Libraries\PosSession::forgetBusiness((int) $b['id']);
        // hourly billing is a room feature: a restaurant gets it only when Super Admin offers it to the account
        $db->table('tenants')->where('id', (int) $b['tenant_id'])->update(['feature_overrides' => json_encode(['pos.hourly' => true])]);
        service('moduleAccess')->invalidate((int) $b['tenant_id']);
        $this->act('owner');
        $p = $db->table('products')->where('business_id', $b['id'])->where('is_active', 1)->orderBy('id')->get()->getRowArray();
        $q = (new TillService())->quote(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]], 'table_ref' => $t['name'], 'checkin' => time() - 30 * 60]);
        $this->assertSame(30, $q['meta']['minutes']);
        $this->assertEqualsWithDelta(300, $q['totals']['hourly'], 0.01);
    }

    public function testOpenOrderIsPaidLaterWithChange(): void
    {
        $o = $this->kitchenOrder('tok-open-0001');
        $this->assertSame('open', $o['status']);
        $r = (new \App\Modules\Orders\Services\OrderActions())->pay((int) $o['id'], [['method' => 'cash', 'amount' => (float) $o['total'] + 50]]);
        $this->assertSame('paid', $r['order']['status']);
        $this->assertEqualsWithDelta(50, $r['change'], 0.01);
    }

}
