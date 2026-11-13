<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;

/**
 * Product search shared by the catalogue and the marketplace.
 *
 *   >= 3 characters  FULLTEXT ft_prod_search (name, brand, sku, generic_name), boolean mode,
 *                    every word required and prefix-matched ("+galax* +a55*")
 *   shorter          prefix LIKE on name / brand / sku (uses the leftmost characters only)
 */
final class ProductSearch
{
    public static function apply(BaseBuilder $b, string $term, string $alias = 'p'): void
    {
        $term = trim(mb_substr($term, 0, 100));
        if ($term === '') {
            return;
        }
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $w): bool => mb_strlen($w) >= 3,
        ));
        if (mb_strlen($term) >= 3 && $words !== []) {
            $expr = implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', array_slice($words, 0, 8)));
            $b->where("MATCH({$alias}.name, {$alias}.brand, {$alias}.sku, {$alias}.generic_name) AGAINST (" . $b->db()->escape($expr) . ' IN BOOLEAN MODE)', null, false);

            return;
        }
        $b->groupStart()
            ->like($alias . '.name', $term, 'after')
            ->orLike($alias . '.brand', $term, 'after')
            ->orLike($alias . '.sku', $term, 'after')
            ->groupEnd();
    }

    /**
     * SQL for the price the customer pays (VAT added on top for exclusive products), for sorting.
     * Product rule: tax_mode default -> the outlet's mode; tax_rate NULL -> the outlet's rate.
     */
    public static function grossPriceSql(string $p = 'p', string $b = 'b'): string
    {
        return "({$p}.price * (CASE WHEN {$p}.tax_mode = 'exclusive' OR ({$p}.tax_mode = 'default' AND {$b}.tax_inclusive = 0)"
            . " THEN 1 + COALESCE({$p}.tax_rate, {$b}.tax_rate) / 100 ELSE 1 END))";
    }

    /** Same expression for one outlet whose VAT settings are known (no join needed). */
    public static function grossPriceSqlFor(bool $inclusive, float $defaultRate, string $p = 'p'): string
    {
        $exclusiveDefault = $inclusive ? '0' : '1';
        $rate = number_format($defaultRate, 2, '.', '');

        return "({$p}.price * (CASE WHEN {$p}.tax_mode = 'exclusive' OR ({$p}.tax_mode = 'default' AND {$exclusiveDefault} = 1)"
            . " THEN 1 + COALESCE({$p}.tax_rate, {$rate}) / 100 ELSE 1 END))";
    }
}
