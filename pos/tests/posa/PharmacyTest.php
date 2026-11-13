<?php

declare(strict_types=1);

use App\Modules\Orders\Services\OrderActions;
use App\Modules\Pharmacy\Services\MedicineCatalog;
use App\Modules\Pharmacy\Services\PharmacyService;
use App\Modules\Pharmacy\Services\PharmacySettings;
use App\Modules\Products\Services\ProductService;
use App\Modules\Sales\Services\TillCatalog;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosA\TestCase;

/**
 * Pharmacy module: FEFO batch selection, expired-batch blocking (and the "warn" policy), returns by
 * batch, expiry / low-stock alerts, write-off, and the link to the shared medicine database.
 * Medicine rows here are made-up fixtures ("Testbrand …") — not medicine data.
 *
 * @internal
 */
final class PharmacyTest extends TestCase
{
    protected function businessType(): string
    {
        return 'pharmacy';
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    /** A batch-tracked product with the given batches [no, expiry offset in days, qty]. */
    private function batchProduct(array $batches, string $name = 'Fixture Tablet'): int
    {
        $rows = [];
        foreach ($batches as $i => [$no, $off, $qty]) {
            $rows[$i] = ['no' => $no, 'exp' => $this->day($off), 'qty' => $qty];
        }

        return ProductService::make()->save(null, ['name' => $name . ' ' . bin2hex(random_bytes(3)), 'price' => '10', 'cost' => '6', 'stock_type' => 'batch', 'batches' => $rows]);
    }

    private function batch(int $pid, string $no): float
    {
        return (float) db_connect()->table('product_batches')->select('qty')->where('product_id', $pid)->where('batch_no', $no)->get()->getRow('qty');
    }

    private function sell(int $pid, float $qty, string $token): array
    {
        return (new TillService())->checkout(['lines' => [['product_id' => $pid, 'qty' => $qty]]], [['method' => 'cash', 'amount' => 999999]], $token)['order'];
    }

    public function testFefoTakesTheEarliestUnexpiredBatchFirst(): void
    {
        $pid = $this->batchProduct([['LATE', 200, 50], ['GONE', -5, 10], ['SOON', 10, 3]]);
        $o = $this->sell($pid, 5, 'tok-fefo-0001');

        $this->assertEqualsWithDelta(0, $this->batch($pid, 'SOON'), 0.001, 'earliest expiry first');
        $this->assertEqualsWithDelta(48, $this->batch($pid, 'LATE'), 0.001, 'the rest from the next batch');
        $this->assertEqualsWithDelta(10, $this->batch($pid, 'GONE'), 0.001, 'expired batch untouched');
        $soonId = (int) db_connect()->table('product_batches')->select('id')->where('product_id', $pid)->where('batch_no', 'SOON')->get()->getRow('id');
        $this->assertSame($soonId, (int) $o['items'][0]['batch_id']);
        $this->assertEqualsWithDelta(58, (float) db_connect()->table('products')->select('stock_qty')->where('id', $pid)->get()->getRow('stock_qty'), 0.001);
    }

    public function testExpiredBatchesAreBlockedByDefaultAndSoldLastWhenSetToWarn(): void
    {
        $pid = $this->batchProduct([['OLD', -3, 5], ['NEW', 90, 1]]);
        $this->assertSame('block', PharmacySettings::current()['expiredSale']);
        $e = $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->sell($pid, 3, 'tok-exp-00001'));
        $this->assertStringContainsString('unexpired', $e->getMessage());
        $this->assertEqualsWithDelta(1, $this->batch($pid, 'NEW'), 0.001, 'nothing taken');

        PharmacySettings::save(['near_days' => '45', 'expired_sale' => 'warn']);
        $this->act('owner');
        $this->assertSame(['nearDays' => 45, 'expiredSale' => 'warn'], PharmacySettings::current());
        $this->sell($pid, 3, 'tok-exp-00002');
        $this->assertEqualsWithDelta(0, $this->batch($pid, 'NEW'), 0.001, 'unexpired stock goes first');
        $this->assertEqualsWithDelta(3, $this->batch($pid, 'OLD'), 0.001);
        $this->seeInDatabase('stock_movements', ['product_id' => $pid, 'note' => 'Batch OLD (expired)']);

        // online orders never take expired batches, whatever the setting
        \Config\Services::resetSingle('stock');
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => service('stock')->consume(db_connect()->table('products')->where('id', $pid)->get()->getRowArray(), 1, null, null, 'web', 'order', 0));

        $this->assertThrowsOmni(ValidationException::class, static fn () => PharmacySettings::save(['near_days' => '0', 'expired_sale' => 'sometimes']));
    }

    public function testReturnGoesBackToTheBatchesItWasSoldFrom(): void
    {
        $pid = $this->batchProduct([['A1', 20, 3], ['B1', 300, 50]]);
        $o = $this->sell($pid, 5, 'tok-retb-0001');
        $this->assertEqualsWithDelta(0, $this->batch($pid, 'A1'), 0.001);
        $this->assertEqualsWithDelta(48, $this->batch($pid, 'B1'), 0.001);

        (new OrderActions())->returnItems((int) $o['id'], [(int) $o['items'][0]['id'] => 4], 'customer return');
        $this->assertEqualsWithDelta(50, $this->batch($pid, 'B1'), 0.001, 'last-picked batch refilled first, never above what it gave');
        $this->assertEqualsWithDelta(2, $this->batch($pid, 'A1'), 0.001);
        (new OrderActions())->returnItems((int) $o['id'], [(int) $o['items'][0]['id'] => 1], 'customer return');
        $this->assertEqualsWithDelta(3, $this->batch($pid, 'A1'), 0.001);
        $this->assertEqualsWithDelta(53, (float) db_connect()->table('products')->select('stock_qty')->where('id', $pid)->get()->getRow('stock_qty'), 0.001);
    }

    public function testAlertsWriteOffAndPages(): void
    {
        $pid = $this->batchProduct([['EXP1', -10, 4], ['NEAR1', 5, 2], ['FAR1', 400, 30]], 'Alert Fixture');
        $svc = PharmacyService::make();
        $before = $svc->stats();
        $this->assertGreaterThanOrEqual(1, $before['expired']);
        $this->assertGreaterThanOrEqual(1, $before['near']);
        $near = array_column($svc->batchesQuery('near')->get()->getResultArray(), 'batch_no');
        $this->assertContains('NEAR1', $near);
        $this->assertNotContains('FAR1', $near);
        $this->assertContains('EXP1', array_column($svc->batchesQuery('expired')->get()->getResultArray(), 'batch_no'));

        // tiles at the till warn about the near expiry
        $tile = array_values(array_filter((new TillCatalog(service('posSession')->business()))->products('Alert Fixture')['items'], static fn (array $t): bool => $t['id'] === $pid))[0];
        $this->assertSame('Exp ' . date('m/y', strtotime($this->day(5))), $tile['badge']);

        $page = $this->getPage('pharmacy');
        $page->assertOK();
        $page->assertSee('Near expiry');
        $page = $this->getPage('pharmacy?view=expired');
        $page->assertOK();
        $page->assertSee('EXP1');
        $this->getPage('pharmacy?view=low')->assertOK();

        $expId = (int) db_connect()->table('product_batches')->select('id')->where('product_id', $pid)->where('batch_no', 'EXP1')->get()->getRow('id');
        $nearId = (int) db_connect()->table('product_batches')->select('id')->where('product_id', $pid)->where('batch_no', 'NEAR1')->get()->getRow('id');
        $this->assertThrowsOmni(BusinessRuleException::class, static fn () => PharmacyService::make()->writeOff($nearId));
        $this->postForm('pharmacy/batches/' . $expId . '/write-off')->assertRedirect();
        $this->assertEqualsWithDelta(0, $this->batch($pid, 'EXP1'), 0.001);
        $this->assertEqualsWithDelta(32, (float) db_connect()->table('products')->select('stock_qty')->where('id', $pid)->get()->getRow('stock_qty'), 0.001);
        $this->seeInDatabase('activity_log', ['action' => 'Expired batch written off']);

        // settings form (owner has settings.manage); a cashier cannot write off
        $this->postForm('pharmacy/settings', ['near_days' => '60', 'expired_sale' => 'block'])->assertRedirect();
        $this->act('owner');
        $this->assertSame(60, PharmacySettings::current()['nearDays']);
    }

    public function testModuleIsOnlyForPharmacyOutletsInTheCatalogue(): void
    {
        $db = db_connect();
        $this->assertNotNull($db->table('modules')->where('code', 'pharmacy')->get()->getRow());
        $this->assertNotNull($db->table('module_prices')->where('module_code', 'pharmacy')->where('business_type', 'pharmacy')->get()->getRow('monthly_price'));
        $this->assertNull($db->table('module_prices')->where('module_code', 'pharmacy')->where('business_type', 'grocery')->get()->getRow('monthly_price'));
        $this->assertTrue(service('posSession')->moduleOn('pharmacy'));
    }

    public function testMedicineDatabaseLinkAndSearch(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $tag = bin2hex(random_bytes(3));
        $db->table('medicines')->insert(['brand_id' => 'TFX-' . $tag, 'brand_name' => 'Testbrand ' . $tag, 'type' => 'fixture', 'slug' => 'testbrand-' . $tag, 'dosage_form' => 'Fixture Tablet',
            'generic' => 'Test Generic ' . $tag, 'strength' => '1 unit', 'manufacturer' => 'Fixture Labs', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $mid = (int) $db->insertID();
        $db->table('medicines')->insert(['brand_id' => 'TFX-OFF-' . $tag, 'brand_name' => 'Testbrand Off ' . $tag, 'slug' => 'testbrand-off-' . $tag, 'is_active' => 0, 'created_at' => $now]);
        $off = (int) $db->insertID();

        $hits = MedicineCatalog::search('Testbrand ' . $tag, $this->fx['business_id']);
        $this->assertSame([$mid], array_column($hits, 'id'), 'disabled medicines are not offered');
        $this->assertSame('Testbrand ' . $tag . ' 1 unit Fixture Tablet', $hits[0]['name']);
        $this->assertFalse($hits[0]['linked']);
        $this->assertSame([$mid], array_column(MedicineCatalog::search('Test Generic ' . $tag, $this->fx['business_id']), 'id'), 'generic-name search');

        $json = $this->getPage('pharmacy/medicines/search?q=' . rawurlencode('Testbrand ' . $tag));
        $json->assertOK();
        $this->assertSame($mid, json_decode($json->getJSON(), true)['data'][0]['id']);

        $new = $this->getPage('products/new?medicine=' . $mid);
        $new->assertOK();
        $new->assertSee('Testbrand ' . $tag . ' 1 unit Fixture Tablet');
        $this->getPage('pharmacy/medicines')->assertOK();

        $pid = ProductService::make()->save(null, ['name' => $hits[0]['name'], 'price' => '5', 'stock_type' => 'batch', 'medicine_id' => (string) $mid, 'generic_name' => $hits[0]['generic']]);
        $this->assertSame($mid, (int) $db->table('products')->select('medicine_id')->where('id', $pid)->get()->getRow('medicine_id'));
        $this->assertTrue(MedicineCatalog::search('Testbrand ' . $tag, $this->fx['business_id'])[0]['linked']);
        $this->assertThrowsOmni(ValidationException::class, static fn () => ProductService::make()->save(null, ['name' => 'X', 'price' => '1', 'medicine_id' => (string) $off]));
        // the shared row is read-only for outlets: unlinking only clears the product's link
        ProductService::make()->save($pid, ['name' => $hits[0]['name'], 'price' => '5', 'stock_type' => 'batch', 'medicine_id' => '0']);
        $this->assertNull($db->table('products')->select('medicine_id')->where('id', $pid)->get()->getRow('medicine_id'));
        $this->assertSame(1, $db->table('medicines')->where('id', $mid)->countAllResults());
    }
}
