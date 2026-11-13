<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class InventoryTest extends FeatureTestCase
{
    private function product(string $type = 'simple', float $qty = 10): int
    {
        $db = db_connect();
        $db->table('products')->insert(['tenant_id' => $this->fx['tenant_id'], 'business_id' => $this->fx['business_id'], 'name' => 'Charger ' . $type, 'price' => 500, 'cost' => 300,
            'stock_type' => $type, 'stock_qty' => $qty, 'created_at' => date('Y-m-d H:i:s')]);

        return (int) $db->insertID();
    }

    public function testStockAdjustmentWritesMovement(): void
    {
        $pid = $this->product();
        $this->postForm('inventory/' . $pid . '/adjust', ['delta' => '-3', 'reason' => 'damaged', 'note' => 'Dropped'])->assertRedirect();
        $this->assertEqualsWithDelta(7.0, (float) db_connect()->table('products')->where('id', $pid)->get()->getRow('stock_qty'), 0.001);
        $mv = db_connect()->table('stock_movements')->where('product_id', $pid)->get()->getRowArray();
        $this->assertSame('adjust', $mv['reason']);
        $this->assertEqualsWithDelta(-3.0, (float) $mv['qty_change'], 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $mv['qty_after'], 0.001);
        $this->assertStringContainsString('Dropped', (string) $mv['note']);

        // never below zero
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('inventory/' . $pid . '/adjust', ['delta' => '-50', 'reason' => 'count']));

        foreach (['inventory', 'inventory/' . $pid, 'inventory/movements', 'inventory/expiry', 'inventory/serials', 'inventory?f=low'] as $uri) {
            $this->getPage($uri)->assertOK();
        }
    }

    public function testBatchReceiveAndCorrection(): void
    {
        $pid = $this->product('batch', 0);
        $this->postForm('inventory/' . $pid . '/batches', ['batch_no' => 'B-1', 'expires_on' => date('Y-m-d', strtotime('+20 days')), 'qty' => '40'])->assertRedirect();
        $b = db_connect()->table('product_batches')->where('product_id', $pid)->get()->getRowArray();
        $this->assertEqualsWithDelta(40.0, (float) $b['qty'], 0.001);
        $this->postForm('inventory/' . $pid . '/batches/' . $b['id'], ['delta' => '-5', 'reason' => 'damaged'])->assertRedirect();
        $this->assertEqualsWithDelta(35.0, (float) db_connect()->table('products')->where('id', $pid)->get()->getRow('stock_qty'), 0.001);
        $this->assertSame(2, db_connect()->table('stock_movements')->where('product_id', $pid)->countAllResults());
        $this->getPage('inventory', ['f' => 'expiring'])->assertSee('Charger batch');
    }

    public function testCashierCannotAdjust(): void
    {
        $pid = $this->product();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->as('cashier')->postForm('inventory/' . $pid . '/adjust', ['delta' => '5']));
    }
}
