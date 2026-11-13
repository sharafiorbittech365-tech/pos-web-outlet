<?php

declare(strict_types=1);

namespace App\Modules\Floor\Services;

/**
 * Tables / rooms / chairs of the outlet with what is on them right now:
 *   busy  an open order (sent to the kitchen / table, not paid) or a kitchen ticket still cooking
 *   held  a suspended cart parked on that table (e.g. a karaoke room with its check-in time)
 *   free  nothing — tapping it starts a sale on the table in the till
 */
final class FloorService
{
    public function __construct(private readonly int $businessId)
    {
    }

    /** @return list<array<string, mixed>> */
    public function tables(): array
    {
        $db = db_connect();
        $tables = $db->table('dining_tables')->select('id, name, area, seats, hourly_rate')->where('business_id', $this->businessId)
            ->orderBy('sort_order')->orderBy('id')->get()->getResultArray();
        if ($tables === []) {
            return [];
        }
        $names = array_column($tables, 'name');
        $orders = [];
        foreach ($db->table('orders')->select('id, doc_no, table_ref, customer_name, total, due_total, status, kitchen_status, created_at')
            ->where('business_id', $this->businessId)->whereIn('table_ref', $names)
            ->groupStart()->where('status', 'open')
                ->orGroupStart()->whereIn('kitchen_status', ['new', 'cooking', 'ready'])->where('created_at >=', date('Y-m-d H:i:s', time() - 12 * 3600))->groupEnd()
            ->groupEnd()->whereNotIn('status', ['cancelled', 'held'])->orderBy('created_at')->get()->getResultArray() as $o) {
            $orders[$o['table_ref']] ??= $o;
        }
        $held = [];
        foreach ($db->table('held_carts')->select('id, table_ref, customer_name, total, cart, created_at')->where('business_id', $this->businessId)
            ->whereIn('table_ref', $names)->orderBy('created_at')->get()->getResultArray() as $h) {
            $cart = json_decode((string) $h['cart'], true) ?: [];
            $h['checkin'] = (int) ($cart['checkin'] ?? 0);
            unset($h['cart']);
            $held[$h['table_ref']] ??= $h;
        }
        foreach ($tables as &$t) {
            $t['order'] = $orders[$t['name']] ?? null;
            $t['held'] = $t['order'] === null ? ($held[$t['name']] ?? null) : null;
            $t['state'] = $t['order'] !== null ? 'busy' : ($t['held'] !== null ? 'held' : 'free');
        }

        return $tables;
    }
}
