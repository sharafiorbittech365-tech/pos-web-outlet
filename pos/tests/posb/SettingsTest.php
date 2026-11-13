<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosB\FeatureTestCase;

/**
 * @internal
 */
final class SettingsTest extends FeatureTestCase
{
    protected function fixtureOptions(): array
    {
        return ['limit_outlets' => 2];
    }

    public function testSaveBusinessSettings(): void
    {
        $this->getPage('settings')->assertOK();
        $this->postForm('settings', [
            'name' => 'Renamed Shop', 'branch' => 'Uttara', 'address' => 'Road 1', 'phone' => '0171', 'lat' => '23.8759', 'lng' => '90.3795', 'area' => 'Uttara', 'city' => 'Dhaka', 'delivery_km' => '8',
            'currency_symbol' => 'Tk', 'currency_code' => 'bdt', 'currency_decimals' => '0', 'tax_label' => 'VAT', 'tax_rate' => '7.5', 'tax_rates' => '0, 5, 7.5, 15', 'tax_inclusive' => '0',
            'service_charge_pct' => '0', 'round_to' => '1', 'low_stock_alert' => '4', 'stock_enabled' => '1', 'payments' => ['cash', 'mobile'],
            'invoice_prefix' => 'TS-', 'invoice_pad' => '5', 'invoice_year' => '1', 'print_format' => 'a4', 'terms' => 'No returns after 7 days.',
            'features' => ['stock' => '1', 'serials' => '1'], 'expense_cats' => 'Rent, Salary, Rent', 'order_types' => 'In store, Delivery', 'type_charges' => 'Delivery=60, Express=10%',
        ])->assertRedirect();
        $b = db_connect()->table('businesses')->where('id', $this->fx['business_id'])->get()->getRowArray();
        $this->assertSame('Renamed Shop', $b['name']);
        $this->assertSame('BDT', $b['currency_code']);
        $this->assertEqualsWithDelta(7.5, (float) $b['tax_rate'], 0.001);
        $this->assertSame(0, (int) $b['tax_inclusive']);
        $this->assertSame('0, 5, 7.5, 15', $b['tax_rates']);
        $s = json_decode((string) $b['settings'], true);
        $this->assertSame(['Rent', 'Salary'], $s['expenseCats']);
        $this->assertSame(['cash', 'mobile'], $s['payments']);
        $this->assertSame('TS-', $s['invoice']['prefix']);
        $this->assertTrue($s['invoice']['num']['year']);
        $this->assertEquals(['kind' => 'percent', 'value' => 10], $s['typeCharges']['Express']);
        $this->assertTrue($s['features']['serials']);
        $this->assertFalse($s['features']['kitchen']);
        // keys the form does not own are kept
        $this->assertSame(['earnPer' => 100, 'pointValue' => 1], $s['loyalty']);

        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('settings', ['name' => '', 'payments' => []]));
        $this->assertArrayHasKey('name', $e->details());
        $this->assertArrayHasKey('payments', $e->details());
    }

    public function testAddOutletWithinPlanLimit(): void
    {
        $this->postForm('settings/outlets', ['name' => 'Second Branch', 'type' => 'grocery', 'area' => 'Mirpur', 'copy_team' => '1'])->assertRedirect();
        $b = db_connect()->table('businesses')->where('tenant_id', $this->fx['tenant_id'])->where('name', 'Second Branch')->get()->getRowArray();
        $this->assertNotNull($b);
        $this->assertSame('grocery', $b['business_type']);
        // owner (owner role) and cashier (same role) can open it
        $this->assertSame(2, db_connect()->table('user_businesses')->where('business_id', $b['id'])->countAllResults());
        $this->assertGreaterThan(0, db_connect()->table('categories')->where('business_id', $b['id'])->countAllResults());
        $this->getPage('settings/outlets')->assertSee('Second Branch');

        // limit 2 reached
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $this->postForm('settings/outlets', ['name' => 'Third', 'type' => 'grocery']));
    }
}
