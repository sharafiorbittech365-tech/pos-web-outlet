<?php

declare(strict_types=1);

use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\LocationCatalog;
use OmniPOS\Core\Services\LocationImporter;
use Tests\Support\SuperAdmin\FeatureTestCase;

/**
 * Location datasets: the importer (validation, idempotent upsert, duplicates, relationships kept)
 * and the Super Admin > Locations pages. All location data here is a made-up fixture (country "ZZ").
 *
 * @internal
 */
final class LocationsTest extends FeatureTestCase
{
    private function csv(string $body): string
    {
        $path = WRITEPATH . 'uploads/loc-test-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, $body);
        $this->files[] = $path;

        return $path;
    }

    /** @var list<string> temp files to remove */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function clean(): void
    {
        $db = db_connect();
        $db->query('DELETE a FROM areas a JOIN cities c ON c.id = a.city_id WHERE c.country_code IN (\'ZZ\', \'ZY\')');
        $db->table('cities')->whereIn('country_code', ['ZZ', 'ZY'])->delete();
        $db->table('countries')->whereIn('code', ['ZZ', 'ZY'])->delete();
        cache()->clean();
    }

    public function testImportIsValidatedIdempotentAndKeepsIds(): void
    {
        $this->clean();
        $file = $this->csv("\xEF\xBB\xBFcity,city_kind,region,city_code,area,area_kind,area_code,postal_code\n"
            . "Alpha,district,East,ZZ-A,North Ward,thana,A1,1200\n"
            . "Alpha,district,East,ZZ-A,South Ward,thana,A2,\n"
            . "Alpha,,East,,north ward,thana,,\n"          // duplicate area (same name, other case): skipped
            . "Beta,district,West,ZZ-B,,,,\n"               // a district without areas
            . "\n");
        [$meta, $rows] = LocationImporter::readFile($file);
        $importer = new LocationImporter();

        // a new country needs its name
        $e = $this->assertThrowsOmni(ValidationException::class, static fn () => $importer->import(['code' => 'ZZ'], $rows));
        $this->assertArrayHasKey('country.name', $e->details());

        // dry run: counts, nothing saved
        $s = $importer->import(['code' => 'zz', 'name' => 'Testland', 'city_label' => 'District', 'area_label' => 'Thana'] + $meta, $rows, true);
        $this->assertTrue($s['dry_run']);
        $this->assertSame([2, 2, 1], [$s['cities_created'], $s['areas_created'], $s['duplicates']]);
        $this->assertSame(0, db_connect()->table('countries')->where('code', 'ZZ')->countAllResults());

        $s = $importer->import(['code' => 'ZZ', 'name' => 'Testland', 'city_label' => 'District', 'area_label' => 'Thana'], $rows);
        $this->assertSame([2, 0, 2, 0, 1], [$s['cities_created'], $s['cities_updated'], $s['areas_created'], $s['areas_updated'], $s['duplicates']]);
        $db = db_connect();
        $alpha = $db->table('cities')->where('country_code', 'ZZ')->where('code', 'ZZ-A')->get()->getRowArray();
        $this->assertSame('district', $alpha['kind']);
        $this->assertSame('East', $alpha['region']);
        $north = $db->table('areas')->where('city_id', $alpha['id'])->where('code', 'A1')->get()->getRowArray();
        $this->assertSame('1200', $north['postal_code']);
        $this->assertSame('thana', $north['kind']);

        // an admin disables an area; then the same file is imported again: nothing changes, ids and the disabled state are kept
        $db->table('areas')->where('id', $north['id'])->update(['is_active' => 0]);
        $s = $importer->import(['code' => 'ZZ'], $rows);
        $this->assertSame([0, 0, 0, 0], [$s['cities_created'], $s['cities_updated'], $s['areas_created'], $s['areas_updated']]);
        $this->assertSame(2, $db->table('areas')->where('city_id', $alpha['id'])->countAllResults());
        $this->assertSame(0, (int) $db->table('areas')->where('id', $north['id'])->get()->getRow('is_active'));

        // a renamed area matched by its code keeps its id (orders keep pointing at it)
        $s = $importer->import(['code' => 'ZZ'], [['city' => 'Alpha', 'region' => 'East', 'city_code' => 'ZZ-A', 'city_kind' => 'district', 'area' => 'North Ward 1', 'area_code' => 'A1', 'area_kind' => 'thana', 'postal_code' => '1200']]);
        $this->assertSame(1, $s['areas_updated']);
        $this->assertSame('North Ward 1', $db->table('areas')->where('id', $north['id'])->get()->getRow('name'));

        // the catalogue (API side) only offers enabled entries, and checks relations
        cache()->clean();
        $cat = new LocationCatalog();
        $this->assertSame(['South Ward'], array_column($cat->areas((int) $alpha['id']), 'name'));
        $beta = (int) $db->table('cities')->where('code', 'ZZ-B')->get()->getRow('id');
        $this->assertThrowsOmni(ValidationException::class, static fn () => $cat->resolve('ZZ', $beta, (int) $north['id']));
        @unlink($file);
    }

    public function testInvalidRowsAbortTheWholeImport(): void
    {
        $this->clean();
        $file = $this->csv("city,city_kind,area,area_lat,area_lng\n"
            . "Gamma,district,Ok Ward,,\n"
            . ",district,No City,,\n"
            . "Gamma,province,Bad Kind,,\n"
            . "Gamma,district,Far Away,95,10\n"
            . "Gamma,district,Half Pin,10,\n"
            . "Gamma,district,<script>,,\n");
        [, $rows] = LocationImporter::readFile($file);
        $e = $this->assertThrowsOmni(ValidationException::class, static fn () => (new LocationImporter())->import(['code' => 'ZY', 'name' => 'Otherland'], $rows));
        $d = $e->details();
        foreach (['line 3', 'line 4', 'line 5', 'line 6', 'line 7'] as $line) {
            $this->assertArrayHasKey($line, $d);
        }
        $this->assertArrayNotHasKey('line 2', $d);
        $this->assertSame(0, db_connect()->table('countries')->where('code', 'ZY')->countAllResults(), 'nothing written');

        // unknown columns and a missing city column are refused up front
        $this->assertThrowsOmni(ValidationException::class, fn () => LocationImporter::readFile($this->csv("town,area\nX,Y\n")));
        $this->assertThrowsOmni(ValidationException::class, fn () => LocationImporter::readFile($this->csv("city,colour\nX,red\n")));
        @unlink($file);

        // JSON (nested) works too
        [$meta, $rows] = LocationImporter::parseJson((string) json_encode(['country' => ['name' => 'Otherland', 'area_label' => 'Upazila'],
            'cities' => [['name' => 'Delta', 'kind' => 'district', 'areas' => [['name' => 'One', 'kind' => 'upazila'], ['name' => 'Two', 'kind' => 'upazila']]]]]));
        $s = (new LocationImporter())->import(['code' => 'ZY'] + $meta, $rows);
        $this->assertSame([1, 2], [$s['cities_created'], $s['areas_created']]);
        $this->assertSame('Upazila', db_connect()->table('countries')->where('code', 'ZY')->get()->getRow('area_label'));
    }

    public function testPagesAndEnableDisable(): void
    {
        $this->clean();
        (new LocationImporter())->import(['code' => 'ZZ', 'name' => 'Testland', 'city_label' => 'District'], [['city' => 'Alpha', 'city_kind' => 'district', 'area' => 'North Ward']]);
        $city = (int) db_connect()->table('cities')->where('country_code', 'ZZ')->get()->getRow('id');
        $area = (int) db_connect()->table('areas')->where('city_id', $city)->get()->getRow('id');

        $r = $this->getPage('locations');
        $r->assertOK();
        $r->assertSee('Testland');
        $r->assertSee('Import a location dataset');
        $this->getPage('locations', ['loc_export' => 'csv'])->assertOK();
        $this->getPage('locations/zz')->assertOK();
        $this->getPage('locations/ZZ')->assertSee('Alpha');
        $this->getPage('locations/cities/' . $city)->assertSee('North Ward');
        $t = $this->getPage('locations/template.csv');
        $this->assertSame(implode(',', LocationImporter::COLUMNS), trim((string) $t->response()->getBody()));

        $this->postForm('locations/cities/' . $city . '/toggle')->assertRedirect();
        $this->assertSame(0, (int) db_connect()->table('cities')->where('id', $city)->get()->getRow('is_active'));
        $this->postForm('locations/areas/' . $area . '/toggle')->assertRedirect();
        $this->assertSame(0, (int) db_connect()->table('areas')->where('id', $area)->get()->getRow('is_active'));
        $this->postForm('locations/ZZ/toggle')->assertRedirect();
        $this->assertSame(0, (int) db_connect()->table('countries')->where('code', 'ZZ')->get()->getRow('is_active'));
        $this->seeInDatabase('platform_audit', ['action' => 'Location country disabled']);

        // add + edit a country
        $this->postForm('locations/countries', ['code' => 'zy', 'name' => 'Otherland', 'city_label' => 'Province', 'area_label' => 'Ward'])->assertRedirect();
        $this->seeInDatabase('countries', ['code' => 'ZY', 'name' => 'Otherland', 'city_label' => 'Province']);
        $this->postForm('locations/ZY', ['code' => 'ZY', 'name' => 'Otherland 2'])->assertRedirect();
        $this->seeInDatabase('countries', ['code' => 'ZY', 'name' => 'Otherland 2']);
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\NotFoundException::class, fn () => $this->getPage('locations/QQ'));
    }

    public function testOnlyPlatformSettingsStaffCanManageLocations(): void
    {
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\PermissionDeniedException::class, fn () => $this->as('support')->getPage('locations'));
        $this->assertThrowsOmni(\OmniPOS\Core\Exceptions\PermissionDeniedException::class, fn () => $this->as('support')->postForm('locations/ZZ/toggle'));
    }
}
