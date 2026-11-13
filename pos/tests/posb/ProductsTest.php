<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class ProductsTest extends FeatureTestCase
{
    public function testCreateProductWithVatRuleAndOpeningStock(): void
    {
        $res = $this->postForm('products', [
            'name' => 'Galaxy A15', 'category_id' => $this->fx['category_id'], 'sku' => 'GA15', 'price' => '23000', 'cost' => '20000',
            'tax_mode' => 'exclusive', 'tax_rate' => '7.5', 'stock_type' => 'simple', 'opening_stock' => '12', 'is_online' => '1',
            'warranties' => [['part' => 'Battery', 'months' => '6']],
        ]);
        $res->assertRedirect();
        $p = db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('sku', 'GA15')->get()->getRowArray();
        $this->assertNotNull($p);
        $this->assertSame('exclusive', $p['tax_mode']);
        $this->assertEqualsWithDelta(7.5, (float) $p['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(12.0, (float) $p['stock_qty'], 0.001);
        $mv = db_connect()->table('stock_movements')->where('product_id', $p['id'])->get()->getResultArray();
        $this->assertCount(1, $mv);
        $this->assertSame('opening', $mv[0]['reason']);
        $this->assertEqualsWithDelta(12.0, (float) $mv[0]['qty_change'], 0.001);
        $this->assertSame(1, db_connect()->table('product_warranties')->where('product_id', $p['id'])->countAllResults());

        $list = $this->getPage('products');
        $list->assertOK();
        $list->assertSee('Galaxy A15');
        $list->assertSee('7.5% added');
        $this->getPage('products/' . $p['id'])->assertOK();
    }

    public function testRejectsRateNotOfferedByOutlet(): void
    {
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('products', ['name' => 'Odd rate', 'price' => '100', 'tax_mode' => 'inclusive', 'tax_rate' => '13', 'stock_type' => 'none']));
        $this->assertArrayHasKey('tax_rate', $e->details());
        $this->assertSame(0, db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('name', 'Odd rate')->countAllResults());
    }

    public function testSerialProductStock(): void
    {
        $this->postForm('products', ['name' => 'iPhone 15', 'price' => '150000', 'stock_type' => 'serial', 'serials' => "IMEI001\nIMEI002\nIMEI002"])->assertRedirect();
        $p = db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('name', 'iPhone 15')->get()->getRowArray();
        $this->assertEqualsWithDelta(2.0, (float) $p['stock_qty'], 0.001);
        $this->assertSame(2, db_connect()->table('product_serials')->where('product_id', $p['id'])->where('status', 'in_stock')->countAllResults());
    }

    public function testCashierCannotCreateProductsButCanView(): void
    {
        $e = $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('products', ['name' => 'Blocked', 'price' => '10']));
        $this->assertSame(403, $e->httpStatus());
        $this->assertSame(0, db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('name', 'Blocked')->countAllResults());
        $this->as('cashier')->getPage('products')->assertOK();
    }

    public function testCategoriesCrud(): void
    {
        $this->postForm('products/categories', ['name' => 'Chargers', 'icon' => 'plug'])->assertRedirect();
        $id = (int) db_connect()->table('categories')->where('business_id', $this->fx['business_id'])->where('name', 'Chargers')->get()->getRow('id');
        $this->assertGreaterThan(0, $id);
        $this->postForm('products/categories/' . $id, ['name' => 'Chargers & cables', 'icon' => 'cable', 'sort_order' => '3'])->assertRedirect();
        $this->getPage('products/categories')->assertSee('Chargers &amp; cables');
        $this->postForm('products/categories/' . $id . '/delete')->assertRedirect();
        $this->assertSame(0, db_connect()->table('categories')->where('id', $id)->countAllResults());
    }
}
