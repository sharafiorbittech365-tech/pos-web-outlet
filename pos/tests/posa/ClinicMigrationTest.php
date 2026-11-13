<?php

declare(strict_types=1);

use OmniPOS\Core\Database\Migrations\AddBookingDoctor;
use OmniPOS\Core\Database\Migrations\CreateClinicTables;
use OmniPOS\Core\Database\Seeds\PlatformSeeder;
use Tests\Support\PosA\TestCase;

/**
 * Migrations 000022 / 000023 on an existing platform (business types already seeded, no clinic yet):
 * down() removes everything, up() adds the clinic type with its plan & module prices and the module.
 *
 * @internal
 */
final class ClinicMigrationTest extends TestCase
{
    public function testDownAndUpOnAnExistingPlatform(): void
    {
        $dir = ROOTPATH . 'vendor/omnipos/core/src/Database/Migrations/';
        require_once $dir . '2026-10-28-000022_CreateClinicTables.php';
        require_once $dir . '2026-10-28-000023_AddBookingDoctor.php';
        require_once $dir . '2026-10-28-000029_AddPrescriptionPadStyle.php';
        $db = db_connect();
        $forge = \Config\Database::forge();
        (new \OmniPOS\Core\Database\Migrations\AddPrescriptionPadStyle($forge))->down();
        (new AddBookingDoctor($forge))->down();
        (new CreateClinicTables($forge))->down();
        $this->assertFalse($db->tableExists('clinic_doctors', false));
        $this->assertNotContains('doctor_id', array_column($db->query('SHOW COLUMNS FROM bookings')->getResultArray(), 'Field'));
        $this->assertSame(0, $db->table('modules')->where('code', 'clinic')->countAllResults());

        // a platform from before the clinic type existed
        $db->query("DELETE FROM module_prices WHERE business_type = 'clinic'");
        $db->query("DELETE FROM plan_prices WHERE business_type = 'clinic'");
        $db->query('SET FOREIGN_KEY_CHECKS = 0'); // clinic outlets of earlier tests point at the type
        $db->query("DELETE FROM business_types WHERE code = 'clinic'");
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new CreateClinicTables($forge))->up();
        (new AddBookingDoctor($forge))->up();
        (new \OmniPOS\Core\Database\Migrations\AddPrescriptionPadStyle($forge))->up();
        $this->assertTrue($db->tableExists('clinic_prescriptions', false));
        $this->assertContains('serial_no', array_column($db->query('SHOW COLUMNS FROM bookings')->getResultArray(), 'Field'));
        $type = $db->table('business_types')->where('code', 'clinic')->get()->getRowArray();
        $this->assertSame('appointment', $type['booking_kind']);
        $this->assertSame('ডাক্তারের চেম্বার ও ক্লিনিক', $type['name_bn']);
        $this->assertSame(3, $db->table('plan_prices')->where('business_type', 'clinic')->countAllResults());
        $this->assertSame(['bookings'], json_decode((string) $db->table('modules')->select('needs')->where('code', 'clinic')->get()->getRow('needs'), true));
        // the clinic module is priced for clinics only; bookings is available to clinics
        $this->assertNotNull($db->table('module_prices')->select('monthly_price')->where('module_code', 'clinic')->where('business_type', 'clinic')->get()->getRow('monthly_price'));
        $this->assertNull($db->table('module_prices')->select('monthly_price')->where('module_code', 'clinic')->where('business_type', 'grocery')->get()->getRow('monthly_price'));
        $this->assertNotNull($db->table('module_prices')->select('monthly_price')->where('module_code', 'bookings')->where('business_type', 'clinic')->get()->getRow('monthly_price'));
        $this->assertSame(count(PlatformSeeder::TYPES), $db->table('module_prices')->where('module_code', 'clinic')->countAllResults());
        service('catalog')->flush();
    }
}
