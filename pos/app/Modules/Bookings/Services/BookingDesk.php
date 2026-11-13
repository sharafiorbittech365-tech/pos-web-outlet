<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Services;

use CodeIgniter\Database\BaseBuilder;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Booking desk of the current outlet: list & counts, counter bookings and status changes.
 * Slots, clash checks and the booking row itself come from core BookingService.
 */
final class BookingDesk
{
    /** kind => [label, icon] */
    public const KINDS = ['appointment' => ['Appointment', 'scissors'], 'room' => ['Room booking', 'mic'], 'table' => ['Table reservation', 'utensils'], 'pickup' => ['Pickup', 'truck'],
        'quote' => ['Quote request', 'file-text'], 'visit' => ['Showroom visit', 'store'], 'repair' => ['Repair request', 'wrench']];

    public function __construct(private readonly array $business)
    {
    }

    public static function make(): self
    {
        return new self(service('posSession')->business());
    }

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    public function kind(): string
    {
        $k = (string) ($this->business['booking_kind'] ?? '');

        return array_key_exists($k, self::KINDS) ? $k : 'appointment';
    }

    public function assertEnabled(): void
    {
        if (empty($this->business['booking_kind'])) {
            throw new BusinessRuleException('Bookings are not used by this business type.');
        }
    }

    public function listQuery(string $filter): BaseBuilder
    {
        $t0 = date('Y-m-d 00:00:00');
        $t1 = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $b = db_connect()->table('bookings bk')
            ->select('bk.id, bk.reference, bk.kind, bk.status, bk.source, bk.customer_name, bk.customer_phone, bk.staff_name, bk.starts_at, bk.ends_at, bk.party_size, bk.details, bk.note, bk.order_id, bk.created_at, p.name AS service')
            ->join('products p', 'p.id = bk.product_id', 'left')
            ->where('bk.business_id', $this->bid());
        match ($filter) {
            'today' => $b->where('bk.starts_at >=', $t0)->where('bk.starts_at <', $t1),
            'pending' => $b->where('bk.status', 'pending'),
            'all' => $b,
            default => $b->groupStart()->where('bk.starts_at >=', $t0)->orWhere('bk.starts_at', null)->groupEnd()->whereIn('bk.status', ['pending', 'confirmed']),
        };

        return $b;
    }

    /** @return array{today: int, pending: int, upcoming: int, web7: int} */
    public function counts(): array
    {
        $r = db_connect()->query("SELECT SUM(starts_at >= ? AND starts_at < ? AND status <> 'cancelled') AS today, SUM(status = 'pending') AS pending,
                SUM(starts_at >= ? AND status = 'confirmed') AS upcoming, SUM(source IN ('web','marketplace','api') AND created_at >= ?) AS web7
                FROM bookings WHERE business_id = ?", [date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day')), date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('-6 days')), $this->bid()])->getRowArray() ?? [];

        return ['today' => (int) ($r['today'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0), 'upcoming' => (int) ($r['upcoming'] ?? 0), 'web7' => (int) ($r['web7'] ?? 0)];
    }

    /** @return list<array{id: int, name: string, duration: ?int}> services offered (products with a duration, or not stocked) */
    public function services(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => $r['name'], 'duration' => $r['duration_min'] !== null ? (int) $r['duration_min'] : null],
            db_connect()->table('products')->select('id, name, duration_min')->where('business_id', $this->bid())->where('deleted_at', null)->where('is_active', 1)
                ->groupStart()->where('duration_min IS NOT NULL', null, false)->orWhere('stock_type', 'none')->groupEnd()->orderBy('name')->limit(300)->get()->getResultArray());
    }

    /** @return list<string> */
    public function staff(): array
    {
        $s = (array) ($this->business['settings'] ?? []);

        return array_values(array_unique(array_filter(array_map(static fn ($x): string => is_array($x) ? (string) ($x['name'] ?? '') : (string) $x, array_merge((array) ($s['serviceStaff'] ?? []), (array) ($s['staff'] ?? []))))));
    }

    /** @param array<string, mixed> $in */
    public function create(array $in): array
    {
        $this->assertEnabled();
        $date = (string) ($in['date'] ?? '');
        $time = (string) ($in['time'] ?? '');
        $startsAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date . ' ' . (preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '10:00') . ':00' : '';
        $details = array_filter(['address' => trim((string) ($in['address'] ?? '')), 'brief' => trim((string) ($in['details'] ?? ''))]);
        $row = service('bookings')->create([
            'kind' => $this->kind(), 'source' => 'pos', 'customer_name' => (string) ($in['customer_name'] ?? ''), 'customer_phone' => (string) ($in['customer_phone'] ?? ''),
            'customer_email' => (string) ($in['customer_email'] ?? ''), 'product_id' => (int) ($in['product_id'] ?? 0) ?: null, 'staff_name' => (string) ($in['staff_name'] ?? ''),
            'starts_at' => $startsAt, 'party_size' => isset($in['party_size']) && $in['party_size'] !== '' ? (int) $in['party_size'] : null,
            'details' => $details !== [] ? $details : null, 'note' => trim((string) ($in['note'] ?? '')) ?: null,
        ]);
        // taken at the counter → confirmed straight away
        service('bookings')->setStatus((int) $row['id'], 'confirmed');
        service('audit')->activity('Booking added', self::KINDS[$this->kind()][0] . ' · ' . ($startsAt ?: 'no time') . ' · ' . $row['customer_name']);

        return $row;
    }

    public function setStatus(int $id, string $status): void
    {
        $bk = db_connect()->table('bookings')->select('id, reference, customer_name, status')->where('id', $id)->where('business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Booking not found.');
        if (in_array($bk['status'], ['done', 'cancelled'], true) && $status !== 'pending') {
            throw new BusinessRuleException('This booking is already ' . $bk['status'] . '.');
        }
        service('bookings')->setStatus($id, $status);
        service('audit')->activity('Booking ' . $status, $bk['reference'] . ' · ' . $bk['customer_name']);
    }
}
