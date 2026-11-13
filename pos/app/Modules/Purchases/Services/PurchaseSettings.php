<?php

declare(strict_types=1);

namespace App\Modules\Purchases\Services;

/**
 * Module switches of Suppliers & Purchases (Setup › Purchasing), per outlet in businesses.settings.purchasing.
 * Every page, button and stock effect of the module checks these; nothing runs when its switch is off.
 *
 * Stock rule (with the outlet's Inventory switch, Setup › Money):
 *   Purchase ON  + Inventory ON  → receiving a purchase raises stock (when "Automatic stock update" is on)
 *   Purchase OFF + Inventory ON  → stock is entered by hand (Inventory page)
 *   Purchase ON  + Inventory OFF → suppliers and payables work, stock is never touched
 *   Purchase OFF + Inventory OFF → neither
 */
final class PurchaseSettings
{
    /** key => [label, help, default] */
    public const SWITCHES = [
        'supplier' => ['Supplier management', 'Supplier list, contact details, opening balance, ledger and purchase history.', true],
        'purchase' => ['Purchase management', 'Purchase orders and invoices, receiving, editing, cancelling and purchase reports.', true],
        'auto_stock' => ['Automatic stock update', 'Receiving a purchase raises stock by the received quantity (needs Inventory ON).', true],
        'return' => ['Purchase return', 'Send goods back to a supplier: stock goes down and the supplier due is reduced.', true],
        'payment' => ['Supplier payment', 'Pay suppliers in full or in part, payment history and receipts.', true],
        'approval' => ['Purchase approval', 'A purchase order needs approval before it can be received.', false],
    ];

    /** @var array<int, array<string, bool>> */
    private static array $cache = [];

    /**
     * @param array<string, mixed>|null $business default: the signed-in outlet
     * @return array<string, bool>
     */
    public static function all(?array $business = null): array
    {
        $business ??= service('posSession')->business();
        $id = (int) ($business['id'] ?? 0);
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $saved = (array) ($business['settings']['purchasing'] ?? []);
        $out = [];
        foreach (self::SWITCHES as $k => [, , $def]) {
            $out[$k] = array_key_exists($k, $saved) ? (bool) $saved[$k] : $def;
        }

        return self::$cache[$id] = $out;
    }

    public static function on(string $key, ?array $business = null): bool
    {
        return (bool) (self::all($business)[$key] ?? false);
    }

    /** Is the outlet's stock management (Inventory) running? */
    public static function inventoryOn(?array $business = null): bool
    {
        $business ??= service('posSession')->business();

        return ! empty($business['stock_enabled']) && service('posSession')->moduleOn('inventory');
    }

    /** Does receiving a purchase change stock? Purchases ON, Inventory ON and "Automatic stock update" ON. */
    public static function stockLinked(?array $business = null): bool
    {
        return self::on('purchase', $business) && self::on('auto_stock', $business) && self::inventoryOn($business);
    }

    /** The module (sidebar entry, pages) is reachable when suppliers or purchases are on. */
    public static function anyOn(?array $business = null): bool
    {
        return self::on('supplier', $business) || self::on('purchase', $business);
    }

    /** Which row of the Purchase × Inventory table applies: a short plain-words line for Setup. */
    public static function behaviour(?array $business = null): string
    {
        $p = self::on('purchase', $business);
        $i = self::inventoryOn($business);

        return match (true) {
            $p && $i => self::on('auto_stock', $business) ? 'Purchase ON + Inventory ON — receiving a purchase raises stock automatically.' : 'Purchase ON + Inventory ON — automatic stock update is off, so receiving a purchase leaves stock unchanged.',
            ! $p && $i => 'Purchase OFF + Inventory ON — stock is entered by hand on the Inventory page.',
            $p => 'Purchase ON + Inventory OFF — purchases and supplier accounts run, stock is not updated.',
            default => 'Purchase OFF + Inventory OFF — purchases and stock are both off.',
        };
    }

    /**
     * @param array<string, mixed> $in Setup form
     * @return array<string, bool>
     */
    public static function fromForm(array $in): array
    {
        $out = [];
        foreach (self::SWITCHES as $k => $_) {
            $out[$k] = ! empty($in['pur_' . $k]);
        }

        return $out;
    }
}
