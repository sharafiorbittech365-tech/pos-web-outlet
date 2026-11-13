<?php

declare(strict_types=1);

namespace App\Modules\Buyers\Services;

use App\Modules\Shops\Services\ListedShops;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * "My orders" and "My bookings" across shops.
 * Orders: orders.buyer_id (idx_ord_buyer); bookings: bookings.buyer_id (idx_bk_buyer).
 */
final class BuyerHistory
{
    public const PER_PAGE = 20;

    public function __construct(private readonly ListedShops $shops)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, page: int, last_page: int} */
    public function orders(int $buyerId, int $page = 1): array
    {
        $db = db_connect();
        $total = $db->table('orders')->where('buyer_id', $buyerId)->countAllResults();
        $last = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($page, $last));
        $rows = $db->table('orders')
            ->select('id, business_id, doc_no, web_ref, status, ship_status, total, due_total, item_count, ship_address, created_at')
            ->where('buyer_id', $buyerId)->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->get()->getResultArray();

        return ['rows' => $this->withShops($rows), 'total' => $total, 'page' => $page, 'last_page' => $last];
    }

    /** @return array<string, mixed> one of the buyer's orders with its lines */
    public function order(int $buyerId, int $orderId): array
    {
        $db = db_connect();
        $o = $db->table('orders')
            ->select('id, business_id, doc_no, web_ref, status, ship_status, subtotal, discount_total, tax_total, tax_added, shipping, round_off, total, paid_total, due_total, item_count, customer_name, customer_phone, ship_address, note, created_at')
            ->where('id', $orderId)->where('buyer_id', $buyerId)->get()->getRowArray()
            ?? throw new NotFoundException('Order not found.');
        $o['items'] = $db->table('order_items')->select('name, variant_name, serial, qty, unit_price, line_total, tax_mode, tax_rate')
            ->where('order_id', $orderId)->orderBy('id')->get()->getResultArray();

        return $this->withShops([$o])[0];
    }

    /** @return list<array<string, mixed>> */
    public function bookings(int $buyerId, int $limit = 50): array
    {
        if ($buyerId <= 0) {
            return [];
        }
        $rows = db_connect()->table('bookings b')
            ->select('b.id, b.business_id, b.reference, b.kind, b.status, b.starts_at, b.ends_at, b.staff_name, b.note, b.created_at, p.name AS service')
            ->join('products p', 'p.id = b.product_id', 'left')
            ->where('b.buyer_id', $buyerId)
            ->orderBy('b.created_at', 'DESC')->limit($limit)->get()->getResultArray();

        return $this->withShops($rows);
    }

    /** Batch shop names: from the listed set, then one query for shops no longer listed. */
    private function withShops(array $rows): array
    {
        $listed = $this->shops->all();
        $missing = array_values(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['business_id'], $rows), static fn (int $id): bool => ! isset($listed[$id]))));
        $other = $missing !== [] ? array_column(db_connect()->table('businesses')->select('id, name, slug, phone, currency_symbol, currency_decimals')->whereIn('id', $missing)->get()->getResultArray(), null, 'id') : [];
        foreach ($rows as &$r) {
            $bid = (int) $r['business_id'];
            if (isset($listed[$bid])) {
                $s = $listed[$bid];
                $r['shop'] = ['name' => $s['name'], 'slug' => $s['site_slug'], 'phone' => $s['phone'], 'listed' => true, 'currency' => $s['currency']];
            } else {
                $s = $other[$bid] ?? ['name' => 'Shop', 'slug' => '', 'phone' => null, 'currency_symbol' => '৳', 'currency_decimals' => 2];
                $r['shop'] = ['name' => $s['name'], 'slug' => $s['slug'], 'phone' => $s['phone'], 'listed' => false, 'currency' => ['symbol' => $s['currency_symbol'], 'decimals' => (int) $s['currency_decimals']]];
            }
        }
        unset($r);

        return $rows;
    }
}
