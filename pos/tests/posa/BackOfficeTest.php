<?php

declare(strict_types=1);

use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Register\Services\RegisterService;
use App\Modules\Sales\Services\TillService;
use Tests\Support\PosA\TestCase;

/**
 * Due book, register closing and the back-office pages.
 *
 * @internal
 */
final class BackOfficeTest extends TestCase
{
    public function testDueCollectionPaysTheOldestInvoiceFirst(): void
    {
        $p = $this->product();
        $c = $this->namedCustomer();
        $till = new TillService();
        $o1 = $till->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]], 'customer' => ['id' => (int) $c['id']]], [['method' => 'credit', 'amount' => 1]], 'tok-due-0001')['order'];
        $o2 = (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 2]], 'customer' => ['id' => (int) $c['id']]], [['method' => 'credit', 'amount' => 1]], 'tok-due-0002')['order'];
        $svc = new CustomerService();
        $before = (float) $svc->find((int) $c['id'])['balance_due'];
        $pay = (float) $o1['total'] + 1;
        $r = $svc->collect((int) $c['id'], $pay, 'cash', '', 'part payment');
        $this->assertEqualsWithDelta($before - $pay, $r['balance'], 0.01);
        $this->assertSame((int) $o1['id'], $r['orders'][0]['id']);
        $db = db_connect();
        $this->assertSame('paid', $db->table('orders')->select('status')->where('id', $o1['id'])->get()->getRow('status'));
        $this->assertSame('partial', $db->table('orders')->select('status')->where('id', $o2['id'])->get()->getRow('status'));
        $this->assertEqualsWithDelta((float) $o2['total'] - 1, (float) $db->table('orders')->select('due_total')->where('id', $o2['id'])->get()->getRow('due_total'), 0.01);
    }

    public function testOpeningBalanceAndStatement(): void
    {
        $c = $this->namedCustomer();
        $svc = new CustomerService();
        $bal = $svc->opening((int) $c['id'], 1500, 'Khata page 3');
        $this->assertEqualsWithDelta((float) $c['balance_due'] + 1500, $bal, 0.01);
        $st = $svc->statement((int) $c['id'], 'all');
        $this->assertEqualsWithDelta($bal, $st['closing'], 0.01);
        $__r = $this->getPage('customers/' . $c['id'] . '/statement');
        $__r->assertOK();
        $__r->assertSee('CUSTOMER LEDGER');
    }

    public function testRegisterCloseMath(): void
    {
        $reg = RegisterService::forCurrent();
        $reg->open(500);
        $p = $this->product();
        $sale = (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 2]]], [['method' => 'cash', 'amount' => 999999]], 'tok-reg-00001')['order'];
        (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'card', 'amount' => (float) $p['price']]], 'tok-reg-00002');
        $this->assertSame($reg->currentId(), (int) $sale['register_id']);
        $refund = (new OrderActions())->returnItems((int) $sale['id'], [(int) $sale['items'][0]['id'] => 1])['amount'];
        db_connect()->table('expenses')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'category' => 'Tea', 'amount' => 100, 'method' => 'cash',
            'spent_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
        // old khata balance collected in cash (not allocated to any invoice)
        $c = $this->namedCustomer();
        (new CustomerService())->opening((int) $c['id'], 300, 'old');
        (new CustomerService())->collect((int) $c['id'], 300, 'cash');

        $expected = round(500 + (float) $sale['total'] - $refund - 100 + 300, 2);
        $sum = $reg->summary($reg->current());
        $this->assertEqualsWithDelta($expected, $sum['expected'], 0.01);
        $this->assertEqualsWithDelta((float) $p['price'], $sum['by_method']['card']['v'], 0.01);

        $closed = $reg->close($expected - 20, 'short');
        $this->assertEqualsWithDelta($expected, $closed['expected'], 0.01);
        $this->assertEqualsWithDelta(-20, $closed['difference'], 0.01);
        $this->assertNull($reg->current());
        $__r = $this->getPage('register');
        $__r->assertOK();
        $__r->assertSee('Open the register');
    }

    public function testPagesRender(): void
    {
        $p = $this->product();
        $o = (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'cash', 'amount' => 999999]], 'tok-page-0001')['order'];
        foreach (['dashboard', 'sell', 'orders', 'orders/' . $o['id'], 'orders/' . $o['id'] . '/print?size=a4', 'orders/' . $o['id'] . '/print?size=58',
            'orders/' . $o['id'] . '/print?mode=challan&size=a4', 'customers', 'customers/' . $this->namedCustomer()['id'], 'register', 'outlets', 'switch-user'] as $uri) {
            $r = $this->getPage($uri);
            $this->assertSame(200, $r->response()->getStatusCode(), $uri);
        }
        $__r = $this->getPage('orders?orders_export=csv');
        $__r->assertOK();
        $__r->assertSee($o['doc_no']);
    }
}
