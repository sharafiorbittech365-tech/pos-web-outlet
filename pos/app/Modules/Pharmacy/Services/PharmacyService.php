<?php

declare(strict_types=1);

namespace App\Modules\Pharmacy\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Pharmacy alerts of the current outlet: expired batches still on the shelf, batches expiring
 * within the configured window, low / out-of-stock medicines; and writing off an expired batch
 * (core StockService::adjustBatch, logged as a stock movement).
 */
final class PharmacyService
{
    public const VIEWS = ['near' => 'Near expiry', 'expired' => 'Expired', 'low' => 'Low stock'];

    public function __construct(private readonly int $businessId, private readonly array $business)
    {
    }

    public static function make(): self
    {
        $s = service('posSession');

        return new self($s->businessId(), $s->business());
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }

    public function nearDays(): int
    {
        return PharmacySettings::for($this->business)['nearDays'];
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    private function nearDate(): string
    {
        return date('Y-m-d', strtotime('+' . $this->nearDays() . ' days'));
    }

    private function lowExpr(): string
    {
        return 'COALESCE(p.low_stock_at, ' . $this->db()->escape((float) ($this->business['low_stock_alert'] ?? 0)) . ')';
    }

    /** @return array{expired: int, expired_qty: float, expired_value: float, near: int, low: int, out: int, linked: int} */
    public function stats(): array
    {
        $db = $this->db();
        $e = $db->query('SELECT COUNT(*) AS n, COALESCE(SUM(b.qty), 0) AS q, COALESCE(SUM(b.qty * p.cost), 0) AS v FROM product_batches b JOIN products p ON p.id = b.product_id AND p.deleted_at IS NULL
                          WHERE b.business_id = ? AND b.qty > 0 AND b.expires_on IS NOT NULL AND b.expires_on < ?', [$this->businessId, $this->today()])->getRowArray() ?? [];
        $near = (int) ($db->query('SELECT COUNT(*) AS n FROM product_batches b JOIN products p ON p.id = b.product_id AND p.deleted_at IS NULL
                          WHERE b.business_id = ? AND b.qty > 0 AND b.expires_on >= ? AND b.expires_on <= ?', [$this->businessId, $this->today(), $this->nearDate()])->getRow('n') ?? 0);
        $low = $db->query("SELECT COALESCE(SUM(p.stock_qty > 0 AND p.stock_qty <= {$this->lowExpr()}), 0) AS low_n, COALESCE(SUM(p.stock_qty <= 0), 0) AS out_n,
                                  COALESCE(SUM(p.medicine_id IS NOT NULL), 0) AS linked
                             FROM products p WHERE p.business_id = ? AND p.deleted_at IS NULL AND p.is_active = 1 AND p.stock_type <> 'none'", [$this->businessId])->getRowArray() ?? [];

        return ['expired' => (int) ($e['n'] ?? 0), 'expired_qty' => (float) ($e['q'] ?? 0), 'expired_value' => (float) ($e['v'] ?? 0), 'near' => $near,
            'low' => (int) ($low['low_n'] ?? 0), 'out' => (int) ($low['out_n'] ?? 0), 'linked' => (int) ($low['linked'] ?? 0)];
    }

    /** Batches for the alert lists: $view near | expired. */
    public function batchesQuery(string $view): BaseBuilder
    {
        $b = $this->db()->table('product_batches b')
            ->select('b.id, b.batch_no, b.expires_on, b.qty, b.product_id, p.name AS product, p.generic_name, p.unit, p.cost, (b.qty * p.cost) AS value, DATEDIFF(b.expires_on, CURDATE()) AS days_left')
            ->join('products p', 'p.id = b.product_id')
            ->where('b.business_id', $this->businessId)->where('p.business_id', $this->businessId)->where('p.deleted_at', null)->where('b.qty >', 0)
            ->where('b.expires_on IS NOT NULL', null, false);
        if ($view === 'expired') {
            $b->where('b.expires_on <', $this->today());
        } else {
            $b->where('b.expires_on >=', $this->today())->where('b.expires_on <=', $this->nearDate());
        }

        return $b;
    }

    /** Tracked products at or below their low-stock level (out of stock included). */
    public function lowQuery(): BaseBuilder
    {
        return $this->db()->table('products p')
            ->select('p.id, p.name, p.generic_name, p.unit, p.stock_qty, p.stock_type, ' . $this->lowExpr() . ' AS low_at')
            ->where('p.business_id', $this->businessId)->where('p.deleted_at', null)->where('p.is_active', 1)->where('p.stock_type <>', 'none')
            ->where('p.stock_qty <= ' . $this->lowExpr(), null, false);
    }

    /**
     * Take an expired batch off the shelf (quantity to zero), logged as an adjustment.
     *
     * @return array{product: string, batch: string, qty: float}
     */
    public function writeOff(int $batchId, string $note = ''): array
    {
        $b = $this->db()->table('product_batches b')->select('b.id, b.product_id, b.batch_no, b.qty, b.expires_on, p.name')
            ->join('products p', 'p.id = b.product_id')
            ->where('b.id', $batchId)->where('b.business_id', $this->businessId)->where('p.business_id', $this->businessId)->get()->getRowArray()
            ?? throw new NotFoundException('Batch not found.');
        if ($b['expires_on'] === null || $b['expires_on'] >= $this->today()) {
            throw new BusinessRuleException('Batch ' . $b['batch_no'] . ' has not expired — correct its quantity in Inventory instead.');
        }
        if ((float) $b['qty'] <= 0) {
            throw new BusinessRuleException('Batch ' . $b['batch_no'] . ' is already empty.');
        }
        service('stock')->adjustBatch((int) $b['product_id'], $batchId, -(float) $b['qty'], trim('Expired write-off' . ($note !== '' ? ' · ' . mb_substr($note, 0, 120) : '')));
        service('audit')->activity('Expired batch written off', $b['name'] . ' · batch ' . $b['batch_no'] . ' · ' . rtrim(rtrim(number_format((float) $b['qty'], 3, '.', ''), '0'), '.'));

        return ['product' => (string) $b['name'], 'batch' => (string) $b['batch_no'], 'qty' => (float) $b['qty']];
    }

    /**
     * Expiry summary per batch-tracked product for the till tiles: next unexpired expiry, whether
     * it is within the near window, and the quantity of expired stock still on file.
     *
     * @param list<int> $productIds
     * @return array<int, array{next: ?string, near: bool, expired: float}>
     */
    public function expiryFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $today = $this->today();
        $rows = $this->db()->table('product_batches')
            ->select('product_id, MIN(CASE WHEN expires_on IS NULL OR expires_on >= ' . $this->db()->escape($today) . ' THEN expires_on END) AS next_exp,
                      SUM(CASE WHEN expires_on < ' . $this->db()->escape($today) . ' THEN qty ELSE 0 END) AS expired_qty', false)
            ->where('business_id', $this->businessId)->whereIn('product_id', $productIds)->where('qty >', 0)
            ->groupBy('product_id')->get()->getResultArray();
        $near = $this->nearDate();
        $out = [];
        foreach ($rows as $r) {
            $next = $r['next_exp'] !== null ? (string) $r['next_exp'] : null;
            $out[(int) $r['product_id']] = ['next' => $next, 'near' => $next !== null && $next <= $near, 'expired' => (float) $r['expired_qty']];
        }

        return $out;
    }
}
