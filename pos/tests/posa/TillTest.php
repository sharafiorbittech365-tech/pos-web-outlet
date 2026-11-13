<?php

declare(strict_types=1);

use App\Modules\Orders\Services\OrderActions;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosA\TestCase;

/**
 * @internal
 */
final class TillTest extends TestCase
{
    private function cart(array $lines, array $extra = []): array
    {
        return $extra + ['lines' => $lines];
    }

    public function testCashSaleGivesChangeAndRecordsOnlyTheTotal(): void
    {
        $p = $this->product();
        $stock = (float) $p['stock_qty'];
        $r = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 2]]), [['method' => 'cash', 'amount' => 10000]], 'tok-cash-0001');
        $o = $r['order'];
        $total = round((float) $p['price'] * 2, 2);
        $this->assertSame('paid', $o['status']);
        $this->assertEqualsWithDelta($total, (float) $o['total'], 0.01);
        $this->assertEqualsWithDelta(10000 - $total, $r['change'], 0.01);
        $this->assertCount(1, $o['payments']);
        $this->assertEqualsWithDelta($total, (float) $o['payments'][0]['amount'], 0.01);
        $after = (float) db_connect()->table('products')->select('stock_qty')->where('id', $p['id'])->get()->getRow('stock_qty');
        $this->assertEqualsWithDelta($stock - 2, $after, 0.0001);
    }

    public function testSameCartTokenDoesNotSellTwice(): void
    {
        $p = $this->product();
        $till = new TillService();
        $a = $till->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]]), [['method' => 'cash', 'amount' => 99999]], 'tok-replay-01');
        $b = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]]), [['method' => 'cash', 'amount' => 99999]], 'tok-replay-01');
        $this->assertTrue($b['replay']);
        $this->assertSame((int) $a['order']['id'], (int) $b['order']['id']);
        $this->assertSame(1, db_connect()->table('orders')->where('business_id', $this->fx['business_id'])->countAllResults());
    }

    public function testCreditSaleWritesTheDueBook(): void
    {
        $p = $this->product();
        $c = $this->namedCustomer();
        db_connect()->table('customers')->where('id', $c['id'])->update(['credit_limit' => 0]);
        $r = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]], ['customer' => ['id' => (int) $c['id']]]), [['method' => 'credit', 'amount' => 1]], 'tok-credit-01');
        $o = $r['order'];
        $this->assertSame('due', $o['status']);
        $this->assertEqualsWithDelta((float) $o['total'], (float) $o['due_total'], 0.01);
        $row = db_connect()->table('customer_ledger')->where('customer_id', $c['id'])->where('ref_type', 'order')->where('ref_id', $o['id'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('sale', $row['entry_type']);
        $this->assertEqualsWithDelta((float) $o['total'], (float) $row['amount'], 0.01);
        $bal = (float) db_connect()->table('customers')->select('balance_due')->where('id', $c['id'])->get()->getRow('balance_due');
        $this->assertEqualsWithDelta((float) $c['balance_due'] + (float) $o['total'], $bal, 0.01);
    }

    public function testCreditNeedsANamedCustomerAndThePermission(): void
    {
        $p = $this->product();
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]]), [['method' => 'credit', 'amount' => 1]], 'tok-credit-02'));
        $this->act('cashier');
        $c = $this->namedCustomer();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]], ['customer' => ['id' => (int) $c['id']]]), [['method' => 'credit', 'amount' => 1]], 'tok-credit-03'));
    }

    public function testOutOfStockIsRefusedAndNothingIsWritten(): void
    {
        $p = $this->product();
        $qty = (float) $p['stock_qty'] + 5;
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => $qty]]), [['method' => 'cash', 'amount' => 9999999]], 'tok-oos-0001'));
        $this->assertSame(0, db_connect()->table('orders')->where('business_id', $this->fx['business_id'])->countAllResults());
        $after = (float) db_connect()->table('products')->select('stock_qty')->where('id', $p['id'])->get()->getRow('stock_qty');
        $this->assertEqualsWithDelta((float) $p['stock_qty'], $after, 0.0001);
        // the token is free again after the failure
        $ok = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]]), [['method' => 'cash', 'amount' => 9999999]], 'tok-oos-0001');
        $this->assertSame('paid', $ok['order']['status']);
    }

    public function testVatBucketsPerRateInclusiveAndAdded(): void
    {
        $db = db_connect();
        $b = $db->table('businesses')->where('id', $this->fx['business_id'])->get()->getRowArray();
        $db->table('businesses')->where('id', $b['id'])->update(['tax_rate' => 5, 'tax_inclusive' => 1]);
        \App\Libraries\PosSession::forgetBusiness((int) $b['id']);
        $this->act('owner');
        $rows = $db->table('products')->where('business_id', $b['id'])->where('stock_type', 'simple')->where('stock_qty >=', 5)->orderBy('id')->limit(2)->get()->getResultArray();
        [$a, $c] = $rows;
        $db->table('products')->where('id', $a['id'])->update(['price' => 1050, 'tax_mode' => 'inclusive', 'tax_rate' => 5]);
        $db->table('products')->where('id', $c['id'])->update(['price' => 1000, 'tax_mode' => 'exclusive', 'tax_rate' => 15]);

        $q = (new TillService())->quote($this->cart([['product_id' => (int) $a['id'], 'qty' => 1], ['product_id' => (int) $c['id'], 'qty' => 1]]));
        $t = $q['totals'];
        $this->assertEqualsWithDelta(50.0, $t['taxIncluded'], 0.01);
        $this->assertEqualsWithDelta(150.0, $t['taxAdded'], 0.01);
        $this->assertEqualsWithDelta(2200.0, $t['total'], 0.01);
        $this->assertSame('5% incl.', $q['lines'][0]['tax']);
        $this->assertSame('15% added', $q['lines'][1]['tax']);

        $r = (new TillService())->checkout($this->cart([['product_id' => (int) $a['id'], 'qty' => 1], ['product_id' => (int) $c['id'], 'qty' => 1]]), [['method' => 'card', 'amount' => 2200, 'reference' => '4242']], 'tok-vat-00001');
        $o = service('orders')->find((int) $r['order']['id']);
        $this->assertEqualsWithDelta(200.0, (float) $o['tax_total'], 0.01);
        $byRate = [];
        foreach ($o['taxes'] as $x) {
            $byRate[$x['tax_mode'] . '|' . (float) $x['rate']] = [(float) $x['taxable'], (float) $x['tax']];
        }
        $this->assertEqualsWithDelta(1000.0, $byRate['inclusive|5'][0], 0.01);
        $this->assertEqualsWithDelta(50.0, $byRate['inclusive|5'][1], 0.01);
        $this->assertEqualsWithDelta(1000.0, $byRate['exclusive|15'][0], 0.01);
        $this->assertEqualsWithDelta(150.0, $byRate['exclusive|15'][1], 0.01);

        // the A4 invoice prints one VAT row per rate and the taxable-value table
        $d = (new \App\Modules\Orders\Services\OrderDocs())->build($o, service('posSession')->business());
        $html = view('App\Modules\Orders\Views\docs\a4', ['d' => $d, 'mode' => 'invoice']);
        $this->assertStringContainsString('VAT 5% (incl.)', $html);
        $this->assertStringContainsString('VAT 15% (added)', $html);
        $this->assertStringContainsString('Taxable value', $html);
    }

    public function testDiscountAboveTheRoleLimitIsRefused(): void
    {
        $p = $this->product();
        $this->act('cashier');   // max 5 %
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]], ['discount' => ['type' => 'percent', 'value' => 20]]), [['method' => 'cash', 'amount' => 999999]], 'tok-disc-0001'));
        $ok = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 1]], ['discount' => ['type' => 'percent', 'value' => 5]]), [['method' => 'cash', 'amount' => 999999]], 'tok-disc-0002');
        $this->assertGreaterThan(0, (float) $ok['order']['discount_total']);
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => (new TillService())->quote($this->cart([['product_id' => (int) $p['id'], 'qty' => 1, 'unit_price' => 1]])));
    }

    public function testReturnRefundsAndRestocks(): void
    {
        $p = $this->product();
        $r = (new TillService())->checkout($this->cart([['product_id' => (int) $p['id'], 'qty' => 3]]), [['method' => 'cash', 'amount' => 999999]], 'tok-ret-00001');
        $o = $r['order'];
        $stock = (float) db_connect()->table('products')->select('stock_qty')->where('id', $p['id'])->get()->getRow('stock_qty');
        $ret = (new OrderActions())->returnItems((int) $o['id'], [(int) $o['items'][0]['id'] => 1], 'damaged');
        $this->assertEqualsWithDelta((float) $o['total'] / 3, $ret['amount'], 0.02);
        $this->assertSame('cash', $ret['method']);
        $after = (float) db_connect()->table('products')->select('stock_qty')->where('id', $p['id'])->get()->getRow('stock_qty');
        $this->assertEqualsWithDelta($stock + 1, $after, 0.0001);
        $this->assertEqualsWithDelta($ret['amount'], (float) db_connect()->table('orders')->select('returned_total')->where('id', $o['id'])->get()->getRow('returned_total'), 0.01);
    }

    public function testHoldAndResumeKeepsTheCartWithoutStock(): void
    {
        $p = $this->product();
        $till = new TillService();
        $id = $till->hold($this->cart([['product_id' => (int) $p['id'], 'qty' => 2]]), 'back soon');
        $this->assertCount(1, $till->parked()['held']);
        $back = $till->resume($id);
        $this->assertSame((int) $p['id'], (int) $back['cart']['lines'][0]['product_id']);
        $this->assertCount(0, $till->parked()['held']);
        $after = (float) db_connect()->table('products')->select('stock_qty')->where('id', $p['id'])->get()->getRow('stock_qty');
        $this->assertEqualsWithDelta((float) $p['stock_qty'], $after, 0.0001);
    }

    public function testCheckoutEndpointReturnsJsonWithTheReceipt(): void
    {
        $p = $this->product();
        foreach (['security', 'posSession', 'moduleAccess', 'tenant'] as $svc) {
            \Config\Services::resetSingle($svc);
        }
        $body = json_encode(['token' => 'tok-http-0001', 'cart' => ['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], 'payments' => [['method' => 'cash', 'amount' => 999999]]]);
        $r = $this->withSession(['pos' => $this->sessionPos(), 'csrf_test_name' => 'posa-test-token'])
            ->withHeaders(['X-CSRF-TOKEN' => 'posa-test-token', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'Content-Type' => 'application/json'])
            ->withBody($body)->call('post', 'sell/checkout');
        $r->assertOK();
        $j = json_decode($r->getJSON(), true);
        $this->assertSame('paid', $j['data']['order']['status']);
        $this->assertStringContainsString('SALES RECEIPT', $j['data']['receipt_html']);
    }
}
