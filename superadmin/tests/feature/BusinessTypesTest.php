<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\BusinessTypeSetup;
use OmniPOS\Core\Services\OutletProvisioner;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Super Admin › Business types: every type has its own method and setup, the editor saves what it
 * shows, reset restores the Bangladesh defaults and new outlets start from the stored setup.
 *
 * @internal
 */
final class BusinessTypesTest extends FeatureTestCase
{
    public function testPagesLoad(): void
    {
        $this->getPage('business-types')->assertOK();
        foreach (['electronics', 'restaurant', 'clinic', 'karaoke'] as $code) {
            $this->getPage('business-types/' . $code)->assertOK();
        }
    }

    public function testEveryTypeHasItsOwnDefaults(): void
    {
        foreach (array_column(db_connect()->table('business_types')->select('code')->get()->getResultArray(), 'code') as $code) {
            $this->assertTrue(BusinessTypeSetup::hasDefaults($code), $code . ' has no Bangladesh defaults');
            $s = BusinessTypeSetup::defaults($code);
            $this->assertNotSame([], $s['payments'], $code);
            $this->assertNotSame([], $s['features']['orderTypes'], $code);
        }
        $this->assertSame(10.0, BusinessTypeSetup::defaults('karaoke')['roundTo']);
        $this->assertFalse(BusinessTypeSetup::defaults('restaurant')['tax']['inclusive']);
    }

    public function testSaveAndReset(): void
    {
        $this->postForm('business-types/grocery', [
            'name' => 'Grocery & Super Shop', 'name_bn' => 'মুদি ও সুপার শপ', 'icon' => 'shopping-cart', 'store_kind' => 'shop', 'booking_kind' => 'pickup', 'sort_order' => '6', 'is_active' => '1',
            'features' => ['stock', 'weighted'], 'order_types' => "In Store\nHome Delivery = 35\nExpress = 10%", 'tax_label' => 'VAT', 'tax_rate' => '5', 'tax_inclusive' => '1',
            'round_to' => '1', 'low_stock' => '8', 'print_format' => 'thermal', 'payments' => ['cash', 'mobile'], 'hours_open' => '07:30', 'hours_close' => '22:30', 'hours_step' => '30',
            'labels' => ['catalog' => 'Bazar'], 'price_groups' => 'Retail, Shop owner', 'expense_cats' => 'Rent, Salary', 'terms' => 'Thanks',
        ])->assertRedirect();
        $s = BusinessTypeSetup::stored('grocery');
        $this->assertSame(['In Store', 'Home Delivery', 'Express'], $s['features']['orderTypes']);
        $this->assertSame(['kind' => 'fixed', 'value' => 35], ['kind' => $s['typeCharges']['Home Delivery']['kind'], 'value' => (int) $s['typeCharges']['Home Delivery']['value']]);
        $this->assertSame('percent', $s['typeCharges']['Express']['kind']);
        $this->assertTrue($s['features']['weighted']);
        $this->assertFalse($s['features']['barcode']);
        $this->assertSame(['cash', 'mobile'], $s['payments']);
        $this->assertSame('Bazar', $s['labels']['catalog']);
        $this->seeInDatabase('business_types', ['code' => 'grocery', 'booking_kind' => 'pickup']);

        $t = (new OutletProvisioner())->template('grocery');
        $this->assertSame(35.0, (float) $t['typeCharges']['Home Delivery']['value']);
        $this->assertNotEmpty($t['products'], 'starter catalogue stays in the template');

        $this->postForm('business-types/grocery/reset')->assertRedirect();
        $this->assertEquals(BusinessTypeSetup::defaults('grocery'), BusinessTypeSetup::stored('grocery'));
    }

    public function testBadChargeIsRejected(): void
    {
        $before = BusinessTypeSetup::stored('salon');
        $e = $this->assertThrowsOmni(ValidationException::class, fn () => $this->postForm('business-types/salon', ['name' => 'Salon', 'icon' => 'scissors', 'store_kind' => 'booking',
            'order_types' => 'Walk-in = abc', 'payments' => ['cash'], 'print_format' => 'thermal', 'hours_open' => '10:00', 'hours_close' => '21:00']));
        $this->assertArrayHasKey('order_types', $e->details());
        $this->assertSame($before, BusinessTypeSetup::stored('salon'));
    }
}
