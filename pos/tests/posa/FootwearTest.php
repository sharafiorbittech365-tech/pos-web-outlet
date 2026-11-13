<?php

declare(strict_types=1);

use App\Modules\Products\Services\ProductService;
use App\Modules\Sales\Services\TillCatalog;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Services\VariantMatrix;
use Tests\Support\PosA\TestCase;

/**
 * Footwear business template: size x colour options with their own SKU and stock, brand + model,
 * the matrix generator of the product form, scanning an option SKU at the till.
 *
 * @internal
 */
final class FootwearTest extends TestCase
{
    protected function businessType(): string
    {
        return 'footwear';
    }

    public function testTemplateSetsUpSizeColourStockWithSkus(): void
    {
        $db = db_connect();
        $this->assertSame('জুতার দোকান', $db->table('business_types')->select('name_bn')->where('code', 'footwear')->get()->getRow('name_bn'));
        $this->assertNotNull($db->table('plan_prices')->where('business_type', 'footwear')->get()->getRow('monthly_price'));
        $biz = service('posSession')->business();
        $this->assertTrue((bool) $biz['settings']['features']['matrix']);
        $this->assertSame(1, (int) $biz['stock_enabled']);

        $p = $db->table('products')->where('business_id', $this->fx['business_id'])->where('sku', 'SAMPLE-FS1')->get()->getRowArray();
        $this->assertSame('variant', $p['stock_type']);
        $this->assertSame('FS-1', $p['model']);
        $v = $db->table('product_variants')->where('product_id', $p['id'])->where('name', '41 / Black')->get()->getRowArray();
        $this->assertSame('SAMPLE-FS1-41-BLK', $v['sku']);
        $this->assertSame(['Size' => '41', 'Colour' => 'Black'], json_decode($v['attributes'], true));
        $sum = (float) $db->table('product_variants')->selectSum('stock_qty')->where('product_id', $p['id'])->get()->getRow('stock_qty');
        $this->assertEqualsWithDelta((float) $p['stock_qty'], $sum, 0.001, 'product stock = sum of option stock');

        // the storefront picks the minimal theme for shoe stores
        $this->assertTrue(in_array('footwear', array_column(\OmniPOS\Core\Database\Seeds\PlatformSeeder::TYPES, 0), true));
    }

    public function testMatrixGeneratorCreatesOneOptionPerSizeAndColour(): void
    {
        $svc = ProductService::make();
        $pid = $svc->save(null, ['name' => 'Runner', 'price' => '2500', 'sku' => 'RUN1', 'brand' => 'Fixture', 'model' => 'RN-1', 'stock_type' => 'simple',
            'matrix' => ['sizes' => '40-42', 'colours' => 'Black, Off-white', 'stock' => '3']]);
        $p = $svc->find($pid);
        $this->assertSame('variant', $p['stock_type'], 'a size grid keeps stock per option');
        $this->assertSame('RN-1', $p['model']);
        $this->assertSame(['40 / Black', '40 / Off-white', '41 / Black', '41 / Off-white', '42 / Black', '42 / Off-white'], array_column($p['variants'], 'name'));
        $this->assertSame(['RUN1-40-BLK', 'RUN1-40-OW'], array_slice(array_column($p['variants'], 'sku'), 0, 2));
        $this->assertSame(['Size' => '41', 'Colour' => 'Off-white'], $p['variants'][3]['attributes']);
        $this->assertSame([3.0, 3.0, 3.0, 3.0, 3.0, 3.0], array_map('floatval', array_column($p['variants'], 'stock_qty')));
        $this->assertEqualsWithDelta(18, (float) $p['stock_qty'], 0.001);

        // edit: the form posts the existing options; the generator adds only the new size
        $keep = array_map(static fn (array $v): array => ['id' => $v['id'], 'name' => $v['name'], 'sku' => $v['sku'], 'price_delta' => 0, 'mult' => 1,
            'attrs' => 'Size: ' . $v['attributes']['Size'] . ', Colour: ' . $v['attributes']['Colour']], $p['variants']);
        $svc->save($pid, ['name' => 'Runner', 'price' => '2500', 'sku' => 'RUN1', 'stock_type' => 'variant', 'variants' => $keep, 'matrix' => ['sizes' => '41, 43', 'colours' => 'Black', 'stock' => '1']]);
        $p = $svc->find($pid);
        $this->assertCount(7, $p['variants']);
        $this->assertSame('43 / Black', $p['variants'][6]['name']);
        $this->assertEqualsWithDelta(19, (float) $p['stock_qty'], 0.001);

        // scanning an option SKU at the till picks that size / colour; selling takes its own stock
        $hit = (new TillCatalog(service('posSession')->business()))->lookup('RUN1-41-OW');
        $this->assertSame($pid, $hit['product']['id']);
        $vid = (int) $hit['variant_id'];
        (new TillService())->checkout(['lines' => [['product_id' => $pid, 'variant_id' => $vid, 'qty' => 1]]], [['method' => 'cash', 'amount' => 999999]], 'tok-shoe-0001');
        $this->assertEqualsWithDelta(2, (float) db_connect()->table('product_variants')->select('stock_qty')->where('id', $vid)->get()->getRow('stock_qty'), 0.001);
        $this->assertEqualsWithDelta(3, (float) db_connect()->table('product_variants')->select('stock_qty')->where('product_id', $pid)->where('name', '41 / Black')->get()->getRow('stock_qty'), 0.001);
    }

    public function testGeneratedSkusNeverClashInTheOutlet(): void
    {
        $svc = ProductService::make();
        $svc->save(null, ['name' => 'Clash A', 'price' => '100', 'sku' => 'CLS-40-BLK', 'stock_type' => 'simple']);
        $pid = $svc->save(null, ['name' => 'Clash B', 'price' => '100', 'sku' => 'CLS', 'stock_type' => 'variant', 'matrix' => ['sizes' => '40', 'colours' => 'Black, Brown', 'stock' => '1']]);
        $skus = array_column($svc->find($pid)['variants'], 'sku');
        $this->assertSame(['CLS-40-BLK-2', 'CLS-40-BRN'], $skus);
    }

    public function testVariantMatrixHelpers(): void
    {
        $this->assertSame(['36', '37', '38', '39.5', 'XL'], VariantMatrix::parseList('36-38, 39.5; XL, xl'));
        $this->assertSame(['BLK', 'WHT', 'NB', 'TAN', '365', 'GLN', 'BRN'], array_map([VariantMatrix::class, 'code'], ['Black', 'White', 'Navy Blue', 'Tan', '36.5', 'Golden', 'Brown']));
        $this->assertCount(20, VariantMatrix::combos(['Size' => VariantMatrix::parseList('36-45'), 'Colour' => ['Black', 'Brown']]));
        $this->expectException(\OmniPOS\Core\Exceptions\BusinessRuleException::class);
        VariantMatrix::combos(['Size' => range(1, 30), 'Colour' => range(1, 30)]);
    }

    public function testProductFormShowsTheGenerator(): void
    {
        $page = $this->getPage('products/new');
        $page->assertOK();
        $page->assertSee('Size × colour generator');
        $page->assertSee('EU adult (36–45)');
        $page->assertSee('Model / style no.');
    }
}
