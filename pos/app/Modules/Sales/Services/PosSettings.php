<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * POS switches (Setup › POS), per outlet in businesses.settings.pos. A switch that is off removes the
 * option from the till for everyone and the server refuses it; when it is on, each person still needs
 * the matching role permission (Users & roles).
 */
final class PosSettings
{
    /** key => [label, help, default, role permission] */
    public const SWITCHES = [
        'price_edit' => ['Edit item price at the till', 'Staff with the "Change item price" permission can set a different unit price on a cart line. Off: prices always come from the catalogue.', true, 'pos.price'],
        'discount' => ['Discounts &amp; tax change', 'Order discount and VAT override at the till, limited to each role\'s maximum discount.', true, 'pos.discount'],
        'credit' => ['Credit sale (leave a balance due)', 'Sell on credit to a named customer and leave the balance as a due.', true, 'pos.credit'],
        'quotation' => ['Quotations', 'Save the cart as a quotation instead of a sale.', true, 'pos.quotation'],
    ];

    /** Products per row on the till's product grid: 0 = automatic (as many as fit), else 2–6. */
    public const PER_ROW = [0, 2, 3, 4, 5, 6];

    /** @var array<int, array<string, bool>> */
    private static array $cache = [];

    /** @return array<string, bool> */
    public static function all(?array $business = null): array
    {
        $business ??= service('posSession')->business();
        $id = (int) ($business['id'] ?? 0);
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }
        $saved = (array) ($business['settings']['pos'] ?? []);
        $out = [];
        foreach (self::SWITCHES as $k => [, , $def]) {
            $out[$k] = array_key_exists($k, $saved) ? (bool) $saved[$k] : $def;
        }

        return self::$cache[$id] = $out;
    }

    public static function perRow(?array $business = null): int
    {
        $business ??= service('posSession')->business();
        $n = (int) ($business['settings']['pos']['per_row'] ?? 0);

        return in_array($n, self::PER_ROW, true) ? $n : 0;
    }

    public static function on(string $key, ?array $business = null): bool
    {
        return (bool) (self::all($business)[$key] ?? true);
    }

    /** Role permission AND the outlet switch. */
    public static function allows(string $perm): bool
    {
        foreach (self::SWITCHES as $k => [, , , $p]) {
            if ($p === $perm && ! self::on($k)) {
                return false;
            }
        }

        return service('posSession')->can($perm);
    }

    /** @param array<string, mixed> $in posted form @return array<string, bool|int> */
    public static function fromForm(array $in): array
    {
        $out = [];
        foreach (array_keys(self::SWITCHES) as $k) {
            $out[$k] = ! empty($in['pos_' . $k]);
        }
        $n = (int) ($in['pos_per_row'] ?? 0);
        $out['per_row'] = in_array($n, self::PER_ROW, true) ? $n : 0;

        return $out;
    }
}
