<?php

declare(strict_types=1);

namespace App\Modules\Locations\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\LocationCatalog;
use OmniPOS\Core\Services\LocationImporter;

/**
 * Super Admin > Locations: the platform's address catalogue (countries -> cities/districts ->
 * areas/thanas). Countries are added here or by an import; datasets come from a CSV / JSON upload
 * (LocationImporter, the same code as `php spark location:import`). Entries are never deleted from
 * here (orders point at them): they are enabled or disabled.
 */
final class LocationAdmin
{
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    public static function countriesQuery(): BaseBuilder
    {
        $db = db_connect();
        $cities = $db->table('cities')->select('country_code, COUNT(*) AS n, SUM(is_active) AS on_n')->groupBy('country_code')->getCompiledSelect();
        $areas = $db->table('areas a')->select('c.country_code, COUNT(*) AS n')->join('cities c', 'c.id = a.city_id')->groupBy('c.country_code')->getCompiledSelect();

        return $db->table('countries k')
            ->select('k.code, k.name, k.name_bn, k.phone_code, k.city_label, k.area_label, k.is_active, k.updated_at, COALESCE(ci.n, 0) AS cities, COALESCE(ci.on_n, 0) AS cities_on, COALESCE(ar.n, 0) AS areas')
            ->join('(' . $cities . ') ci', 'ci.country_code = k.code', 'left', false)
            ->join('(' . $areas . ') ar', 'ar.country_code = k.code', 'left', false);
    }

    public static function citiesQuery(string $country): BaseBuilder
    {
        $db = db_connect();
        $areas = $db->table('areas')->select('city_id, COUNT(*) AS n, SUM(is_active) AS on_n')->groupBy('city_id')->getCompiledSelect();

        return $db->table('cities c')
            ->select('c.id, c.name, c.name_bn, c.kind, c.region, c.code, c.lat, c.lng, c.is_active, COALESCE(ar.n, 0) AS areas, COALESCE(ar.on_n, 0) AS areas_on')
            ->join('(' . $areas . ') ar', 'ar.city_id = c.id', 'left', false)
            ->where('c.country_code', $country);
    }

    public static function areasQuery(int $cityId): BaseBuilder
    {
        return db_connect()->table('areas a')->select('a.id, a.name, a.name_bn, a.kind, a.code, a.postal_code, a.lat, a.lng, a.is_active')->where('a.city_id', $cityId);
    }

    /** @return array<string, mixed> */
    public static function country(string $code): array
    {
        $code = LocationCatalog::countryCode($code) ?? throw new NotFoundException('Country not found.');

        return db_connect()->table('countries')->where('code', $code)->get()->getRowArray() ?? throw new NotFoundException('Country not found.');
    }

    /** @return array<string, mixed> city row + country name */
    public static function city(int $id): array
    {
        return db_connect()->table('cities c')->select('c.*, k.name AS country_name, k.area_label')->join('countries k', 'k.code = c.country_code')
            ->where('c.id', $id)->get()->getRowArray() ?? throw new NotFoundException('City not found.');
    }

    /**
     * Add or edit a country (code, name, name_bn, phone_code, city_label, area_label).
     *
     * @param array<string, string> $in validated input
     */
    public function saveCountry(array $in, bool $isNew): string
    {
        $code = LocationCatalog::countryCode($in['code'] ?? '') ?? throw new ValidationException('Invalid country code.', ['code' => 'Use the 2-letter ISO code, e.g. BD.']);
        $db = db_connect();
        $exists = $db->table('countries')->where('code', $code)->countAllResults() > 0;
        if ($isNew && $exists) {
            throw new ValidationException('This country already exists.', ['code' => $code . ' is already in the list.']);
        }
        if (! $isNew && ! $exists) {
            throw new NotFoundException('Country not found.');
        }
        if (($in['phone_code'] ?? '') !== '' && preg_match('/^\+?[0-9]{1,6}$/', $in['phone_code']) !== 1) {
            throw new ValidationException('Invalid dialling code.', ['phone_code' => 'Use digits, e.g. +880.']);
        }
        $row = ['name' => trim($in['name']), 'updated_at' => date('Y-m-d H:i:s')];
        foreach (['name_bn', 'phone_code', 'city_label', 'area_label'] as $k) {
            $row[$k] = trim((string) ($in[$k] ?? '')) ?: null;
        }
        if ($isNew) {
            $db->table('countries')->insert($row + ['code' => $code, 'is_active' => 1]);
            service('audit')->platform('Location country added', $code . ' · ' . $row['name'], null, $this->actor);
        } else {
            $db->table('countries')->where('code', $code)->update($row);
            service('audit')->platform('Location country changed', $code . ' · ' . $row['name'], null, $this->actor);
        }
        LocationCatalog::forget($code);

        return $code;
    }

    /** Enable / disable a country, city or area; returns the new state. */
    public function toggle(string $level, string|int $id): bool
    {
        $db = db_connect();
        [$table, $key, $label] = match ($level) {
            'country' => ['countries', 'code', 'country'],
            'city' => ['cities', 'id', 'city'],
            'area' => ['areas', 'id', 'area'],
            default => throw new NotFoundException('Unknown level.'),
        };
        $row = $db->table($table)->select($key . ', name, is_active' . ($level === 'city' ? ', country_code' : '') . ($level === 'area' ? ', city_id' : ''))->where($key, $id)->get()->getRowArray()
            ?? throw new NotFoundException('Not found.');
        $on = ! (int) $row['is_active'];
        $db->table($table)->where($key, $id)->update(['is_active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform('Location ' . $label . ($on ? ' enabled' : ' disabled'), $row['name'] . ' (' . $row[$key] . ')', null, $this->actor);
        match ($level) {
            'country' => LocationCatalog::forget((string) $row['code']),
            'city' => LocationCatalog::forget((string) $row['country_code'], [(int) $row['id']]),
            'area' => LocationCatalog::forget(null, [(int) $row['city_id']]),
        };

        return $on;
    }

    /**
     * Import an uploaded CSV / JSON dataset for a country.
     *
     * @param array<string, string> $country code + optional name / labels
     * @return array<string, mixed> LocationImporter::import() stats
     */
    public function import(array $country, ?UploadedFile $file, bool $dryRun): array
    {
        if ($file === null || ! $file->isValid()) {
            throw new ValidationException('Choose a file to upload.', ['file' => $file?->getErrorString() ?? 'No file was uploaded.']);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException('The file is too large.', ['file' => 'At most 20 MB.']);
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['csv', 'json', 'txt'], true)) {
            throw new ValidationException('Unsupported file type.', ['file' => 'Use a .csv or .json file.']);
        }
        [$meta, $rows] = LocationImporter::readFile($file->getTempName(), $ext === 'json' ? 'json' : 'csv');
        $in = array_filter($country, static fn ($v): bool => is_string($v) && trim($v) !== '');
        $stats = (new LocationImporter())->import($in + $meta, $rows, $dryRun);
        if (! $dryRun) {
            service('audit')->platform('Location dataset imported', sprintf('%s · %s · cities +%d ~%d · areas +%d ~%d · %d duplicate(s)',
                $stats['country'], mb_substr($file->getClientName(), 0, 80), $stats['cities_created'], $stats['cities_updated'], $stats['areas_created'], $stats['areas_updated'], $stats['duplicates']), null, $this->actor);
        }

        return $stats;
    }
}
