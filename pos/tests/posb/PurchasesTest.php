<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\ModuleDisabledException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class PurchasesTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        return $this->name() === 'testModuleIsOptIn' ? [] : ['addons' => ['purchases']];
    }

    public function testModuleIsOptIn(): void
    {
        $this->assertThrowsOmni(ModuleDisabledException::class, fn () => $this->getPage('purchases'));
    }

    public function testPurchaseReceiveRaisesStockAndSupplierDue(): void
    {
        $db = db_connect();
        $db->table('products')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'name' => 'USB cable', 'price' => 200, 'cost' => 100,
            'stock_type' => 'simple', 'stock_qty' => 5, 'created_at' => date('Y-m-d H:i:s')]);
        $pid = (int) $db->insertID();

        $this->postForm('purchases/suppliers', ['name' => 'Gadget Wholesale', 'phone' => '01700000000', 'opening' => '0'])->assertRedirect();
        $sid = (int) $db->table('suppliers')->where('business_id', $this->fx['business_id'])->where('name', 'Gadget Wholesale')->get()->getRow('id');

        $this->postForm('purchases', ['supplier_id' => $sid, 'bill_no' => 'GW-77', 'paid' => '300', 'method' => 'cash', 'receive' => '1', 'update_cost' => '1',
            'items' => [['product_id' => $pid, 'qty' => '20', 'unit_cost' => '90'], ['product_id' => '0', 'name' => 'Packing tape', 'qty' => '2', 'unit_cost' => '50']]])->assertRedirect();

        $p = $db->table('products')->where('id', $pid)->get()->getRowArray();
        $this->assertEqualsWithDelta(25.0, (float) $p['stock_qty'], 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $p['cost'], 0.001);
        $mv = $db->table('stock_movements')->where('product_id', $pid)->get()->getRowArray();
        $this->assertSame('purchase', $mv['reason']);
        $this->assertSame('purchase', $mv['ref_type']);

        $pu = $db->table('purchases')->where('supplier_id', $sid)->get()->getRowArray();
        $this->assertEqualsWithDelta(1900.0, (float) $pu['total'], 0.001);
        $this->assertSame('partial', $pu['status']);
        $this->assertEqualsWithDelta(1600.0, (float) $db->table('suppliers')->where('id', $sid)->get()->getRow('balance_due'), 0.001);

        // pay the rest → purchase paid, supplier clear
        $this->postForm('purchases/pay', ['supplier_id' => $sid, 'amount' => '1600', 'method' => 'bank'])->assertRedirect();
        $this->assertSame('paid', $db->table('purchases')->where('id', $pu['id'])->get()->getRow('status'));
        $this->assertEqualsWithDelta(0.0, (float) $db->table('suppliers')->where('id', $sid)->get()->getRow('balance_due'), 0.001);

        foreach (['purchases', 'purchases?tab=suppliers', 'purchases?tab=payments', 'purchases/new', 'purchases/' . $pu['id'], 'purchases/suppliers/' . $sid] as $uri) {
            $this->getPage($uri)->assertOK();
        }
    }
}
