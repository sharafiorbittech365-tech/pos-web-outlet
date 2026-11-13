<?php

declare(strict_types=1);

use App\Modules\BackOffice\Support\NavCounts;
use App\Modules\Sales\Services\TillService;
use Tests\Support\PosA\TestCase;

/**
 * Menu unread badges: online orders (not POS), table orders, pending online bookings, new kitchen tickets.
 *
 * @internal
 */
final class NavCountsTest extends TestCase
{
    protected function businessType(): string
    {
        return 'restaurant';
    }

    private function order(string $token, string $table = ''): array
    {
        $p = db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('is_active', 1)->orderBy('id')->get()->getRowArray();

        return (new TillService())->openOrder(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]], 'kitchen' => true, 'table_ref' => $table], $token)['order'];
    }

    private function asOnline(int $orderId): void
    {
        db_connect()->table('orders')->where('id', $orderId)->update(['channel' => 'web', 'acknowledged_at' => null]);
    }

    public function testCountsAndAcknowledgement(): void
    {
        $pos = $this->order('tok-nav-00001');
        $c = NavCounts::forCurrentUser();
        $this->assertSame(0, $c['orders'], 'sales made at the POS till never count as new orders');
        $this->assertSame(1, $c['kitchen']);

        $web = $this->order('tok-nav-00002');
        $this->asOnline((int) $web['id']);
        $tname = (string) db_connect()->table('dining_tables')->where('business_id', $this->fx['business_id'])->get()->getRow('name');
        $table = $this->order('tok-nav-00003', $tname);
        $this->asOnline((int) $table['id']);
        db_connect()->table('bookings')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'reference' => 'BKNAV' . bin2hex(random_bytes(4)), 'kind' => 'table',
            'customer_name' => 'Guest', 'customer_phone' => '01700000000', 'status' => 'pending', 'source' => 'web', 'created_at' => date('Y-m-d H:i:s')]);

        $c = NavCounts::forCurrentUser();
        $this->assertSame(1, $c['orders']);
        $this->assertSame(1, $c['floor']);
        $this->assertSame(3, $c['kitchen']);
        $this->assertSame(1, $c['bookings']);

        // the JSON endpoint the menu polls
        $r = $this->getPage('nav/counts');
        $r->assertOK();
        $j = json_decode((string) $r->getJSON(), true) ?? json_decode((string) $r->getBody(), true);
        $this->assertSame(1, $j['data']['orders'] ?? null, (string) $r->getBody());

        // opening the online order clears its badge and records who opened it
        $this->getPage('orders/' . $web['id'])->assertOK();
        $row = db_connect()->table('orders')->where('id', $web['id'])->get()->getRowArray();
        $this->assertNotNull($row['acknowledged_at']);
        $this->assertSame($this->fx['users']['owner'], (int) $row['acknowledged_by']);
        $this->assertSame(0, NavCounts::forCurrentUser()['orders']);
        $this->assertNull(db_connect()->table('orders')->where('id', $pos['id'])->get()->getRow('acknowledged_at'), 'POS sales are never marked');
    }

    public function testCountsFollowTheMenuTheUserCanOpen(): void
    {
        $this->act('cashier');
        $c = NavCounts::forCurrentUser();
        foreach (array_keys($c) as $k) {
            $this->assertContains($k, array_column(pos_nav_items(), 'path'));
        }
    }
}
