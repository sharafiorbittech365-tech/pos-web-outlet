<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use CodeIgniter\Database\BaseBuilder;

/**
 * Read queries for Orders & invoices (current outlet; every filter is on an indexed column:
 * business_id + status / channel / created_at).
 */
final class OrderQueries
{
    public const STATUSES = ['open' => 'Open', 'paid' => 'Paid', 'partial' => 'Partially paid', 'due' => 'Due', 'returned' => 'Refunded', 'cancelled' => 'Void'];
    public const CHANNELS = ['pos' => 'POS', 'web' => 'Website', 'table' => 'Table QR', 'marketplace' => 'Marketplace', 'api' => 'API', 'invoice' => 'Invoice'];

    /**
     * Where an order came from, for people: a guest's order from the QR on a table is saved as a web order on that
     * table (channel "web" + table_ref), shown as "Table QR" — not as the website.
     */
    public static function source(array $o): string
    {
        return ($o['channel'] ?? '') === 'web' && ! empty($o['table_ref']) ? 'table' : (string) ($o['channel'] ?? 'pos');
    }

    public static function sourceLabel(array $o): string
    {
        $s = self::source($o);

        return $s === 'table' ? 'Table QR · ' . $o['table_ref'] : (self::CHANNELS[$s] ?? $s);
    }

    public function __construct(private readonly int $businessId, private readonly ?int $onlyUserId = null)
    {
    }

    /** @param array{status?: string, channel?: string, from?: string, to?: string} $f */
    public function listQuery(array $f): BaseBuilder
    {
        $b = db_connect()->table('orders o')
            ->select('o.id, o.doc_no, o.number, o.channel, o.order_type, o.table_ref, o.customer_name, o.customer_phone, o.status, o.kitchen_status, o.item_count, o.total, o.paid_total, o.due_total, o.returned_total, o.user_name, o.web_ref, o.ship_status, o.created_at, inv.id AS invoice_id, inv.doc_no AS invoice_no,
                (SELECT GROUP_CONCAT(DISTINCT p.method) FROM order_payments p WHERE p.order_id = o.id) AS methods', false)
            ->join('invoices inv', 'inv.order_id = o.id AND inv.business_id = o.business_id', 'left')
            ->where('o.business_id', $this->businessId)->where('o.status !=', 'held');
        $this->filters($b, $f);

        return $b;
    }

    private function filters(BaseBuilder $b, array $f): void
    {
        if ($this->onlyUserId !== null) {
            $b->where('o.user_id', $this->onlyUserId);
        }
        if (! empty($f['status']) && isset(self::STATUSES[$f['status']])) {
            $b->where('o.status', $f['status']);
        }
        $ch = (string) ($f['channel'] ?? '');
        if ($ch === 'table') {
            $b->where('o.channel', 'web')->where('o.table_ref IS NOT NULL', null, false);   // the QR on a table
        } elseif ($ch === 'web') {
            $b->where('o.channel', 'web')->where('o.table_ref', null);                      // the website itself
        } elseif (isset(self::CHANNELS[$ch])) {
            $b->where('o.channel', $ch);
        }
        if (! empty($f['table'])) {
            $b->where('o.channel', 'web')->where('o.table_ref', mb_substr((string) $f['table'], 0, 60));
        }
        if (! empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $b->where('o.created_at >=', $f['from'] . ' 00:00:00');
        }
        if (! empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $b->where('o.created_at <=', $f['to'] . ' 23:59:59');
        }
    }

    /** @return list<string> the tables guests have ordered from by QR (for the filter), in table order */
    public function tables(): array
    {
        $rows = db_connect()->table('orders')->select('table_ref')->distinct()->where('business_id', $this->businessId)->where('channel', 'web')->where('table_ref IS NOT NULL', null, false)->get()->getResultArray();
        $names = array_column($rows, 'table_ref');
        natcasesort($names);

        return array_values($names);
    }

    /** @return array<string, int> status => count (all = total), for the chips */
    public function counts(array $f): array
    {
        $b = db_connect()->table('orders o')->select('o.status, COUNT(*) AS n')->where('o.business_id', $this->businessId)->where('o.status !=', 'held');
        $this->filters($b, ['channel' => $f['channel'] ?? '', 'table' => $f['table'] ?? '', 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? '']);
        $out = ['all' => 0];
        foreach ($b->groupBy('o.status')->get()->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['all'] += (int) $r['n'];
        }

        return $out;
    }

    /** May the signed-in user open this order (own orders only without orders.all)? */
    public function visible(array $order): bool
    {
        return $this->onlyUserId === null || (int) $order['user_id'] === $this->onlyUserId;
    }
}
