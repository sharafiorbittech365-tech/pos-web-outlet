<?php

declare(strict_types=1);

namespace App\Modules\Pharmacy\Services;

use CodeIgniter\Database\BaseBuilder;

/**
 * Read-only access to the shared medicine master database (table medicines, platform-wide
 * reference data imported by Super Admin). Outlets never write to it: "Add from medicine
 * database" creates the outlet's own product (price, stock, batches) linked by products.medicine_id.
 */
final class MedicineCatalog
{
    public const COLS = 'id, brand_id, brand_name, type, slug, dosage_form, generic, strength, manufacturer, package_container, package_size';

    /** @return array<string, mixed>|null active medicine */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return db_connect()->table('medicines')->select(self::COLS)->where('id', $id)->where('is_active', 1)->get()->getRowArray();
    }

    /** Product name for a medicine: brand + strength + dosage form ("Brand 500 mg Tablet"). */
    public static function productName(array $m): string
    {
        $name = trim(implode(' ', array_filter([(string) $m['brand_name'], (string) ($m['strength'] ?? ''), (string) ($m['dosage_form'] ?? '')], static fn ($v) => $v !== '')));

        return mb_substr($name, 0, 200);
    }

    public static function label(array $m): string
    {
        return implode(' · ', array_filter([trim($m['brand_name'] . ' ' . ($m['strength'] ?? '')), (string) ($m['dosage_form'] ?? ''), (string) ($m['generic'] ?? ''), (string) ($m['manufacturer'] ?? '')], static fn ($v) => $v !== ''));
    }

    /**
     * Search by brand or generic name (prefix match on the indexed columns), with whether the
     * current outlet already has a product linked to each result.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $q, int $businessId, int $limit = 20): array
    {
        $q = trim(mb_substr($q, 0, 80));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $db = db_connect();
        $rows = $db->table('medicines')->select(self::COLS)->where('is_active', 1)
            ->groupStart()->like('brand_name', $q, 'after')->orLike('generic', $q, 'after')->orWhere('brand_id', $q)->groupEnd()
            ->orderBy('brand_name')->orderBy('strength')->limit(max(1, min(50, $limit)))->get()->getResultArray();
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $linked = $ids === [] ? [] : array_flip(array_map('intval', array_column($db->table('products')->select('medicine_id')->where('business_id', $businessId)
            ->where('deleted_at', null)->whereIn('medicine_id', $ids)->get()->getResultArray(), 'medicine_id')));

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'brand_id' => (string) $r['brand_id'], 'brand_name' => (string) $r['brand_name'], 'type' => $r['type'], 'dosage_form' => $r['dosage_form'],
            'generic' => $r['generic'], 'strength' => $r['strength'], 'manufacturer' => $r['manufacturer'], 'package_container' => $r['package_container'], 'package_size' => $r['package_size'],
            'name' => self::productName($r), 'label' => self::label($r), 'linked' => isset($linked[(int) $r['id']]),
        ], $rows);
    }

    /** Browse list (Pharmacy › Medicine database). */
    public static function browseQuery(): BaseBuilder
    {
        return db_connect()->table('medicines m')->select('m.id, m.brand_id, m.brand_name, m.type, m.dosage_form, m.generic, m.strength, m.manufacturer, m.package_container, m.package_size')
            ->where('m.is_active', 1);
    }

    public static function count(): int
    {
        return db_connect()->table('medicines')->where('is_active', 1)->countAllResults();
    }
}
