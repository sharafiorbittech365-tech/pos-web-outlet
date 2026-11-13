<?php

declare(strict_types=1);

use App\Modules\Invoices\Services\DocSequence;
use App\Modules\Invoices\Services\FiscalYear;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\InvoiceSettings;
use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Pricing\Cart;
use OmniPOS\Core\Pricing\CartLine;
use OmniPOS\Core\Pricing\OrderCalculator;
use OmniPOS\Core\Pricing\TaxPolicy;
use Tests\Support\PosA\TestCase;

/**
 * Universal invoicing: drafts, issuing (numbering, stock, due book), payments, revisions with an
 * audit trail, void, permissions, invoice page settings and printing.
 *
 * @internal
 */
final class InvoicesTest extends TestCase
{
    private function stock(int $productId): float
    {
        return (float) db_connect()->table('products')->select('stock_qty')->where('id', $productId)->get()->getRow('stock_qty');
    }

    private function draft(array $lines, array $extra = []): int
    {
        return InvoiceService::forCurrent()->saveDraft(null, ['bill_name' => 'Rahim Traders', 'bill_phone' => '01711000111', 'lines' => $lines] + $extra);
    }

    private function setRates(string $rates, float $default = 0, bool $inclusive = true): void
    {
        db_connect()->table('businesses')->where('id', $this->fx['business_id'])->update(['tax_rates' => $rates, 'tax_rate' => $default, 'tax_inclusive' => $inclusive ? 1 : 0, 'round_to' => 0]);
        \App\Libraries\PosSession::forgetBusiness($this->fx['business_id']);
        $this->act($this->who);
    }

    public function testDraftThenIssueNumbersTakesStockAndCountsAsSale(): void
    {
        $p = $this->product('simple', 10);
        $before = $this->stock((int) $p['id']);
        $id = $this->draft([['product_id' => $p['id'], 'qty' => 2]]);
        $this->assertSame($before, $this->stock((int) $p['id']), 'a draft takes no stock');
        $this->dontSeeInDatabase('orders', ['business_id' => $this->fx['business_id'], 'channel' => 'invoice']);
        $this->seeInDatabase('invoice_revisions', ['invoice_id' => $id, 'action' => 'created']);

        $inv = InvoiceService::forCurrent()->issue($id, [['method' => 'cash', 'amount' => 999999]]);
        $this->assertSame('INV-00001', $inv['doc_no']);
        $this->assertSame('invoice', $inv['order']['channel']);
        $this->assertSame('paid', $inv['state']);
        $this->assertNotNull($inv['order']['acknowledged_at'], 'own invoices never raise the online-order badge');
        $this->assertEqualsWithDelta($before - 2, $this->stock((int) $p['id']), 0.001);
        $this->assertEqualsWithDelta(round((float) $p['price'] * 2, 2), (float) $inv['order']['subtotal'], 0.01);

        // issuing twice is refused; the next invoice gets the next number
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => InvoiceService::forCurrent()->issue($id));
        $id2 = $this->draft([['product_id' => $p['id'], 'qty' => 1]]);
        $this->assertSame('INV-00002', InvoiceService::forCurrent()->issue($id2, [['method' => 'cash', 'amount' => 999999]])['doc_no']);
        $this->seeInDatabase('invoice_revisions', ['invoice_id' => $id, 'action' => 'issued']);
    }

    public function testNumberingFormatsFiscalYearAndPerOutlet(): void
    {
        $this->assertSame('2025-26', FiscalYear::label('2026-06-30 23:59:59'));
        $this->assertSame('2026-27', FiscalYear::label('2026-07-01 00:00:00'));
        $this->assertSame('1999-00', FiscalYear::label('2000-01-15'));
        $this->assertSame(['2026-07-01', '2027-06-30'], FiscalYear::range('2026-27'));
        $this->assertTrue(FiscalYear::valid('2026-27'));
        $this->assertFalse(FiscalYear::valid('2026-28'));

        $cfg = ['prefix' => 'DZ/', 'pad' => 4, 'reset' => 'fy'];
        $this->assertSame('2026-27', InvoiceSettings::period($cfg, '2026-10-28'));
        $this->assertSame('DZ/2026-27-0007', InvoiceSettings::format($cfg, 7, '2026-27'));
        $this->assertSame('2026', InvoiceSettings::period(['reset' => 'year'], '2026-10-28'));
        $this->assertSame('', InvoiceSettings::period(['reset' => 'never'], '2026-10-28'));
        $this->assertSame('42', InvoiceSettings::format(['prefix' => '', 'pad' => 0], 42, ''));

        // each outlet and each period has its own gap-free series
        $bid = $this->fx['business_id'];
        $other = (new \OmniPOS\Core\Services\OutletProvisioner())->create($this->fx['tenant_id'], 'grocery', ['name' => 'Second outlet'], []);
        $this->assertGreaterThan(0, $other);
        $n = static fn (int $b, string $p): int => Tx::run(static fn () => DocSequence::next($b, 'invoice', $p));
        $this->assertSame(1, $n($bid, '2025-26'));
        $this->assertSame(2, $n($bid, '2025-26'));
        $this->assertSame(1, $n($bid, '2026-27'), 'a new fiscal year starts at 1');
        $this->assertSame(1, $n($other, '2026-27'), 'another outlet has its own series');
        // a rolled-back issue gives its number back
        try {
            Tx::run(static function () use ($bid): void {
                DocSequence::next($bid, 'invoice', '2026-27');
                throw new BusinessRuleException('boom');
            });
        } catch (BusinessRuleException) {
        }
        $this->assertSame(2, $n($bid, '2026-27'));
        $this->assertSame(3, DocSequence::peek($bid, 'invoice', '2026-27'));
    }

    public function testDueGoesOnTheDueBookAndPaymentsSettleIt(): void
    {
        $p = $this->product('simple', 5);
        $c = $this->namedCustomer();
        $due0 = (float) $c['balance_due'];
        $id = InvoiceService::forCurrent()->saveDraft(null, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 2]], 'due_date' => date('Y-m-d', strtotime('+15 days'))]);
        $inv = InvoiceService::forCurrent()->issue($id, [['method' => 'cash', 'amount' => 10]]);
        $total = (float) $inv['order']['total'];
        $this->assertSame('partial', $inv['state']);
        $this->assertEqualsWithDelta($total - 10, (float) $inv['order']['due_total'], 0.01);
        $bal = static fn (): float => (float) db_connect()->table('customers')->select('balance_due')->where('id', $c['id'])->get()->getRow('balance_due');
        $this->assertEqualsWithDelta($due0 + $total - 10, $bal(), 0.01);

        $r = InvoiceService::forCurrent()->pay($id, [['method' => 'cash', 'amount' => $total]]);
        $this->assertSame('paid', $r['order']['status']);
        $this->assertEqualsWithDelta($due0, $bal(), 0.01);
        $this->seeInDatabase('invoice_revisions', ['invoice_id' => $id, 'action' => 'payment']);
    }

    public function testWalkInInvoiceIsPaidInFull(): void
    {
        $p = $this->product('simple', 5);
        $id = InvoiceService::forCurrent()->saveDraft(null, ['lines' => [['product_id' => $p['id'], 'qty' => 1]]]);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => InvoiceService::forCurrent()->issue($id));
        $this->seeInDatabase('invoices', ['id' => $id, 'status' => 'draft']);
    }

    public function testCalculationsFollowOrderCalculator(): void
    {
        $this->setRates('0, 5, 15', 0, true);
        $p = $this->product('simple', 5);
        $svc = InvoiceService::forCurrent();
        $n = $svc->normalize(['bill_name' => 'Karim', 'discount_type' => 'percent', 'discount_value' => '10', 'lines' => [
            ['product_id' => $p['id'], 'qty' => 2, 'unit_price' => '200', 'discount_type' => 'fixed', 'discount_value' => '20', 'vat' => 'inclusive|5'],
            ['custom' => 1, 'name' => 'Installation', 'unit' => 'job', 'qty' => 3, 'unit_price' => '100', 'vat' => 'exclusive|15'],
        ]]);
        $q = $svc->quote($n);
        $expected = (new OrderCalculator(2))->calculate(new Cart(
            lines: [new CartLine(2, 200, 'inclusive', 5.0, 0, 'fixed', 20), new CartLine(3, 100, 'exclusive', 15.0)],
            discountType: 'percent', discountValue: 10,
        ), TaxPolicy::fromBusiness(service('posSession')->business()));
        $this->assertEqualsWithDelta($expected->total, $q['total'], 0.001);
        $this->assertEqualsWithDelta($expected->tax, $q['tax'], 0.001);
        $this->assertEqualsWithDelta(660.0, $q['subtotal'], 0.001); // (200-20)×2 + 300
        $this->assertEqualsWithDelta(360.0, $q['lines'][0]['amount'], 0.001);

        // only the outlet's own rates
        $this->assertThrowsOmni(ValidationException::class, fn () => $svc->normalize(['bill_name' => 'X', 'lines' => [['custom' => 1, 'name' => 'Odd', 'qty' => 1, 'unit_price' => 10, 'vat' => 'exclusive|12']]]));

        // the issued sale stores the same figures (free line without a product, VAT per line)
        $id = $svc->saveDraft(null, ['bill_name' => 'Karim', 'bill_phone' => '01811000222', 'discount_type' => 'percent', 'discount_value' => '10', 'lines' => [
            ['product_id' => $p['id'], 'qty' => 2, 'unit_price' => '200', 'discount_type' => 'fixed', 'discount_value' => '20', 'vat' => 'inclusive|5'],
            ['custom' => 1, 'name' => 'Installation', 'unit' => 'job', 'qty' => 3, 'unit_price' => '100', 'vat' => 'exclusive|15'],
        ]]);
        $inv = $svc->issue($id, [['method' => 'cash', 'amount' => 99999]]);
        $o = $inv['order'];
        $this->assertEqualsWithDelta($expected->total, (float) $o['total'], 0.001);
        $this->assertEqualsWithDelta($expected->tax, (float) $o['tax_total'], 0.001);
        $free = array_values(array_filter($o['items'], static fn ($i) => $i['product_id'] === null))[0];
        $this->assertSame('Installation', $free['name']);
        $this->assertSame('exclusive', $free['tax_mode']);
        $this->assertEqualsWithDelta(15.0, (float) $free['tax_rate'], 0.001);
    }

    public function testReviseKeepsNumberMovesStockAndAudits(): void
    {
        $p = $this->product('simple', 10);
        $c = $this->namedCustomer();
        $stock0 = $this->stock((int) $p['id']);
        $id = InvoiceService::forCurrent()->saveDraft(null, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 2]]]);
        $inv = InvoiceService::forCurrent()->issue($id);
        $bal0 = (float) db_connect()->table('customers')->select('balance_due')->where('id', $c['id'])->get()->getRow('balance_due');

        $inv2 = InvoiceService::forCurrent()->revise($id, ['customer_id' => $c['id'], 'notes' => 'Deliver Sunday', 'lines' => [['product_id' => $p['id'], 'qty' => 5]]]);
        $this->assertSame($inv['doc_no'], $inv2['doc_no'], 'the number stays');
        $this->assertSame((int) $inv['order_id'], (int) $inv2['order_id']);
        $this->assertEqualsWithDelta($stock0 - 5, $this->stock((int) $p['id']), 0.001);
        $this->assertEqualsWithDelta((float) $inv['order']['total'] / 2 * 5, (float) $inv2['order']['total'], 0.02);
        $bal1 = (float) db_connect()->table('customers')->select('balance_due')->where('id', $c['id'])->get()->getRow('balance_due');
        $this->assertEqualsWithDelta($bal0 + (float) $inv2['order']['total'] - (float) $inv['order']['total'], $bal1, 0.01, 'the due book follows');

        $rev = db_connect()->table('invoice_revisions')->where('invoice_id', $id)->where('action', 'revised')->get()->getRowArray();
        $this->assertNotNull($rev);
        $this->assertStringContainsString('qty 2 → 5', (string) $rev['summary']);
        $this->assertStringContainsString('Notes changed', (string) $rev['summary']);
        $this->assertSame(2.0, (float) json_decode((string) $rev['before_data'], true)['lines'][0]['qty']);
        $this->assertSame(5.0, (float) json_decode((string) $rev['after_data'], true)['lines'][0]['qty']);
        $this->assertSame((int) $this->fx['users']['owner'], (int) $rev['user_id']);

        // cannot go below what was paid
        InvoiceService::forCurrent()->pay($id, [['method' => 'cash', 'amount' => (float) $inv2['order']['total']]]);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => InvoiceService::forCurrent()->revise($id, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 1]]]));
    }

    public function testVoidPutsStockBackAndClearsTheDue(): void
    {
        $p = $this->product('simple', 5);
        $c = $this->namedCustomer();
        $stock0 = $this->stock((int) $p['id']);
        $bal0 = (float) $c['balance_due'];
        $id = InvoiceService::forCurrent()->saveDraft(null, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 3]]]);
        $inv = InvoiceService::forCurrent()->issue($id);
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::forCurrent()->void($id, ''));
        InvoiceService::forCurrent()->void($id, 'Wrong customer');
        $this->assertEqualsWithDelta($stock0, $this->stock((int) $p['id']), 0.001);
        $this->seeInDatabase('orders', ['id' => $inv['order_id'], 'status' => 'cancelled']);
        $this->seeInDatabase('invoices', ['id' => $id, 'status' => 'void', 'void_reason' => 'Wrong customer']);
        $this->assertEqualsWithDelta($bal0, (float) db_connect()->table('customers')->select('balance_due')->where('id', $c['id'])->get()->getRow('balance_due'), 0.01);
        $this->assertSame('void', InvoiceService::forCurrent()->find($id)['state']);
    }

    public function testPricePermissionsAreEnforced(): void
    {
        $p = $this->product('simple', 5);
        $id = $this->draft([['product_id' => $p['id'], 'qty' => 1, 'unit_price' => '1']]);   // owner: allowed
        $this->act('manager'); // invoices.manage, no invoices.edit_price
        $svc = InvoiceService::forCurrent();
        $this->assertFalse($svc->canEditPrice());
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $svc->normalize(['bill_name' => 'A', 'lines' => [['product_id' => $p['id'], 'qty' => 1, 'unit_price' => '1']]]));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $svc->normalize(['bill_name' => 'A', 'lines' => [['custom' => 1, 'name' => 'Gift', 'qty' => 1, 'unit_price' => '0']]]));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $svc->normalize(['bill_name' => 'A', 'lines' => [['product_id' => $p['id'], 'qty' => 1, 'vat' => 'exempt']]]));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $svc->normalize(['bill_name' => 'A', 'discount_value' => '50', 'lines' => [['product_id' => $p['id'], 'qty' => 1]]]));
        // a manager changing the quantity keeps the price the owner set
        $svc->saveDraft($id, ['bill_name' => 'Rahim Traders', 'lines' => [['product_id' => $p['id'], 'qty' => 3]]]);
        $inv = $svc->find($id);
        $this->assertSame(1.0, (float) $inv['data']['lines'][0]['unit_price']);
        $this->assertEqualsWithDelta(3.0, (float) $inv['total'], 0.01);

        // routes: cashier has no invoice permission; manager cannot void or change the invoice page
        $this->act('cashier');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('invoices'));
        $this->act('manager');
        $this->getPage('invoices')->assertOK();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->postForm('invoices/' . $id . '/void', ['reason' => 'x']));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('invoices/settings'));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('mushak/settings'));
    }

    public function testPagesAndPrint(): void
    {
        $p = $this->product('simple', 5);
        $id = $this->draft([['product_id' => $p['id'], 'qty' => 1]]);
        foreach (['invoices', 'invoices/new', 'invoices/' . $id, 'invoices/' . $id . '/edit', 'invoices/settings', 'invoices/products?q=' . urlencode(mb_substr((string) $p['name'], 0, 3))] as $uri) {
            $this->assertSame(200, $this->getPage($uri)->response()->getStatusCode(), $uri);
        }
        $draft = (string) $this->getPage('invoices/' . $id . '/print?size=a4&fragment=1')->getBody();
        $this->assertStringContainsString('DRAFT', $draft);

        $q = $this->getPage('invoices/quote?bill_name=A&lines[0][product_id]=' . $p['id'] . '&lines[0][qty]=2');
        $q->assertOK();
        $j = json_decode((string) $q->response()->getBody(), true);
        $this->assertEqualsWithDelta(round((float) $p['price'] * 2, 2), $j['data']['subtotal'], 0.01);

        // the HTTP flow: save & issue in one step
        $r = $this->postForm('invoices', ['action' => 'issue', 'bill_name' => 'Walk in', 'bill_phone' => '01911000333', 'lines' => [['product_id' => $p['id'], 'qty' => 1]], 'pay_amount' => 99999, 'pay_method' => 'cash']);
        $r->assertRedirect();
        $new = db_connect()->table('invoices')->where('business_id', $this->fx['business_id'])->where('status', 'issued')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($new);

        $a4 = $this->getPage('invoices/' . $new['id'] . '/print?size=a4&fragment=1');
        $this->assertSame('a4', $a4->response()->getHeaderLine('X-Print-Kind'));
        $this->assertStringContainsString((string) $new['doc_no'], (string) $a4->getBody());
        $rc = $this->getPage('invoices/' . $new['id'] . '/print?size=80&fragment=1&copies=2');
        $this->assertSame('receipt', $rc->response()->getHeaderLine('X-Print-Kind'));
        $this->assertSame(2, substr_count((string) $rc->getBody(), 'class="print-copy"'));
        $this->assertStringContainsString('size:A4', (string) $this->getPage('invoices/' . $new['id'] . '/print?size=a4')->getBody());
        $this->getPage('orders?channel=invoice')->assertSee((string) $new['doc_no']);
    }

    public function testEditorAndInvoicePageLayout(): void
    {
        $p = $this->product('simple', 5);
        // editor: live preview (left) + form cards (right), number assigned on issue, payment tiles
        $new = (string) $this->getPage('invoices/new')->response()->getBody();
        foreach (['id="ivx-preview"', 'id="ivx-formcol"', 'role="tablist"', 'Assigned on issue', 'next: INV-00001', 'id="ivx-cfg"', 'name="invoice_date"', 'id="f-terms-days"',
            'name="due_date"', 'id="inv-prod"', 'id="inv-free"', 'name="discount_value"', 'name="pay_method"', 'Save draft', 'Generate invoice'] as $needle) {
            $this->assertStringContainsString($needle, $new, $needle);
        }
        $this->assertStringContainsString('"decimals":2', $new);
        // tiles only for the outlet's own methods (no invented "digital wallet")
        $tiles = InvoiceService::forCurrent()->paymentTiles();
        $this->assertNotEmpty($tiles);
        foreach ($tiles as $t) {
            $this->assertContains($t['code'], InvoiceService::forCurrent()->methods());
        }
        $this->assertStringNotContainsString('Digital wallet', $new);

        // the invoice date: today or (with pos.backdate) up to a year back; never in the future
        $svc = InvoiceService::forCurrent();
        $this->assertThrowsOmni(ValidationException::class, fn () => $svc->normalize(['bill_name' => 'A', 'invoice_date' => date('Y-m-d', strtotime('+2 days')), 'lines' => [['product_id' => $p['id'], 'qty' => 1]]]));
        $c = $this->namedCustomer();
        $id = $svc->saveDraft(null, ['customer_id' => $c['id'], 'invoice_date' => date('Y-m-d', strtotime('-3 days')), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'lines' => [['product_id' => $p['id'], 'qty' => 2]]]);
        $this->assertSame(date('Y-m-d', strtotime('-3 days')), $svc->find($id)['data']['invoice_date']);
        $this->act('cashier');
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::forCurrent()->normalize(['bill_name' => 'A', 'invoice_date' => date('Y-m-d', strtotime('-3 days')), 'lines' => [['product_id' => $p['id'], 'qty' => 1]]]));
        $this->act('owner');

        $inv = InvoiceService::forCurrent()->issue($id);
        $this->assertStringContainsString(date_str(date('Y-m-d', strtotime('-3 days'))), (string) $this->getPage('invoices/' . $id . '/print?size=a4&fragment=1')->response()->getBody());

        // invoice page: document viewer, stats, payment card with tiles, history, timeline
        $page = (string) $this->getPage('invoices/' . $id)->response()->getBody();
        foreach (['class="ivx-frame"', 'embed&#x3D;1', 'Download PDF', 'Collect payment', 'name="method"', 'name="date"', 'Balance due', 'Payment history', 'Activity', 'Issued', 'Draft created'] as $needle) {
            $this->assertStringContainsString($needle, $page, $needle);
        }
        foreach ($tiles as $t) {
            $this->assertStringContainsString('value="' . $t['code'] . '"', $page);
        }
        $embed = (string) $this->getPage('invoices/' . $id . '/print?embed=1&size=a4')->response()->getBody();
        $this->assertStringContainsString($inv['doc_no'], $embed);
        $this->assertStringNotContainsString('print-bar', $embed);

        // a payment received yesterday (pos.backdate) is dated yesterday and shows in the history
        $r = $this->postForm('invoices/' . $id . '/pay', ['amount' => 10, 'method' => $tiles[0]['code'], 'date' => date('Y-m-d', strtotime('-1 day')), 'reference' => 'TRX-77']);
        $r->assertRedirect();
        $this->seeInDatabase('order_payments', ['order_id' => $inv['order_id'], 'reference' => 'TRX-77']);
        $paid = db_connect()->table('order_payments')->where('order_id', $inv['order_id'])->where('reference', 'TRX-77')->get()->getRowArray();
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), substr((string) $paid['created_at'], 0, 10));
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::forCurrent()->pay($id, [['method' => 'cash', 'amount' => 1]], date('Y-m-d', strtotime('+1 day'))));
        $tl = InvoiceService::forCurrent()->timeline(InvoiceService::forCurrent()->find($id));
        $pays = array_values(array_filter($tl, static fn ($t) => $t['kind'] === 'payment' && str_contains($t['detail'], 'TRX-77')));
        $this->assertCount(1, $pays);
        $this->assertSame('issued', $tl[0]['kind'], 'newest first: the backdated payment sorts by its own date');
        $this->assertStringContainsString('TRX-77', (string) $this->getPage('invoices/' . $id)->response()->getBody());
    }

    public function testBulkAdjustPricesMathAndRounding(): void
    {
        $l = [['unit_price' => 100, 'qty' => 2], ['unit_price' => 47.5, 'qty' => 1, 'discount_type' => 'percent', 'discount_value' => 10]];
        $up = InvoiceService::adjustPrices($l, 'up', 'pct', 10);
        $this->assertSame([110.0, 52.25], array_column($up, 'new'));
        $this->assertSame([220.0, 47.03], array_column($up, 'total'));
        $this->assertSame([110.0, 50.0], array_column(InvoiceService::adjustPrices($l, 'up', 'pct', 10, '5'), 'new'));
        $this->assertSame([90.0, 37.5], array_column(InvoiceService::adjustPrices($l, 'down', 'amount', 10), 'new'));
        $this->assertSame([0.0, 0.0], array_column(InvoiceService::adjustPrices($l, 'down', 'amount', 500), 'new'), 'never below zero');
        // set the line total: 2 × 75 = 150; 150 after a 10% line discount → 166.67 a unit
        $t = InvoiceService::adjustPrices($l, 'target', 'pct', 150);
        $this->assertSame([75.0, 166.67], array_column($t, 'new'));
        $this->assertSame([150.0, 150.0], array_column($t, 'total'));
        $this->assertSame([80.0, 170.0], array_column(InvoiceService::adjustPrices($l, 'target', 'pct', 150, '10'), 'new'));
        $fixed = InvoiceService::adjustPrices([['unit_price' => 10, 'qty' => 4, 'discount_type' => 'fixed', 'discount_value' => 2]], 'target', 'amount', 100, '1');
        $this->assertSame(27.0, $fixed[0]['new']);
        $this->assertSame(100.0, $fixed[0]['total']);
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::adjustPrices($l, 'down', 'pct', 120));
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::adjustPrices($l, 'sideways', 'pct', 1));

        // the endpoint (invoices.edit_price only)
        $r = $this->getPage('invoices/adjust?mode=up&unit=amount&value=5&round=none&lines[0][unit_price]=20&lines[0][qty]=3');
        $r->assertOK();
        $this->assertSame(25.0, (float) json_decode((string) $r->response()->getBody(), true)['data'][0]['new']);
        $this->act('manager');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('invoices/adjust?mode=up&unit=pct&value=5&lines[0][unit_price]=20&lines[0][qty]=1'));
    }

    public function testCustomPricesAreAuditedAndPrintedAsCharged(): void
    {
        $p = $this->product('simple', 5);
        $catalog = round((float) $p['price'], 2);
        $charged = round($catalog * 0.8, 2);
        $c = $this->namedCustomer();
        $svc = InvoiceService::forCurrent();
        $id = $svc->saveDraft(null, ['customer_id' => $c['id'], 'doc_title' => 'Quotation', 'footer_text' => 'Valid for 7 days', 'sign_name' => 'K. Hasan', 'sign_title' => 'Sales lead',
            'lines' => [['product_id' => $p['id'], 'qty' => 2, 'unit_price' => (string) $charged, 'note' => 'Blue colour']]]);
        $rev = db_connect()->table('invoice_revisions')->where('invoice_id', $id)->where('action', 'created')->get()->getRowArray();
        $after = json_decode((string) $rev['after_data'], true);
        $this->assertTrue($after['lines'][0]['custom_price']);
        $this->assertEqualsWithDelta($catalog, $after['lines'][0]['catalog'], 0.001);
        $this->assertEqualsWithDelta($charged, $after['lines'][0]['unit_price'], 0.001);
        $this->assertStringContainsString('custom prices: ' . $p['name'], (string) $rev['summary']);
        $this->assertSame((int) $this->fx['users']['owner'], (int) $rev['user_id']);

        // back to the catalogue price on edit: recorded too
        $svc->saveDraft($id, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 2]]]);
        $edit = db_connect()->table('invoice_revisions')->where('invoice_id', $id)->where('action', 'edited')->get()->getRowArray();
        $this->assertStringContainsString('back to catalogue', (string) $edit['summary']);
        $svc->saveDraft($id, ['customer_id' => $c['id'], 'lines' => [['product_id' => $p['id'], 'qty' => 2, 'unit_price' => (string) $charged, 'note' => 'Blue colour']]]);

        $inv = $svc->issue($id, [['method' => 'cash', 'amount' => 99999]]);
        $issued = db_connect()->table('invoice_revisions')->where('invoice_id', $id)->where('action', 'issued')->get()->getRowArray();
        $this->assertStringContainsString('custom prices', (string) $issued['summary']);
        $this->assertEqualsWithDelta($charged, (float) $inv['order']['items'][0]['unit_price'], 0.001);

        // printed: only the charged price, this invoice's own title / footer / signature, the line details
        $html = (string) $this->getPage('invoices/' . $id . '/print?size=a4&fragment=1')->response()->getBody();
        $this->assertStringContainsString(number_format($charged, 2), $html);
        if (abs($catalog - $charged) > 0.009) {
            $this->assertStringNotContainsString(number_format($catalog, 2), $html);
        }
        foreach (['Quotation', 'Valid for 7 days', 'Blue colour'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('Custom price', $html);
        $this->assertStringNotContainsString('Received by', $html);
        $this->assertStringNotContainsString('K. Hasan', $html, 'signature texts are not printed');
    }

    public function testEditorRefillsLinesAfterAValidationError(): void
    {
        $p = $this->product('simple', 5);
        $posted = ['bill_name' => 'Refill Test', 'bill_email' => 'not-an-email', 'lines' => [
            ['product_id' => (string) $p['id'], 'qty' => '3', 'unit_price' => '12.5', 'note' => 'Keep me'],
            ['custom' => '1', 'name' => 'Packing service', 'unit' => 'job', 'qty' => '1', 'unit_price' => '40', 'vat' => 'exempt'],
        ]];
        // the save fails and the handler redirects back with the input and the field errors
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceService::forCurrent()->normalize($posted));
        foreach (['security', 'posSession', 'moduleAccess', 'tenant'] as $svc) {
            \Config\Services::resetSingle($svc);
        }
        $html = (string) $this->withSession(['pos' => $this->sessionPos(), '_ci_old_input' => ['get' => [], 'post' => $posted], 'errors' => ['bill_email' => 'Enter a valid email or leave it empty.', 'lines.1.unit_price' => 'Enter a price.']])
            ->call('get', 'invoices/new')->response()->getBody();
        $this->assertStringContainsString('value="Packing&#x20;service"', $html);
        $this->assertStringContainsString('value="Keep&#x20;me"', $html);
        $this->assertStringContainsString('value="12.5"', $html);
        $this->assertStringContainsString('value="' . $p['id'] . '"', $html);
        $this->assertStringContainsString('Please check the highlighted fields', $html);
        $this->assertMatchesRegularExpression('/class="input inv-price is-invalid"[^>]*name="lines\[1\]\[unit_price\]"/', $html);
        $this->assertStringContainsString('lines.1.unit_price":"Enter a price.', $html);
        // the editable document is there with its parts
        foreach (['data-bind="bill_name"', 'data-bind="doc_title"', 'data-bind="terms"', 'data-bind="footer_text"', 'id="ivx-p-search"', 'id="adj-dlg"', 'Edit in settings'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // without invoices.edit_price: no price editing, no adjust tool, no settings link
        $this->act('manager');
        $m = (string) $this->getPage('invoices/new')->response()->getBody();
        $this->assertStringNotContainsString('id="adj-dlg"', $m);
        $this->assertStringNotContainsString('Edit in settings', $m);
        $this->assertStringContainsString('data-price="0"', $m);
    }

    public function testInvoicePageSettingsImagesAndAudit(): void
    {
        $set = InvoiceSettings::forCurrent();
        $this->assertThrowsOmni(ValidationException::class, fn () => $set->save(['title' => 'INVOICE', 'prefix' => 'bad prefix!', 'pad' => 5, 'reset' => 'never']));
        $set->save(['title' => 'TAX BILL', 'prefix' => 'B-', 'pad' => 3, 'reset' => 'year', 'sign_name' => 'Arif Hossain', 'sign_title' => 'Manager', 'terms' => 'Pay in 7 days', 'show_brand' => '1']);
        $this->assertSame('B-' . date('Y') . '-001', InvoiceSettings::forCurrent()->nextNumber());
        $this->seeInDatabase('setting_changes', ['business_id' => $this->fx['business_id'], 'area' => 'invoice_page']);

        // a real PNG is accepted, anything else is refused
        $png = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $fake = $this->fakeUpload($png, 'logo.png');
        $name = InvoiceSettings::forCurrent()->upload('header', $fake);
        $this->assertMatchesRegularExpression('/^header_[a-f0-9]{16}\.png$/', $name);
        $this->assertFileExists(InvoiceSettings::dir($this->fx['business_id']) . $name);
        $img = $this->getPage('invoices/image/header');
        $img->assertOK();
        $this->assertSame('image/png', $img->response()->getHeaderLine('Content-Type'));

        $txt = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($txt, '<?php echo 1;');
        $this->assertThrowsOmni(ValidationException::class, fn () => InvoiceSettings::forCurrent()->upload('footer', $this->fakeUpload($txt, 'evil.png')));
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\NotFoundException::class, fn () => InvoiceSettings::forCurrent()->upload('../x', $this->fakeUpload($png, 'a.png')));

        $p = $this->product('simple', 5);
        $id = $this->draft([['product_id' => $p['id'], 'qty' => 1]]);
        $html = (string) $this->getPage('invoices/' . $id . '/print?size=a4&fragment=1')->getBody();
        $this->assertStringContainsString('TAX BILL', $html);
        $this->assertStringContainsString('invoices/image/header', $html);
        // no signature blocks on invoices (the user asked for none); signature settings are gone
        foreach (['Arif Hossain', 'Received by', 'Authorized signature', 'ivd-signs'] as $no) {
            $this->assertStringNotContainsString($no, $html, $no);
        }
        $this->assertArrayNotHasKey('signature', InvoiceSettings::IMAGES);
        $this->assertStringNotContainsString('name="sign_name"', (string) $this->getPage('invoices/settings')->response()->getBody());
        $this->assertStringContainsString('Pay in 7 days', $html);

        InvoiceSettings::forCurrent()->removeImage('header');
        $this->assertFileDoesNotExist(InvoiceSettings::dir($this->fx['business_id']) . $name);
        // test uploads leave nothing behind
        array_map('unlink', glob(InvoiceSettings::dir($this->fx['business_id']) . '*') ?: []);
        @rmdir(InvoiceSettings::dir($this->fx['business_id']));
        @unlink($png);
        @unlink($txt);
    }

    private function fakeUpload(string $path, string $name): UploadedFile
    {
        return new class ($path, $name, null, (int) filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }

            public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
            {
                copy($this->getTempName(), rtrim($targetPath, '/') . '/' . $name);

                return true;
            }
        };
    }
}
