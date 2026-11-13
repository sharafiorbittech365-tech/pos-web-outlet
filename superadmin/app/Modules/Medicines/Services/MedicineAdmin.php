<?php

declare(strict_types=1);

namespace App\Modules\Medicines\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\Files\UploadedFile;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\MedicineImporter;

/**
 * Super Admin side of the shared medicine database: list, stats, enable / disable a row, and the
 * upload + (dry-run) import through core MedicineImporter (same rules as php spark medicines:import).
 */
final class MedicineAdmin
{
    public const MAX_UPLOAD_BYTES = 30 * 1024 * 1024;

    /** @param array<string, mixed> $actor */
    public function __construct(private readonly array $actor = [])
    {
    }

    public static function listQuery(): BaseBuilder
    {
        return db_connect()->table('medicines m')
            ->select('m.id, m.brand_id, m.brand_name, m.type, m.slug, m.dosage_form, m.generic, m.strength, m.manufacturer, m.package_container, m.package_size, m.is_active, m.updated_at,
                      (SELECT COUNT(*) FROM products p WHERE p.medicine_id = m.id AND p.deleted_at IS NULL) AS products');
    }

    /** @return array{total: int, active: int, generics: int, manufacturers: int, linked: int, updated: ?string} */
    public static function stats(): array
    {
        $db = db_connect();
        $r = $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(is_active), 0) AS on_n, COUNT(DISTINCT generic) AS g, COUNT(DISTINCT manufacturer) AS m, MAX(updated_at) AS u FROM medicines')->getRowArray() ?? [];

        return ['total' => (int) ($r['n'] ?? 0), 'active' => (int) ($r['on_n'] ?? 0), 'generics' => (int) ($r['g'] ?? 0), 'manufacturers' => (int) ($r['m'] ?? 0),
            'linked' => $db->table('products')->where('medicine_id IS NOT NULL', null, false)->where('deleted_at', null)->countAllResults(), 'updated' => $r['u'] ?? null];
    }

    public function toggle(int $id): bool
    {
        $db = db_connect();
        $m = $db->table('medicines')->select('id, brand_id, brand_name, is_active')->where('id', $id)->get()->getRowArray() ?? throw new NotFoundException('Medicine not found.');
        $on = ! (bool) $m['is_active'];
        $db->table('medicines')->where('id', $id)->update(['is_active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->platform($on ? 'Medicine enabled' : 'Medicine disabled', $m['brand_name'] . ' · brand_id ' . $m['brand_id'], null, $this->actor);

        return $on;
    }

    /** @return array<string, mixed> importer report */
    public function import(?UploadedFile $file, bool $dryRun): array
    {
        if ($file === null || ! $file->isValid()) {
            throw new ValidationException('Choose a file to upload.', ['file' => $file?->getErrorString() ?? 'No file was uploaded.']);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException('The file is too large.', ['file' => 'At most 30 MB — use php spark medicines:import for larger files.']);
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, ['csv', 'json', 'txt'], true)) {
            throw new ValidationException('Unsupported file type.', ['file' => 'Use a .csv or .json file.']);
        }
        $r = (new MedicineImporter())->importFile($file->getTempName(), $dryRun, $ext === 'json' ? 'json' : 'csv');
        $r['file'] = mb_substr($file->getClientName(), 0, 120);
        if (! $dryRun) {
            service('audit')->platform('Medicine database imported', sprintf('%s · %d row(s): +%d new, %d updated, %d unchanged, %d error(s)',
                $r['file'], $r['rows'], $r['inserted'], $r['updated'], $r['unchanged'], $r['invalid']), null, $this->actor);
        }

        return $r;
    }
}
