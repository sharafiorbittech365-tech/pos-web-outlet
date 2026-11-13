<?php

declare(strict_types=1);

namespace App\Modules\Floor\Services;

use OmniPOS\Core\Config\OmniPOS;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Tables / rooms of an outlet (Tables › Manage) and their QR codes for Table QR ordering.
 *
 * Orders refer to a table by its name (orders.table_ref), so names are unique per outlet and a
 * table with an open bill cannot be renamed or removed. Each table has an unguessable qr_token;
 * its QR opens <shop address>/order/table-1/<token> on the outlet's storefront, and "New QR code" retires
 * the old one at once (a printed QR that walked off stops working).
 */
final class TableAdmin
{
    /** @var array{base: string, query: string}|false|null siteBase() for this request (false = no storefront) */
    private array|false|null $base = null;

    public function __construct(private readonly int $tenantId, private readonly int $businessId)
    {
    }

    public static function forCurrent(): self
    {
        $s = service('posSession');

        return new self($s->tenantId(), $s->businessId());
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return db_connect()->table('dining_tables')->select('id, name, area, seats, hourly_rate, sort_order, qr_token')
            ->where('business_id', $this->businessId)->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
    }

    /** @param array<string, mixed> $in name, area, seats, hourly_rate */
    public function save(?int $id, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > 40) {
            $errors['name'] = 'Give the table a name (up to 40 characters).';
        }
        $seats = (int) ($in['seats'] ?? 4);
        if ($seats < 1 || $seats > 100) {
            $errors['seats'] = 'Seats: 1 to 100.';
        }
        $rate = trim((string) ($in['hourly_rate'] ?? ''));
        if ($rate !== '' && (! is_numeric($rate) || (float) $rate < 0)) {
            $errors['hourly_rate'] = 'Enter a rate, or leave it empty.';
        }
        if ($errors !== []) {
            throw new ValidationException('Please check the table.', $errors);
        }
        $db = db_connect();
        $dupe = $db->table('dining_tables')->where('business_id', $this->businessId)->where('name', $name);
        if ($id !== null) {
            $dupe->where('id !=', $id);
        }
        if ($dupe->countAllResults() > 0) {
            throw new ValidationException('Another table already has this name.', ['name' => 'Names must be different — orders refer to the table by its name.']);
        }
        $row = ['name' => $name, 'area' => mb_substr(trim((string) ($in['area'] ?? '')), 0, 40) ?: null, 'seats' => $seats, 'hourly_rate' => $rate === '' ? null : round((float) $rate, 2)];
        if ($id === null) {
            $row += ['tenant_id' => $this->tenantId, 'business_id' => $this->businessId, 'qr_token' => bin2hex(random_bytes(24)),
                'sort_order' => (int) $db->table('dining_tables')->selectMax('sort_order', 'm')->where('business_id', $this->businessId)->get()->getRow('m') + 1];
            $db->table('dining_tables')->insert($row);
            $id = (int) $db->insertID();
            service('audit')->activity('Table added', $name);

            return $id;
        }
        $t = $this->find($id);
        if ($t['name'] !== $name && $this->busy($t['name'])) {
            throw new BusinessRuleException($t['name'] . ' has an open bill — settle it before renaming the table.');
        }
        $db->table('dining_tables')->where('id', $id)->where('business_id', $this->businessId)->update($row);
        service('audit')->activity('Table updated', $t['name'] . ($t['name'] !== $name ? ' → ' . $name : ''));

        return $id;
    }

    public function delete(int $id): string
    {
        $t = $this->find($id);
        if ($this->busy($t['name'])) {
            throw new BusinessRuleException($t['name'] . ' has an open bill — settle it before removing the table.');
        }
        db_connect()->table('dining_tables')->where('id', $id)->where('business_id', $this->businessId)->delete();
        service('audit')->activity('Table removed', $t['name']);

        return (string) $t['name'];
    }

    /** A new QR code for the table; the printed one stops working. */
    public function newQr(int $id): string
    {
        $t = $this->find($id);
        db_connect()->table('dining_tables')->where('id', $id)->where('business_id', $this->businessId)->update(['qr_token' => bin2hex(random_bytes(24))]);
        service('audit')->activity('Table QR code replaced', $t['name']);

        return (string) $t['name'];
    }

    /**
     * Where a table's QR leads: the public table-ordering page on this POS address
     * (<pos address>/order/table-5/<secret code>, module TableOrder) — no storefront or website needed.
     */
    public function orderUrl(string $token, string $name = ''): ?string
    {
        $base = $this->siteBase();
        // the readable part ("table-5") is for people, the secret code is what counts
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'table';

        return $base === null ? null : $base['base'] . 'order/' . substr($label, 0, 40) . '/' . $token . $base['query'];
    }

    /** @return array{base: string, query: string}|null the address the QR codes lead to (always this POS server) */
    public function siteBase(): ?array
    {
        return ['base' => rtrim(site_url('/'), '/') . '/', 'query' => ''];
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        return db_connect()->table('dining_tables')->where('id', $id)->where('business_id', $this->businessId)->get()->getRowArray()
            ?? throw new NotFoundException('Table not found.');
    }

    private function busy(string $name): bool
    {
        return db_connect()->table('orders')->where('business_id', $this->businessId)->where('table_ref', $name)->where('status', 'open')->countAllResults() > 0
            || db_connect()->table('held_carts')->where('business_id', $this->businessId)->where('table_ref', $name)->countAllResults() > 0;
    }
}
