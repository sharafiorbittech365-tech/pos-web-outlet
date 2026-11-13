<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Services\MedicineImporter;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Medicine master database: the importer (valid rows, duplicates, invalid rows, dry run,
 * idempotent upsert, chunks) and Super Admin › Medicine database. Every record below is a made-up
 * test fixture ("Testbrand …", "Fixture Labs") — not medicine data.
 *
 * @internal
 */
final class MedicinesTest extends FeatureTestCase
{
    private const HEADER = "brand_id,brand_name,type,slug,dosage_form,generic,strength,manufacturer,package_container,package_size\n";

    /** @var list<string> */
    private array $files = [];

    private function file(string $body, string $ext = 'csv'): string
    {
        $path = WRITEPATH . 'uploads/med-test-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $body);
        $this->files[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function clean(): void
    {
        db_connect()->table('medicines')->like('brand_id', 'T', 'after')->delete();
    }

    public function testValidImportIsIdempotentAndUpdatesChangedRows(): void
    {
        $this->clean();
        $csv = "\xEF\xBB\xBF" . self::HEADER
            . "T1001,Testbrand Alpha,fixture,testbrand-alpha-1,Fixture Tablet,Test Generic A,1 unit,Fixture Labs,Fixture strip,10 x 1\n"
            . "T1002,Testbrand Beta,fixture,,Fixture Syrup,Test Generic B,2 units,Fixture Labs,Fixture bottle,1 x 1\n"
            . "\n"
            . "T1003,\"Testbrand Gamma, Kids\",fixture,testbrand-gamma,Fixture Drops,Test Generic A,,Other Fixture Co,,\n";
        $path = $this->file($csv);
        $r = (new MedicineImporter())->importFile($path);
        $this->assertSame([3, 3, 3, 0, 0, 0], [$r['rows'], $r['valid'], $r['inserted'], $r['updated'], $r['unchanged'], $r['invalid']]);

        $db = db_connect();
        $beta = $db->table('medicines')->where('brand_id', 'T1002')->get()->getRowArray();
        $this->assertSame('testbrand-beta-2-units-fixture-syrup', $beta['slug'], 'slug made from brand + strength + dosage form');
        $this->assertSame('Testbrand Gamma, Kids', $db->table('medicines')->where('brand_id', 'T1003')->get()->getRow('brand_name'));
        $this->assertNull($db->table('medicines')->where('brand_id', 'T1003')->get()->getRow('strength'), 'empty cells are stored as NULL');

        // the same file again: nothing written
        $r = (new MedicineImporter())->importFile($path);
        $this->assertSame([0, 0, 3], [$r['inserted'], $r['updated'], $r['unchanged']]);

        // a switched-off row stays off when its brand_id is imported with a change
        $db->table('medicines')->where('brand_id', 'T1001')->update(['is_active' => 0]);
        $r = (new MedicineImporter())->importFile($this->file(self::HEADER . "T1001,Testbrand Alpha,fixture,testbrand-alpha-1,Fixture Tablet,Test Generic A,1 unit,Fixture Labs,Fixture strip,20 x 1\n"));
        $this->assertSame([0, 1], [$r['inserted'], $r['updated']]);
        $row = $db->table('medicines')->where('brand_id', 'T1001')->get()->getRowArray();
        $this->assertSame('20 x 1', $row['package_size']);
        $this->assertSame(0, (int) $row['is_active']);
        $this->assertSame((int) $beta['id'], (int) $db->table('medicines')->where('brand_id', 'T1002')->get()->getRow('id'), 'ids never change (prescriptions and products refer to them)');

        // JSON, {"data": [...]}, missing optional keys allowed
        $json = json_encode(['data' => [['brand_id' => 'T2001', 'brand_name' => 'Testbrand Delta', 'generic' => 'Test Generic C'], ['brand_id' => 'T1002', 'brand_name' => 'Testbrand Beta', 'type' => 'fixture',
            'slug' => 'testbrand-beta-2-units-fixture-syrup', 'dosage_form' => 'Fixture Syrup', 'generic' => 'Test Generic B', 'strength' => '2 units', 'manufacturer' => 'Fixture Labs', 'package_container' => 'Fixture bottle', 'package_size' => '1 x 1']]]);
        $r = (new MedicineImporter())->importFile($this->file((string) $json, 'json'));
        $this->assertSame([2, 1, 0, 1], [$r['valid'], $r['inserted'], $r['updated'], $r['unchanged']]);
    }

    public function testDuplicatesAndInvalidRowsAreReportedAndSkipped(): void
    {
        $this->clean();
        $db = db_connect();
        $db->table('medicines')->insert(['brand_id' => 'T9000', 'brand_name' => 'Testbrand Existing', 'slug' => 'taken-slug', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $csv = self::HEADER
            . "T3001,Testbrand One,,one,,,,,,\n"                 // line 2: ok
            . "T3001,Testbrand One Again,,one-again,,,,,,\n"     // line 3: duplicate brand_id in the file
            . "T3002,Testbrand Two,,one,,,,,,\n"                 // line 4: slug used on line 2
            . "T3003,,,three,,,,,,\n"                            // line 5: brand_name missing
            . ",Testbrand Four,,four,,,,,,\n"                    // line 6: brand_id missing
            . "T3005,Testbrand Five,,Bad Slug!,,,,,,\n"          // line 7: bad slug
            . "T3006,Testbrand Six,,taken-slug,,,,,,\n"          // line 8: slug belongs to T9000 in the table
            . "T3007,Testbrand Seven,,seven\n"                   // line 9: wrong number of columns
            . "T3008,Testbrand Eight,,eight,,,,,,\n";            // line 10: ok
        $r = (new MedicineImporter())->importFile($this->file($csv));
        $this->assertSame([9, 2, 2, 7], [$r['rows'], $r['valid'], $r['inserted'], $r['invalid']]);
        $byLine = array_column($r['errors'], 'message', 'line');
        ksort($byLine); // table checks (line 8) run when the chunk is written, after the file checks
        $this->assertSame([3, 4, 5, 6, 7, 8, 9], array_keys($byLine));
        $this->assertStringContainsString('Duplicate brand_id T3001 (first on line 2)', $byLine[3]);
        $this->assertStringContainsString('Duplicate slug one', $byLine[4]);
        $this->assertStringContainsString('brand_name is required', $byLine[5]);
        $this->assertStringContainsString('brand_id is required', $byLine[6]);
        $this->assertStringContainsString('slug may contain only', $byLine[7]);
        $this->assertStringContainsString('already belongs to another medicine', $byLine[8]);
        $this->assertStringContainsString('Expected 10 columns', $byLine[9]);
        $this->assertSame(['T3001', 'T3008'], array_column($db->table('medicines')->select('brand_id')->whereIn('brand_id', ['T3001', 'T3002', 'T3003', 'T3005', 'T3006', 'T3007', 'T3008'])->orderBy('brand_id')->get()->getResultArray(), 'brand_id'));
        $this->assertSame('Testbrand One', $db->table('medicines')->where('brand_id', 'T3001')->get()->getRow('brand_name'), 'the first row wins');

        // the header must name exactly the ten fields
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new MedicineImporter())->importFile($this->file("brand_id,brand_name,price\nT1,X,1\n")));
        // JSON: unknown keys are a row error; a non-list is a file error
        $r = (new MedicineImporter())->importFile($this->file((string) json_encode([['brand_id' => 'T3100', 'brand_name' => 'Testbrand', 'dose' => 'x']]), 'json'));
        $this->assertSame(1, $r['invalid']);
        $this->assertStringContainsString('Unknown field(s): dose', $r['errors'][0]['message']);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => (new MedicineImporter())->importFile($this->file('{"x": 1}', 'json')));
    }

    public function testDryRunWritesNothingAndLargeFilesGoInChunks(): void
    {
        $this->clean();
        $rows = [];
        $n = MedicineImporter::CHUNK + 20;
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = 'T5' . str_pad((string) $i, 4, '0', STR_PAD_LEFT) . ',Testbrand ' . $i . ',fixture,,Fixture Tablet,Test Generic,,' . 'Fixture Labs,,';
        }
        $path = $this->file(self::HEADER . implode("\n", $rows) . "\n");
        $r = (new MedicineImporter())->importFile($path, true);
        $this->assertTrue($r['dry_run']);
        $this->assertSame([$n, $n, $n, 0], [$r['rows'], $r['valid'], $r['inserted'], $r['invalid']]);
        $this->assertSame(0, db_connect()->table('medicines')->like('brand_id', 'T5', 'after')->countAllResults());

        $r = (new MedicineImporter())->importFile($path);
        $this->assertSame($n, $r['inserted']);
        $this->assertSame($n, db_connect()->table('medicines')->like('brand_id', 'T5', 'after')->countAllResults());
    }

    public function testSuperAdminPagesImportAndToggle(): void
    {
        $this->clean();
        $res = $this->getPage('medicines');
        $res->assertOK();
        $res->assertSee('Medicine database');
        $res = $this->getPage('medicines/template.csv');
        $res->assertOK();
        $res->assertSee('brand_id,brand_name,type,slug');
        // the upload needs a file
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\ValidationException::class, fn () => (new \App\Modules\Medicines\Services\MedicineAdmin($this->actor()))->import(null, true));
    }

    public function testToggleAndListAndRbac(): void
    {
        $this->clean();
        $db = db_connect();
        $db->table('medicines')->insert(['brand_id' => 'T7001', 'brand_name' => 'Testbrand Toggle', 'slug' => 'testbrand-toggle', 'generic' => 'Test Generic T', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $id = (int) $db->insertID();
        $res = $this->getPage('medicines');
        $res->assertOK();
        $res->assertSee('Testbrand Toggle');
        $this->postForm('medicines/' . $id . '/toggle')->assertRedirect();
        $this->assertSame(0, (int) $db->table('medicines')->where('id', $id)->get()->getRow('is_active'));
        $this->seeInDatabase('platform_audit', ['action' => 'Medicine disabled']);

        // finance staff have no settings.manage
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\PermissionDeniedException::class, fn () => $this->as('finance')->getPage('medicines'));
    }
}
