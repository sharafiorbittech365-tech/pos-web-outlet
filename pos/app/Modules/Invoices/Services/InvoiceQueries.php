<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use CodeIgniter\Database\BaseBuilder;

/** Read queries for the Invoices list and the Mushak-6.3 register (current outlet, indexed filters). */
final class InvoiceQueries
{
    public function __construct(private readonly int $businessId)
    {
    }

    public static function forCurrent(): self
    {
        return new self(service('posSession')->businessId());
    }

    /** @param array{state?: string, src?: string, table?: string, from?: string, to?: string} $f */
    public function listQuery(array $f): BaseBuilder
    {
        $b = db_connect()->table('invoices i')
            ->select('i.id, i.status, i.doc_no, i.bill_name, i.bill_phone, i.total AS draft_total, i.due_date, i.created_at, i.issued_at, i.order_id,
                o.status AS order_status, o.channel, o.table_ref, o.total, o.paid_total, o.due_total, (SELECT m.doc_no FROM mushak_invoices m WHERE m.order_id = i.order_id AND m.business_id = i.business_id LIMIT 1) AS mushak_no', false)
            ->join('orders o', 'o.id = i.order_id AND o.business_id = i.business_id', 'left')
            ->where('i.business_id', $this->businessId);
        $this->filters($b, $f);

        return $b;
    }

    private function filters(BaseBuilder $b, array $f): void
    {
        match ($f['state'] ?? '') {
            'draft' => $b->where('i.status', 'draft'),
            'void' => $b->where('i.status', 'void'),
            'due' => $b->where('i.status', 'issued')->where('o.status', 'due'),
            'partial' => $b->where('i.status', 'issued')->where('o.status', 'partial'),
            'paid' => $b->where('i.status', 'issued')->where('o.status', 'paid'),
            default => null,
        };
        $src = (string) ($f['src'] ?? '');
        if ($src === 'table') {
            $b->where('o.channel', 'web')->where('o.table_ref IS NOT NULL', null, false);
        } elseif (isset(InvoiceSync::SOURCES[$src])) {
            $channels = InvoiceSync::channelsOf($src);
            if ($channels !== []) {
                $b->whereIn('o.channel', $channels);
                if ($src === 'web') {
                    $b->groupStart()->where('o.channel !=', 'web')->orWhere('o.table_ref', null)->groupEnd();   // not the QR-on-a-table orders
                }
            } else {
                $b->groupStart()->where('o.channel', null)->orWhereNotIn('o.channel', array_merge(InvoiceSync::channelsOf('pos'), InvoiceSync::channelsOf('web')))->groupEnd();
            }
        }
        if (! empty($f['table'])) {
            $b->where('o.channel', 'web')->where('o.table_ref', mb_substr((string) $f['table'], 0, 60));
        }
        if (! empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'])) {
            $b->where('i.created_at >=', $f['from'] . ' 00:00:00');
        }
        if (! empty($f['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'])) {
            $b->where('i.created_at <=', $f['to'] . ' 23:59:59');
        }
    }

    /** @return array<string, int> invoices per source (pos, web, invoice) for the source chips */
    public function sourceCounts(): array
    {
        $rows = db_connect()->table('invoices i')->select("o.channel AS ch, (o.table_ref IS NOT NULL) AS tbl, COUNT(*) AS n", false)
            ->join('orders o', 'o.id = i.order_id AND o.business_id = i.business_id', 'left')
            ->where('i.business_id', $this->businessId)->groupBy('o.channel')->groupBy('tbl')->get()->getResultArray();
        $out = ['pos' => 0, 'web' => 0, 'table' => 0, 'invoice' => 0];
        foreach ($rows as $r) {
            $out[InvoiceSync::sourceOf($r['ch'], (int) $r['tbl'] === 1 ? 'x' : null)] += (int) $r['n'];
        }

        return $out;
    }

    /** @return list<string> the tables guests have ordered from by QR (for the filter), in table order */
    public function tables(): array
    {
        $rows = db_connect()->table('invoices i')->select('o.table_ref')->distinct()
            ->join('orders o', 'o.id = i.order_id AND o.business_id = i.business_id')
            ->where('i.business_id', $this->businessId)->where('o.channel', 'web')->where('o.table_ref IS NOT NULL', null, false)->get()->getResultArray();
        $names = array_column($rows, 'table_ref');
        natcasesort($names);

        return array_values($names);
    }

    /** @return array<string, int> counts per state for the chips */
    public function counts(): array
    {
        $rows = db_connect()->table('invoices i')->select("CASE WHEN i.status <> 'issued' THEN i.status WHEN o.status IN ('paid','partial','due','returned') THEN o.status ELSE 'void' END AS st, COUNT(*) AS n", false)
            ->join('orders o', 'o.id = i.order_id AND o.business_id = i.business_id', 'left')
            ->where('i.business_id', $this->businessId)->groupBy('st')->get()->getResultArray();
        $out = array_column($rows, 'n', 'st');
        $out['all'] = array_sum($out);

        return array_map('intval', $out);
    }

    public function mushakQuery(string $fy): BaseBuilder
    {
        $b = db_connect()->table('mushak_invoices m')
            ->select('m.id, m.order_id, m.doc_no, m.fiscal_year, m.serial, m.issued_at, m.buyer_name, m.buyer_bin, m.value_total, m.vat_total, m.grand_total, m.user_name, o.doc_no AS sale_no, i.id AS invoice_id')
            ->join('orders o', 'o.id = m.order_id', 'left')
            ->join('invoices i', 'i.order_id = m.order_id AND i.business_id = m.business_id', 'left')
            ->where('m.business_id', $this->businessId);
        if (FiscalYear::valid($fy)) {
            $b->where('m.fiscal_year', $fy);
        }

        return $b;
    }

    /** @return list<string> fiscal years with Mushak-6.3 (newest first) */
    public function mushakYears(): array
    {
        return array_column(db_connect()->table('mushak_invoices')->select('fiscal_year')->distinct()->where('business_id', $this->businessId)->orderBy('fiscal_year', 'DESC')->get()->getResultArray(), 'fiscal_year');
    }
}
