<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * Expenses, bookings, loyalty and tasks.
 *
 * @internal
 */
final class OperationsTest extends FeatureTestCase
{
    public function testExpenses(): void
    {
        $this->postForm('expenses', ['category' => 'Rent', 'amount' => '15000', 'method' => 'bank', 'note' => 'October', 'spent_at' => date('Y-m-d')])->assertRedirect();
        $this->postForm('expenses', ['category' => 'Utilities', 'amount' => '2500', 'method' => 'cash'])->assertRedirect();
        $page = $this->getPage('expenses');
        $page->assertOK();
        $page->assertSee('October');
        $this->assertEqualsWithDelta(17500.0, (float) db_connect()->table('expenses')->selectSum('amount')->where('business_id', $this->fx['business_id'])->get()->getRow('amount'), 0.01);
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->getPage('expenses'));
    }

    public function testBookingsAtTheCounter(): void
    {
        // electronics → booking kind "repair"
        $this->postForm('bookings', ['customer_name' => 'Rahim', 'customer_phone' => '01711000000', 'date' => date('Y-m-d', strtotime('+1 day')), 'time' => '11:00', 'details' => 'Cracked screen'])->assertRedirect();
        $bk = db_connect()->table('bookings')->where('business_id', $this->fx['business_id'])->get()->getRowArray();
        $this->assertSame('repair', $bk['kind']);
        $this->assertSame('pos', $bk['source']);
        $this->assertSame('confirmed', $bk['status']);
        $this->getPage('bookings')->assertSee('Rahim');
        $this->postForm('bookings/' . $bk['id'] . '/status', ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', db_connect()->table('bookings')->where('id', $bk['id'])->get()->getRow('status'));
    }

    public function testLoyaltyPointsOnPaidSaleAndReturn(): void
    {
        $db = db_connect();
        // loyalty is on the Business plan (all modules); it needs customers, also on
        $db->table('customers')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'name' => 'Karim', 'phone' => '01800000000', 'created_at' => date('Y-m-d H:i:s')]);
        $cid = (int) $db->insertID();
        $db->table('products')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'name' => 'Speaker', 'price' => 1000, 'tax_mode' => 'exempt',
            'stock_type' => 'simple', 'stock_qty' => 10, 'created_at' => date('Y-m-d H:i:s')]);
        $pid = (int) $db->insertID();
        $this->bind('owner');
        $order = service('orders')->create(['channel' => 'pos', 'lines' => [['product_id' => $pid, 'qty' => 2]], 'customer' => ['id' => $cid], 'payments' => [['method' => 'cash', 'amount' => 2000]]]);
        $this->assertSame(20, (int) $db->table('customers')->where('id', $cid)->get()->getRow('points'));
        $this->assertSame(1, $db->table('loyalty_ledger')->where('order_id', $order['id'])->countAllResults());

        $this->bind('owner');
        service('orders')->returnItems((int) $order['id'], [['item_id' => (int) $order['items'][0]['id'], 'qty' => 1]]);
        $this->assertSame(10, (int) $db->table('customers')->where('id', $cid)->get()->getRow('points'));

        $this->postForm('loyalty/rules', ['earn_per' => '50', 'point_value' => '0.5', 'min_redeem' => '20'])->assertRedirect();
        $this->postForm('loyalty/adjust', ['customer_id' => (string) $cid, 'points' => '5', 'note' => 'Birthday'])->assertRedirect();
        $this->assertSame(15, (int) $db->table('customers')->where('id', $cid)->get()->getRow('points'));
        $this->getPage('loyalty')->assertSee('Karim');
    }

    public function testTasksAndLowStockAlerts(): void
    {
        $db = db_connect();
        $db->table('products')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'name' => 'Cable', 'price' => 100, 'stock_type' => 'simple', 'stock_qty' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $this->postForm('tasks/auto')->assertRedirect();
        $this->postForm('tasks/auto')->assertRedirect();
        $this->assertSame(1, $db->table('tasks')->where('business_id', $this->fx['business_id'])->where('source', 'low_stock')->countAllResults());

        $this->postForm('tasks', ['title' => 'Clean the counter', 'assignee_id' => (string) $this->fx['users']['cashier'], 'due_at' => date('Y-m-d\T18:00')])->assertRedirect();
        $t = $db->table('tasks')->where('business_id', $this->fx['business_id'])->where('title', 'Clean the counter')->get()->getRowArray();
        // the cashier cannot assign, but can complete their own task
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('tasks', ['title' => 'Nope']));
        $this->as('cashier')->postForm('tasks/' . $t['id'] . '/toggle')->assertRedirect();
        $this->assertSame('done', $db->table('tasks')->where('id', $t['id'])->get()->getRow('status'));
        $this->as('owner')->getPage('tasks', ['scope' => 'all', 'status' => 'any'])->assertSee('Clean the counter');
    }
}
