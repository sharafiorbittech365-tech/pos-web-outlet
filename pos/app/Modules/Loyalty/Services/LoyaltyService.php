<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Libraries\PosSession;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use OmniPOS\Core\Database\Tx;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Loyalty points: customers earn floor(paid sale ÷ earnPer) points (settings.loyalty), returns take
 * the matching points back, managers adjust by hand. customers.points is the cached balance of
 * loyalty_ledger. Awarding runs from the order:created / order:paid / order:returned events.
 */
final class LoyaltyService
{
    public const DEFAULTS = ['earnPer' => 100, 'pointValue' => 1, 'minRedeem' => 50];

    private function db(): BaseConnection
    {
        return db_connect();
    }

    /** @return array{earnPer: float, pointValue: float, minRedeem: int} */
    public static function rules(array $settings): array
    {
        $l = (array) ($settings['loyalty'] ?? []) + self::DEFAULTS;

        return ['earnPer' => max(1.0, (float) $l['earnPer']), 'pointValue' => max(0.0, (float) $l['pointValue']), 'minRedeem' => max(0, (int) $l['minRedeem'])];
    }

    /* ------------------------------------------------------------------ events */

    /** order:created — a sale that is fully paid earns points now; credit sales earn when paid. */
    public static function onCreated(array $order): void
    {
        if (($order['status'] ?? '') === 'paid') {
            (new self())->award($order, 'Sale');
        }
    }

    /** order:paid — a due / partial sale became fully paid. */
    public static function onPaid(array $order): void
    {
        if (($order['status'] ?? '') === 'paid') {
            (new self())->award($order, 'Paid');
        }
    }

    /** order:returned — take back the points of the returned value. */
    public static function onReturned(array $order, float $amount = 0.0): void
    {
        (new self())->claw($order, $amount);
    }

    /** @return array<string, mixed>|null the business (settings decoded) when loyalty applies to it */
    private function context(array $order): ?array
    {
        $cid = (int) ($order['customer_id'] ?? 0);
        $tid = (int) ($order['tenant_id'] ?? 0);
        $bid = (int) ($order['business_id'] ?? 0);
        if ($cid === 0 || $tid === 0 || $bid === 0 || ! service('moduleAccess')->isOn($tid, 'loyalty')) {
            return null;
        }
        $b = $this->db()->table('businesses')->select('id, tenant_id, settings')->where('id', $bid)->where('tenant_id', $tid)->get()->getRowArray();
        if ($b === null) {
            return null;
        }
        $b['settings'] = json_decode((string) $b['settings'], true) ?: [];

        return $b;
    }

    public function award(array $order, string $why): int
    {
        $b = $this->context($order);
        if ($b === null) {
            return 0;
        }
        $db = $this->db();
        $oid = (int) ($order['id'] ?? 0);
        // once per order
        if ($oid > 0 && $db->table('loyalty_ledger')->where('order_id', $oid)->where('points >', 0)->countAllResults() > 0) {
            return 0;
        }
        $c = $db->table('customers')->select('id, is_walk_in')->where('id', (int) $order['customer_id'])->where('business_id', $b['id'])->get()->getRowArray();
        if ($c === null || (bool) $c['is_walk_in']) {
            return 0;
        }
        $pts = (int) floor((float) ($order['total'] ?? 0) / self::rules($b['settings'])['earnPer']);
        if ($pts <= 0) {
            return 0;
        }
        $this->write((int) $b['tenant_id'], (int) $b['id'], (int) $c['id'], $pts, $oid ?: null, $why . ' ' . ($order['doc_no'] ?? ('#' . $oid)));

        return $pts;
    }

    private function claw(array $order, float $amount): int
    {
        $b = $this->context($order);
        if ($b === null || $amount <= 0) {
            return 0;
        }
        $db = $this->db();
        $earned = (int) ($db->table('loyalty_ledger')->selectSum('points', 'p')->where('order_id', (int) $order['id'])->where('points >', 0)->get()->getRow('p') ?? 0);
        if ($earned <= 0) {
            return 0;
        }
        $taken = -(int) ($db->table('loyalty_ledger')->selectSum('points', 'p')->where('order_id', (int) $order['id'])->where('points <', 0)->get()->getRow('p') ?? 0);
        $pts = min($earned - $taken, (int) floor($amount / self::rules($b['settings'])['earnPer']));
        $balance = (int) ($db->table('customers')->select('points')->where('id', (int) $order['customer_id'])->get()->getRow('points') ?? 0);
        $pts = min($pts, max(0, $balance));
        if ($pts <= 0) {
            return 0;
        }
        $this->write((int) $b['tenant_id'], (int) $b['id'], (int) $order['customer_id'], -$pts, (int) $order['id'], 'Return ' . ($order['doc_no'] ?? ''));

        return $pts;
    }

    private function write(int $tid, int $bid, int $customerId, int $points, ?int $orderId, string $note): void
    {
        Tx::run(function (BaseConnection $db) use ($tid, $bid, $customerId, $points, $orderId, $note): void {
            $db->table('loyalty_ledger')->insert(['tenant_id' => $tid, 'business_id' => $bid, 'customer_id' => $customerId, 'points' => $points, 'order_id' => $orderId,
                'note' => mb_substr($note, 0, 160), 'created_at' => date('Y-m-d H:i:s')]);
            $db->table('customers')->set('points', 'GREATEST(0, points + ' . $points . ')', false)->where('id', $customerId)->where('business_id', $bid)->update();
        });
    }

    /* ------------------------------------------------------------------ back office */

    private function bid(): int
    {
        return service('tenant')->businessId();
    }

    public function membersQuery(): BaseBuilder
    {
        return $this->db()->table('customers c')->select('c.id, c.name, c.phone, c.points, (SELECT MAX(l.created_at) FROM loyalty_ledger l WHERE l.customer_id = c.id) AS last_at')
            ->where('c.business_id', $this->bid())->where('c.is_walk_in', 0);
    }

    public function historyQuery(): BaseBuilder
    {
        return $this->db()->table('loyalty_ledger l')->select('l.id, l.points, l.note, l.created_at, l.order_id, c.name AS customer, c.phone')
            ->join('customers c', 'c.id = l.customer_id')->where('l.business_id', $this->bid());
    }

    /** @return array{members: int, points: int} */
    public function totals(): array
    {
        $r = $this->db()->query('SELECT SUM(points > 0) AS m, COALESCE(SUM(points), 0) AS p FROM customers WHERE business_id = ? AND is_walk_in = 0', [$this->bid()])->getRowArray() ?? [];

        return ['members' => (int) ($r['m'] ?? 0), 'points' => (int) ($r['p'] ?? 0)];
    }

    /** @param array<string, mixed> $in earn_per, point_value, min_redeem */
    public function saveRules(array $in): void
    {
        $per = (float) ($in['earn_per'] ?? 0);
        $value = (float) ($in['point_value'] ?? -1);
        if ($per < 1 || $value < 0) {
            throw new ValidationException('Check the loyalty rules.', ['earn_per' => 'Spend for 1 point must be at least 1; point value zero or more.']);
        }
        $b = $this->db()->table('businesses')->select('id, settings')->where('id', $this->bid())->where('tenant_id', service('tenant')->tenantId())->get()->getRowArray() ?? throw new NotFoundException('Outlet not found.');
        $s = json_decode((string) $b['settings'], true) ?: [];
        $s['loyalty'] = ['earnPer' => round($per, 2), 'pointValue' => round($value, 2), 'minRedeem' => max(0, (int) ($in['min_redeem'] ?? 0))];
        $this->db()->table('businesses')->where('id', $b['id'])->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
        PosSession::forgetBusiness((int) $b['id']);
        service('audit')->activity('Loyalty rules', '1 pt / ' . $per . ' · value ' . $value);
    }

    public function adjust(int $customerId, int $points, string $note): void
    {
        if ($points === 0) {
            throw new ValidationException('Enter the points to add or remove.', ['points' => 'e.g. 50 or -20.']);
        }
        $c = $this->db()->table('customers')->select('id, name, points')->where('id', $customerId)->where('business_id', $this->bid())->get()->getRowArray() ?? throw new NotFoundException('Customer not found.');
        if ((int) $c['points'] + $points < 0) {
            throw new BusinessRuleException($c['name'] . ' has only ' . (int) $c['points'] . ' points.');
        }
        $this->write(service('tenant')->tenantId(), $this->bid(), $customerId, $points, null, trim('Adjusted · ' . mb_substr($note, 0, 120), ' ·'));
        service('audit')->activity('Loyalty adjusted', $c['name'] . ': ' . ($points > 0 ? '+' : '') . $points);
    }
}
