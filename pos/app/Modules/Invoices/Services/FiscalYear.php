<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

/**
 * Bangladesh fiscal year: 1 July to 30 June. FY "2026-27" runs from 1 Jul 2026 to 30 Jun 2027.
 * Dates are read in the app timezone (Asia/Dhaka).
 */
final class FiscalYear
{
    public static function label(string|\DateTimeInterface|null $at = null): string
    {
        $ts = $at instanceof \DateTimeInterface ? $at->getTimestamp() : strtotime($at ?? 'now');
        $y = (int) date('Y', $ts);
        $start = (int) date('n', $ts) >= 7 ? $y : $y - 1;

        return $start . '-' . str_pad((string) (($start + 1) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{0: string, 1: string} first and last day (Y-m-d) of a label */
    public static function range(string $label): array
    {
        $start = (int) substr($label, 0, 4);

        return [$start . '-07-01', ($start + 1) . '-06-30'];
    }

    public static function valid(string $label): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $label, $m)) {
            return false;
        }

        return ((int) $m[1] + 1) % 100 === (int) $m[2];
    }
}
