<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;

/**
 * Every POS and website sale has an invoice in the Invoices list. Each source counts its own series
 * (doc_sequences "invoice_pos" / "invoice_web"): POS-00001, WEB-00001 — invoices made on the invoice
 * form keep the Setup › Invoice numbering (INV-00001). The sale's own number (orders.doc_no, the one
 * on the receipt) is never changed.
 *
 * Invoices are created on the spot after a POS sale and, for sales made elsewhere (the online shop,
 * API) or before this existed, when the Invoices list / an order page is opened. A sale that already
 * has an invoice is skipped (invoices.order_id is unique), so running this twice is harmless.
 */
final class InvoiceSync
{
    /** @var array<string, array{0: string, 1: string}> source => [label, number prefix] */
    public const SOURCES = ['pos' => ['POS', 'POS-'], 'web' => ['Website', 'WEB-'], 'table' => ['Table QR', 'TAB-'], 'invoice' => ['Invoice', '']];
    /** Order channels that count as each source. */
    private const CHANNELS = ['pos' => ['pos'], 'web' => ['web', 'api', 'marketplace']];
    /** Order statuses that are a sale (an open / cancelled order is not one yet). */
    private const SALE_STATUSES = ['paid', 'partial', 'due'];

    public function __construct(private readonly int $businessId)
    {
    }

    public static function forCurrent(): self
    {
        return new self(service('tenant')->businessId());
    }

    /** POS | web | invoice for an order channel (drives the number series, label and filter). */
    public static function sourceOf(?string $channel, ?string $tableRef = null): string
    {
        // a guest's order from the QR on a table: a web order on a table
        if ($channel === 'web' && trim((string) $tableRef) !== '') {
            return 'table';
        }
        foreach (self::CHANNELS as $src => $list) {
            if (in_array((string) $channel, $list, true)) {
                return $src;
            }
        }

        return 'invoice';
    }

    /** SQL list of the channels of one source (for the list filter). @return list<string> */
    public static function channelsOf(string $source): array
    {
        return self::CHANNELS[$source] ?? [];
    }

    /**
     * Create the missing invoices of this outlet, oldest sale first (so numbers follow the sales).
     *
     * @return int how many were created
     */
    public function syncAll(int $limit = 400): int
    {
        $rows = db_connect()->query(
            'SELECT o.id FROM orders o LEFT JOIN invoices i ON i.order_id = o.id WHERE o.business_id = ? AND i.id IS NULL AND o.channel IN (' . $this->in() . ') AND o.status IN (' . $this->in(self::SALE_STATUSES) . ') ORDER BY o.id LIMIT ' . max(1, $limit),
            array_merge([$this->businessId], $this->channels(), self::SALE_STATUSES),
        )->getResultArray();
        $made = 0;
        foreach ($rows as $r) {
            $made += $this->ensure((int) $r['id']) !== null ? 1 : 0;
        }

        return $made;
    }

    /** The invoice id of a sale, creating it first when it has none. Null when the order is not a sale of POS / the shop. */
    public function ensure(int $orderId): ?int
    {
        $db = db_connect();
        $had = $db->table('invoices')->select('id')->where('order_id', $orderId)->where('business_id', $this->businessId)->get()->getRow('id');
        if ($had !== null) {
            return (int) $had;
        }
        $o = $db->table('orders')->where('id', $orderId)->where('business_id', $this->businessId)->get()->getRowArray();
        if ($o === null || ! in_array((string) $o['channel'], $this->channels(), true) || ! in_array((string) $o['status'], self::SALE_STATUSES, true)) {
            return null;
        }
        $src = self::sourceOf((string) $o['channel'], (string) ($o['table_ref'] ?? ''));
        try {
            return Tx::run(function (BaseConnection $db) use ($o, $src): int {
                $cfg = InvoiceSettings::forCurrent()->get();
                $period = InvoiceSettings::period($cfg, (string) $o['created_at']);
                $docNo = InvoiceSettings::format(['prefix' => self::SOURCES[$src][1]] + $cfg, DocSequence::next($this->businessId, 'invoice_' . $src, $period), $period);
                $at = (string) $o['created_at'];
                $db->table('invoices')->insert([
                    'tenant_id' => (int) $o['tenant_id'], 'business_id' => $this->businessId, 'order_id' => (int) $o['id'], 'status' => 'issued', 'doc_no' => $docNo,
                    'customer_id' => $o['customer_id'] ?: null, 'bill_name' => mb_substr((string) ($o['customer_name'] ?: 'Walk-in Customer'), 0, 120),
                    'bill_phone' => $o['customer_phone'] ?: null, 'ship_to' => $o['ship_address'] ? mb_substr((string) $o['ship_address'], 0, 255) : null,
                    'due_date' => $o['due_date'], 'total' => $o['total'], 'revision' => 1, 'created_by' => $o['user_id'] ?: null, 'issued_by' => $o['user_id'] ?: null,
                    'issued_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                ]);
                $id = (int) $db->insertID();
                $db->table('invoice_revisions')->insert([
                    'tenant_id' => (int) $o['tenant_id'], 'business_id' => $this->businessId, 'invoice_id' => $id, 'revision' => 1, 'action' => 'issued',
                    'summary' => 'Created from ' . strtolower(self::SOURCES[$src][0]) . ' sale ' . $o['doc_no'], 'user_id' => $o['user_id'] ?: null,
                    'user_name' => $o['user_name'] ? mb_substr((string) $o['user_name'], 0, 100) : null, 'created_at' => $at,
                ]);

                return $id;
            });
        } catch (\Throwable $e) {
            // a parallel request made it first (unique order_id), or the books are busy: the next look picks it up
            log_message('warning', 'Invoice sync of order {id}: {msg}', ['id' => $orderId, 'msg' => $e->getMessage()]);

            return null;
        }
    }

    /** @return list<string> */
    private function channels(): array
    {
        return array_merge(self::CHANNELS['pos'], self::CHANNELS['web']);
    }

    /** "?, ?, ?" for a list (default: the channels). @param list<string>|null $list */
    private function in(?array $list = null): string
    {
        return implode(', ', array_fill(0, count($list ?? $this->channels()), '?'));
    }
}
