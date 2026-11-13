<?php

declare(strict_types=1);

use App\Modules\Floor\Services\TableAdmin;
use App\Modules\Sales\Services\TillService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosA\TestCase;

/**
 * Tables › Manage and the table QR codes (Table QR ordering).
 *
 * @internal
 */
final class TablesTest extends TestCase
{
    protected function businessType(): string
    {
        return 'restaurant';
    }

    private function token(int $id): string
    {
        return (string) db_connect()->table('dining_tables')->select('qr_token')->where('id', $id)->get()->getRow('qr_token');
    }

    public function testAddRenameRemoveAndNewQr(): void
    {
        $admin = TableAdmin::forCurrent();
        $id = $admin->save(null, ['name' => 'Patio 1', 'area' => 'Patio', 'seats' => '6']);
        $first = $this->token($id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $first);

        // names are unique (orders refer to the table by name)
        $this->assertThrowsOmni(ValidationException::class, fn () => $admin->save(null, ['name' => 'Patio 1', 'seats' => 2]));

        $admin->save($id, ['name' => 'Patio A', 'seats' => 4]);
        $this->assertSame('Patio A', db_connect()->table('dining_tables')->select('name')->where('id', $id)->get()->getRow('name'));

        $admin->newQr($id);
        $this->assertNotSame($first, $this->token($id), 'the printed QR stops working');

        // an open bill on the table blocks renaming and removing it
        $p = db_connect()->table('products')->where('business_id', $this->fx['business_id'])->where('is_active', 1)->get()->getRowArray();
        (new TillService())->openOrder(['lines' => [['product_id' => (int) $p['id'], 'qty' => 1]], 'kitchen' => true, 'table_ref' => 'Patio A'], 'tok-tbl-00001');
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $admin->save($id, ['name' => 'Patio B', 'seats' => 4]));
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $admin->delete($id));
        $this->seeInDatabase('activity_log', ['action' => 'Table QR code replaced']);
    }

    public function testQrLeadsToTheOutletStorefront(): void
    {
        $db = db_connect();
        $admin = TableAdmin::forCurrent();
        $id = $admin->save(null, ['name' => 'QR 1', 'seats' => 2]);
        $this->assertNull($admin->orderUrl($this->token($id)), 'no published storefront yet');

        $db->table('sites')->where('business_id', $this->fx['business_id'])->update(['mode' => 'storefront']);
        $url = TableAdmin::forCurrent()->orderUrl($this->token($id));
        $this->assertStringContainsString('/t/' . $this->token($id), (string) $url);

        // the pages (module tableqr on for this account)
        $db->query('REPLACE INTO tenant_module_overrides (tenant_id, module_code, is_enabled, updated_at) VALUES (?, ?, 1, NOW())', [$this->fx['tenant_id'], 'tableqr']);
        service('moduleAccess')->invalidate($this->fx['tenant_id']);
        $this->getPage('floor/manage')->assertOK();
        $r = $this->getPage('floor/qr');
        $r->assertOK();
        $r->assertSee('QR 1');
    }
}
