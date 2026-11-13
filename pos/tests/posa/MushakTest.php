<?php

declare(strict_types=1);

use App\Modules\Invoices\Services\FiscalYear;
use App\Modules\Invoices\Services\InvoiceService;
use App\Modules\Invoices\Services\MushakService;
use App\Modules\Invoices\Services\MushakSettings;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosA\TestCase;

/**
 * NBR Mushak-6.3: settings (authorized, validated, audited), fiscal-year serials, the frozen
 * document, its calculations and the A4 / 80 mm print pages.
 * The BINs below are test values only.
 *
 * @internal
 */
final class MushakTest extends TestCase
{
    private const TEST_BIN = '123456789';

    private function enable(array $extra = []): void
    {
        MushakSettings::forCurrent()->save($extra + ['enabled' => '1', 'reg_name' => 'Test Outlet Ltd', 'bin' => self::TEST_BIN, 'address' => 'House 1, Road 2, Dhaka',
            'responsible_name' => 'A. Karim', 'responsible_title' => 'Accounts Officer']);
        $this->act($this->who);
    }

    private function invoice(float $qty = 2, ?array $customer = null): array
    {
        $p = $this->product('simple', 10);
        $c = $customer ?? $this->namedCustomer();
        $id = InvoiceService::forCurrent()->saveDraft(null, ['customer_id' => $c['id'], 'bill_bin' => '987654321', 'ship_to' => 'Chattogram depot', 'lines' => [['product_id' => $p['id'], 'qty' => $qty]]]);

        return InvoiceService::forCurrent()->issue($id, [['method' => 'cash', 'amount' => 99999]]);
    }

    public function testSettingsAreValidatedAndAudited(): void
    {
        $this->assertNull(MushakSettings::normalizeBin('12345'));
        $this->assertNull(MushakSettings::normalizeBin('12345678A'));
        $this->assertSame('123456789', MushakSettings::normalizeBin(' 123-456-789 '));
        $this->assertSame('123456789-0101', MushakSettings::normalizeBin('1234567890101'));

        $set = MushakSettings::forCurrent();
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $set->save(['enabled' => '1']));
        $this->assertArrayHasKey('bin', $e->details());
        $this->assertThrowsOmni(ValidationException::class, fn () => $set->save(['enabled' => '1', 'reg_name' => 'X', 'address' => 'Y', 'bin' => '1234']));
        $this->assertFalse(MushakSettings::forCurrent()->enabled());

        $this->enable();
        $this->assertTrue(MushakSettings::forCurrent()->enabled());
        $row = db_connect()->table('setting_changes')->where('business_id', $this->fx['business_id'])->where('area', 'mushak')->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('', json_decode((string) $row['before_data'], true)['bin']);
        $this->assertSame(self::TEST_BIN, json_decode((string) $row['after_data'], true)['bin']);
        $this->assertSame((int) $this->fx['users']['owner'], (int) $row['user_id']);
        $this->seeInDatabase('activity_log', ['business_id' => $this->fx['business_id'], 'action' => 'Mushak-6.3 settings changed']);

        // only mushak.settings may change it (manager has mushak.issue only)
        $this->act('manager');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->postForm('mushak/settings', ['enabled' => '0']));
        $this->assertTrue(MushakSettings::forCurrent()->enabled());
    }

    public function testSerialsRunPerFiscalYearAndTheDocumentIsFrozen(): void
    {
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => MushakService::forCurrent()->issue((int) $this->invoice()['order_id']));
        $this->enable();
        // last year's serials do not continue into this fiscal year
        db_connect()->query("INSERT INTO doc_sequences (business_id, series, period, last_number) VALUES (?, 'mushak', ?, 57)", [$this->fx['business_id'], FiscalYear::label(date('Y-m-d', strtotime('-1 year')))]);
        $fy = FiscalYear::label();

        $inv = $this->invoice();
        $m = MushakService::forCurrent()->issue((int) $inv['order_id']);
        $this->assertSame(1, (int) $m['serial']);
        $this->assertSame($fy, $m['fiscal_year']);
        $this->assertSame($fy . '/000001', $m['doc_no']);
        $this->assertSame(self::TEST_BIN, $m['data']['supplier']['bin']);
        $this->assertSame('987654321', $m['data']['buyer']['bin']);
        $this->assertSame('Chattogram depot', $m['data']['destination']);
        $this->assertSame((int) $m['id'], (int) MushakService::forCurrent()->issue((int) $inv['order_id'])['id'], 'issued once per sale');

        // the table adds up to the sale: VAT as charged, value + VAT = total
        $o = service('orders')->find((int) $inv['order_id']);
        $this->assertEqualsWithDelta((float) $o['tax_total'], (float) $m['vat_total'], 0.01);
        $this->assertEqualsWithDelta((float) $o['total'] - (float) $o['shipping'] - (float) $o['round_off'], (float) $m['grand_total'], 0.01);
        $this->assertEqualsWithDelta((float) $m['grand_total'], (float) $m['value_total'] + (float) $m['vat_total'], 0.01);

        // a POS sale gets the next serial (one series per outlet)
        $p = $this->product('simple', 5);
        $sale = (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'cash', 'amount' => 99999]], 'tok-mk-' . bin2hex(random_bytes(4)))['order'];
        $r = $this->postForm('mushak/order/' . $sale['id'], ['buyer_name' => 'Walk-in buyer', 'vehicle_type' => 'Pickup', 'vehicle_no' => 'DHAKA METRO-NA 11-2233', 'back' => 'orders/' . $sale['id']]);
        $r->assertRedirect();
        $m2 = MushakService::forCurrent()->forOrder((int) $sale['id']);
        $this->assertSame(2, (int) $m2['serial']);
        $this->assertSame('Pickup', $m2['data']['vehicle']['type']);

        // settings changed later do not change an issued Mushak-6.3
        $this->enable(['reg_name' => 'Renamed Ltd']);
        $this->assertSame('Test Outlet Ltd', MushakService::forCurrent()->find((int) $m['id'])['data']['supplier']['name']);

        // an invoice with a Mushak-6.3 can no longer be revised or voided
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => InvoiceService::forCurrent()->revise((int) $inv['id'], ['lines' => [['product_id' => $p['id'], 'qty' => 1]]]));
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => InvoiceService::forCurrent()->void((int) $inv['id'], 'Mistake'));
        $this->seeInDatabase('invoice_revisions', ['invoice_id' => $inv['id'], 'action' => 'mushak']);
    }

    public function testAutoIssueAndPermission(): void
    {
        $this->enable(['auto_issue' => '1']);
        $inv = $this->invoice(1);
        $this->assertNotNull($inv['mushak'], 'issued together with the invoice');

        $this->act('cashier');
        $p = $this->product('simple', 5);
        $sale = (new TillService())->checkout(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]]], [['method' => 'cash', 'amount' => 99999]], 'tok-mk2-' . bin2hex(random_bytes(4)))['order'];
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => MushakService::forCurrent()->issue((int) $sale['id']));
    }

    public function testComputeSpreadsTheDiscountAndKeepsChargesVat(): void
    {
        // 2 lines (one 15% added, one 5% included), 10% invoice discount, service charge at 15% added
        $order = [
            'subtotal' => 300.0, 'discount_total' => 30.0, 'service_charge' => 27.0, 'packing' => 0.0, 'shipping' => 50.0, 'round_off' => 0.0,
            'tax_total' => 0.0, 'tax_added' => 0.0, 'total' => 0.0,
            'items' => [
                ['name' => 'Cement', 'variant_name' => null, 'product_id' => 1, 'unit' => 'bag', 'qty' => 2, 'line_total' => 200.0, 'tax_mode' => 'exclusive', 'tax_rate' => 15, 'tax_amount' => 27.0],
                ['name' => 'Rod', 'variant_name' => null, 'product_id' => 2, 'unit' => 'kg', 'qty' => 1, 'line_total' => 100.0, 'tax_mode' => 'inclusive', 'tax_rate' => 5, 'tax_amount' => 4.29],
            ],
        ];
        // VAT: 180×15% = 27, 90 incl 5% = 4.29, service 27×15% = 4.05
        $order['tax_total'] = 35.34;
        $order['tax_added'] = 31.05;
        $order['total'] = 270 + 27 + 31.05 + 50;
        $c = MushakService::compute($order, false, [1 => 'Shah']);
        $this->assertCount(3, $c['lines']);
        $this->assertSame('Cement (Shah)', $c['lines'][0]['description']);
        $this->assertEqualsWithDelta(180.0, $c['lines'][0]['value'], 0.001);
        $this->assertEqualsWithDelta(90.0, $c['lines'][0]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(207.0, $c['lines'][0]['total'], 0.001);
        $this->assertEqualsWithDelta(85.71, $c['lines'][1]['value'], 0.001);
        $this->assertEqualsWithDelta(90.0, $c['lines'][1]['total'], 0.001);
        $this->assertEqualsWithDelta(4.05, $c['lines'][2]['vat'], 0.001);
        $this->assertEqualsWithDelta(15.0, $c['lines'][2]['vat_rate'], 0.01);
        $this->assertEqualsWithDelta(35.34, $c['totals']['vat'], 0.001);
        $this->assertEqualsWithDelta($order['total'] - 50, $c['totals']['total'], 0.001, 'everything but delivery');
        $this->assertSame(0.0, $c['totals']['sd']);
    }

    public function testRegularInvoiceCarriesNbrDetailsOnlyWhenVatRegistered(): void
    {
        // not VAT-registered: no BIN rows, no NBR columns
        $plain = $this->invoice(2);
        $a4 = (string) $this->getPage('invoices/' . $plain['id'] . '/print?size=a4&fragment=1')->response()->getBody();
        $rc = (string) $this->getPage('invoices/' . $plain['id'] . '/print?size=80&fragment=1')->response()->getBody();
        foreach (['class="bin"', 'Value<br>excl. VAT', 'Mushak-6.3 tax invoice'] as $no) {
            $this->assertStringNotContainsString($no, $a4, $no);
        }
        $this->assertStringContainsString('987654321', $a4, 'the buyer BIN the user entered is still printed');
        $this->assertStringNotContainsString('BIN:', $rc);
        $this->assertStringNotContainsString('Incl. VAT', $rc);

        // VAT-registered: seller registered name / BIN, buyer BIN, time of issue, NBR columns and totals
        $this->enable(['reg_name' => 'Test Outlet Registered Ltd']);
        $inv = $this->invoice(2);
        $a4 = (string) $this->getPage('invoices/' . $inv['id'] . '/print?size=a4&fragment=1')->response()->getBody();
        foreach (['BIN ' . self::TEST_BIN, 'Registered name: Test Outlet Registered Ltd', 'House 1, Road 2, Dhaka', 'BIN</b> 987654321', 'Time of issue',
            'Unit price<br>excl. VAT', 'Value<br>excl. VAT', 'VAT<br>rate', 'Value<br>incl. VAT', 'by rate', 'not the Mushak-6.3 tax invoice', 'Chattogram depot'] as $needle) {
            $this->assertStringContainsString($needle, $a4, $needle);
        }
        $this->assertStringNotContainsString('<th class="num">SD</th>', $a4, 'SD column only when there is SD');
        $this->assertMatchesRegularExpression('/Time of issue<\/td><td>\d{2}:\d{2} (AM|PM)/', $a4);
        $rc = (string) $this->getPage('invoices/' . $inv['id'] . '/print?size=80&fragment=1')->response()->getBody();
        foreach (['BIN: ' . self::TEST_BIN, 'Buyer BIN', 'Incl. VAT', 'Value excl. VAT', 'receipt w80'] as $needle) {
            $this->assertStringContainsString($needle, $rc, $needle);
        }
        // the NBR values match the sale
        $o = service('orders')->find((int) $inv['order_id']);
        $this->assertStringContainsString(number_format((float) $o['tax_total'], 2), $a4);

        // with its Mushak-6.3 the note names it; the invoice page offers "Print as Mushak-6.3"
        $page = (string) $this->getPage('invoices/' . $inv['id'])->response()->getBody();
        $this->assertStringContainsString('Print as Mushak-6.3', $page);
        $m = MushakService::forCurrent()->issue((int) $inv['order_id']);
        $a4 = (string) $this->getPage('invoices/' . $inv['id'] . '/print?size=a4&fragment=1')->response()->getBody();
        $this->assertStringContainsString('Mushak-6.3 tax invoice no. <b>' . $m['doc_no'], $a4);

        // the designs
        foreach (['classic', 'modern', 'compact'] as $tpl) {
            \App\Modules\Invoices\Services\InvoiceSettings::forCurrent()->save(['title' => 'INVOICE', 'prefix' => 'INV-', 'pad' => 5, 'reset' => 'never', 'template' => $tpl]);
            $this->act('owner');
            $this->assertStringContainsString('ivd-' . $tpl, (string) $this->getPage('invoices/' . $inv['id'] . '/print?size=a4&fragment=1')->response()->getBody());
            $this->assertStringContainsString('ivd ivd-' . $tpl, (string) $this->getPage('invoices/new')->response()->getBody(), 'the editor uses the same design');
        }
        $this->assertThrowsOmni(ValidationException::class, fn () => \App\Modules\Invoices\Services\InvoiceSettings::forCurrent()->save(['title' => 'INVOICE', 'prefix' => 'INV-', 'pad' => 5, 'reset' => 'never', 'template' => 'fancy']));
    }

    public function testPrintPages(): void
    {
        $this->enable();
        $inv = $this->invoice();
        $m = MushakService::forCurrent()->issue((int) $inv['order_id'], ['buyer_bin' => '1234567890101']);
        $this->assertSame('123456789-0101', $m['data']['buyer']['bin']);

        $a4 = $this->getPage('mushak/' . $m['id'] . '/print?fragment=1');
        $this->assertSame('a4', $a4->response()->getHeaderLine('X-Print-Kind'));
        $html = (string) $a4->response()->getBody();
        foreach (['মূসক-৬.৩', 'কর চালানপত্র', 'বিধি ৪০', 'নিবন্ধিত ব্যক্তির বিআইএন', self::TEST_BIN, 'সরবরাহের গন্তব্যস্থল', 'ইস্যুর সময়', 'সম্পূরক শুল্কের পরিমাণ', 'সকল প্রকার শুল্ক ও করসহ মূল্য', 'সর্বমোট', 'পদবী', 'সীল', $m['doc_no']] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertSame(2, substr_count($html, 'class="print-copy"'), 'original + duplicate by default');
        $this->assertStringContainsString('Original (buyer)', $html);
        $this->assertStringContainsString('Duplicate (supplier)', $html);

        $r80 = $this->getPage('mushak/' . $m['id'] . '/print?size=80&copies=1&fragment=1');
        $this->assertSame('receipt', $r80->response()->getHeaderLine('X-Print-Kind'));
        $this->assertStringContainsString('receipt w80', (string) $r80->response()->getBody());

        foreach (['mushak', 'mushak/settings', 'invoices/' . $inv['id'], 'orders/' . $inv['order_id'], 'mushak/' . $m['id'] . '/print'] as $uri) {
            $this->assertSame(200, $this->getPage($uri)->response()->getStatusCode(), $uri);
        }
        $this->getPage('mushak?mushak_export=csv')->assertSee($m['doc_no']);
    }
}
