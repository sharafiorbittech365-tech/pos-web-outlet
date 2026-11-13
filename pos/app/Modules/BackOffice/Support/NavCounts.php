<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Support;

/**
 * Unread badges beside the menu items (sidebar, phone tabs, "More"), for the signed-in outlet:
 *
 *   orders    online orders (web, marketplace, API) nobody at the outlet has opened yet — sales made
 *             at the POS till never count; opening the order (Orders › order) clears it
 *   floor     the same for online orders placed at a table (table QR), shown on the Tables item
 *   bookings  online bookings still waiting to be confirmed (pending); confirming / cancelling clears
 *   kitchen   kitchen tickets on the board (last 24 hours) not started yet (new); "Start cooking" clears
 *   pharmacy  expired batches still in stock (Pharmacy module); writing them off clears it
 *
 * Only items the user can open are counted (pos_nav_items: permission, module, feature), so a count
 * never reveals a module the user has no access to. Served by GET nav/counts (polled by
 * assets/pos/badges.js) and rendered into the page on load.
 */
final class NavCounts
{
    /** @return array<string, int> nav path => count (0 included, so badges also clear) */
    public static function forCurrentUser(): array
    {
        helper('pos');
        $paths = array_column(pos_nav_items(), 'path');
        $bid = service('posSession')->businessId();
        $db = db_connect();
        $out = [];
        if (in_array('orders', $paths, true) || in_array('floor', $paths, true)) {
            $row = $db->table('orders')
                ->select('COALESCE(SUM(table_ref IS NULL OR table_ref = \'\'), 0) AS orders, COALESCE(SUM(table_ref IS NOT NULL AND table_ref <> \'\'), 0) AS floor', false)
                ->where('business_id', $bid)->where('acknowledged_at', null)->where('channel !=', 'pos')
                ->whereNotIn('status', ['held', 'cancelled'])->get()->getRowArray();
            foreach (['orders', 'floor'] as $k) {
                if (in_array($k, $paths, true)) {
                    $out[$k] = (int) ($row[$k] ?? 0);
                }
            }
        }
        if (in_array('bookings', $paths, true)) {
            $out['bookings'] = $db->table('bookings')->where('business_id', $bid)->where('status', 'pending')->where('source !=', 'pos')->countAllResults();
        }
        if (in_array('kitchen', $paths, true)) {
            // the same window as the kitchen board (KitchenService::tickets: the last 24 hours)
            $out['kitchen'] = $db->table('orders')->where('business_id', $bid)->where('kitchen_status', 'new')->whereNotIn('status', ['held', 'cancelled'])
                ->where('created_at >=', date('Y-m-d H:i:s', time() - 86400))->countAllResults();
        }
        if (in_array('pharmacy', $paths, true)) {
            // expired batches still on file (Pharmacy › Expired; writing them off clears it)
            $out['pharmacy'] = $db->table('product_batches')->where('business_id', $bid)->where('qty >', 0)->where('expires_on <', date('Y-m-d'))->countAllResults();
        }

        return $out;
    }

    /** Opening an online order clears its badge (POS sales are never "new"). */
    public static function acknowledge(array $order): void
    {
        if ($order['channel'] === 'pos' || ! empty($order['acknowledged_at'])) {
            return;
        }
        $s = service('posSession');
        db_connect()->table('orders')->where('id', (int) $order['id'])->where('business_id', $s->businessId())->where('acknowledged_at', null)
            ->update(['acknowledged_at' => date('Y-m-d H:i:s'), 'acknowledged_by' => $s->userId() ?: null]);
    }
}
