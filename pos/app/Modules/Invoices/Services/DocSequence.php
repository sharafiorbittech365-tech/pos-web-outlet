<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

/**
 * Gap-free document numbers per outlet, series and period (doc_sequences): invoice numbers
 * ("invoice", period '' / '2026' / '2026-27') and Mushak-6.3 serials ("mushak", fiscal year).
 *
 * next() must run inside the caller's transaction (Tx::run): the row is locked (SELECT … FOR UPDATE)
 * until that transaction ends, so two people issuing at once get consecutive numbers, and a
 * rolled-back issue gives its number back — no gaps, no duplicates.
 */
final class DocSequence
{
    public const SERIES = ['invoice', 'mushak'];

    public static function next(int $businessId, string $series, string $period = ''): int
    {
        $db = db_connect();
        $db->query('INSERT IGNORE INTO doc_sequences (business_id, series, period, last_number, updated_at) VALUES (?, ?, ?, 0, NOW())', [$businessId, $series, $period]);
        $last = (int) $db->query('SELECT last_number FROM doc_sequences WHERE business_id = ? AND series = ? AND period = ? FOR UPDATE', [$businessId, $series, $period])->getRow('last_number');
        $db->query('UPDATE doc_sequences SET last_number = ?, updated_at = NOW() WHERE business_id = ? AND series = ? AND period = ?', [$last + 1, $businessId, $series, $period]);

        return $last + 1;
    }

    /** The number the next document would get (preview only — never reserve with this). */
    public static function peek(int $businessId, string $series, string $period = ''): int
    {
        return (int) (db_connect()->table('doc_sequences')->select('last_number')->where('business_id', $businessId)->where('series', $series)->where('period', $period)
            ->get()->getRow('last_number') ?? 0) + 1;
    }
}
