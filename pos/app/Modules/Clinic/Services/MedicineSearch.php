<?php

declare(strict_types=1);

namespace App\Modules\Clinic\Services;

/**
 * Medicine look-up for the prescription pad, from the platform "medicines" table (filled by the
 * medicine importer — OmniPOS ships no medicine data). When the table is not there yet, or holds
 * nothing, search simply returns no matches and the clinician types the medicine.
 *
 * Only id, brand_name, generic, strength, dosage_form and manufacturer are read. Rows that belong
 * to another tenant (a tenant_id column, when the table has one) are never shown.
 */
final class MedicineSearch
{
    private const COLS = ['id', 'brand_name', 'generic', 'strength', 'dosage_form', 'manufacturer'];

    private static ?array $fields = null;

    /** @return list<string> the medicines table's columns ([] when there is no table) */
    private static function fields(): array
    {
        if (self::$fields === null) {
            $db = db_connect();
            try {
                self::$fields = $db->tableExists('medicines', false) ? $db->getFieldNames('medicines') : [];
            } catch (\Throwable) {
                self::$fields = [];
            }
        }

        return self::$fields;
    }

    public static function available(): bool
    {
        return array_diff(['id', 'brand_name'], self::fields()) === [];
    }

    private static function builder(): \CodeIgniter\Database\BaseBuilder
    {
        $f = self::fields();
        $b = db_connect()->table('medicines')->select(implode(', ', array_intersect(self::COLS, $f)));
        if (in_array('tenant_id', $f, true)) {
            $b->groupStart()->where('tenant_id', null)->orWhere('tenant_id', service('posSession')->tenantId())->groupEnd();
        }
        if (in_array('deleted_at', $f, true)) {
            $b->where('deleted_at', null);
        }
        if (in_array('is_active', $f, true)) {
            $b->where('is_active', 1);
        }

        return $b;
    }

    /**
     * @return list<array{id: int, brand_name: string, generic: string, strength: string, dosage_form: string, manufacturer: string}>
     */
    public static function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || ! self::available()) {
            return [];
        }
        try {
            $b = self::builder()->groupStart()->like('brand_name', $q, 'after');
            if (in_array('generic', self::fields(), true)) {
                $b->orLike('generic', $q, 'after');
            }
            $rows = $b->groupEnd()->orderBy('brand_name')->limit(max(1, min(50, $limit)))->get()->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'Medicine search unavailable: ' . $e->getMessage());

            return [];
        }

        return array_map([self::class, 'shape'], $rows);
    }

    /** One medicine by id (for the text snapshot on a prescription line), or null. */
    public static function find(int $id): ?array
    {
        if ($id < 1 || ! self::available()) {
            return null;
        }
        try {
            $r = self::builder()->where('id', $id)->get()->getRowArray();
        } catch (\Throwable) {
            return null;
        }

        return $r !== null ? self::shape($r) : null;
    }

    private static function shape(array $r): array
    {
        return ['id' => (int) $r['id'], 'brand_name' => (string) ($r['brand_name'] ?? ''), 'generic' => (string) ($r['generic'] ?? ''), 'strength' => (string) ($r['strength'] ?? ''),
            'dosage_form' => (string) ($r['dosage_form'] ?? ''), 'manufacturer' => (string) ($r['manufacturer'] ?? '')];
    }

    /** For tests: forget the cached column list. */
    public static function reset(): void
    {
        self::$fields = null;
    }
}
