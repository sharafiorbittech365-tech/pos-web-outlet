<?php

declare(strict_types=1);

namespace App\Modules\BusinessTypes\Services;

use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\BusinessTypeSetup;

/**
 * Business types and their working method: identity (name, Bangla name, icon, order, offered),
 * storefront method (store kind + online request kind) and the setup new outlets of the type start
 * with (business_types.setup). Existing outlets keep their own settings, except the business words and
 * the offered POS switches, which every outlet reads live (BusinessTypeSetup::terms() / allowedFeatures()).
 * Flushes the catalogue cache.
 */
final class BusinessTypeAdmin
{
    /** @param array{name: string, role: string, id?: int|null} $actor */
    public function __construct(private readonly array $actor)
    {
    }

    /** @return array<string, array{tenants: int, outlets: int}> type => live accounts and outlets */
    public static function usage(): array
    {
        $db = db_connect();
        $out = [];
        foreach ($db->table('tenants')->select('business_type, COUNT(*) AS n')->where('deleted_at', null)->groupBy('business_type')->get()->getResultArray() as $r) {
            $out[$r['business_type']]['tenants'] = (int) $r['n'];
        }
        foreach ($db->table('businesses')->select('business_type, COUNT(*) AS n')->groupBy('business_type')->get()->getResultArray() as $r) {
            $out[$r['business_type']]['outlets'] = (int) $r['n'];
        }

        return array_map(static fn (array $u): array => ['tenants' => $u['tenants'] ?? 0, 'outlets' => $u['outlets'] ?? 0], $out);
    }

    /** @return array<string, mixed> business_types row with setup decoded (stored, else the Bangladesh defaults) */
    public function find(string $code): array
    {
        $t = db_connect()->table('business_types')->where('code', $code)->get()->getRowArray() ?? throw new NotFoundException('Business type not found.');
        $t['setup'] = BusinessTypeSetup::stored($code) ?? BusinessTypeSetup::defaults($code);

        return $t;
    }

    /** @param array<string, mixed> $in validated identity fields + the raw setup form */
    public function save(string $code, array $in): void
    {
        $t = $this->find($code);
        $book = (string) ($in['booking_kind'] ?? '');
        if ($book !== '' && ! isset(BusinessTypeSetup::BOOKING_KINDS[$book])) {
            throw new ValidationException('Check the highlighted fields.', ['booking_kind' => 'Choose an online request type.']);
        }
        $setup = BusinessTypeSetup::fromInput($in, $code);
        db_connect()->table('business_types')->where('code', $code)->update([
            'name' => trim((string) $in['name']), 'name_bn' => trim((string) ($in['name_bn'] ?? '')) ?: null, 'icon' => trim((string) $in['icon']),
            'store_kind' => (string) $in['store_kind'], 'booking_kind' => $book === '' ? null : $book, 'sort_order' => (int) ($in['sort_order'] ?? 0),
            'is_active' => ($in['is_active'] ?? '0') === '1' ? 1 : 0, 'setup' => json_encode($setup, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        service('audit')->platform('Business type saved', $code . ' · ' . trim((string) $in['name']) . ($t['store_kind'] !== $in['store_kind'] ? ' · method ' . $t['store_kind'] . ' → ' . $in['store_kind'] : ''), null, $this->actor);
        service('catalog')->flush();
    }

    /** Put the type's setup back to the Bangladesh defaults. */
    public function reset(string $code): string
    {
        $t = $this->find($code);
        db_connect()->table('business_types')->where('code', $code)->update([
            'setup' => json_encode(BusinessTypeSetup::defaults($code), JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        service('audit')->platform('Business type reset', $code . ' · setup back to defaults', null, $this->actor);
        service('catalog')->flush();

        return (string) $t['name'];
    }
}
