<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

/**
 * Default paper size and copies per document type, set once per outlet in Setup › Printing and used
 * whenever a document is printed without choosing a size (order page, till, auto print):
 *
 *   receipt  the sale receipt (till, "Print receipt")      58 | 80 | a4 | a5
 *   invoice  the sales invoice                             a4 | a5 | 80 | 58
 *   challan  the delivery challan                          a4 | a5
 *   kot      the kitchen ticket                            80 | 58
 *   mushak   NBR Mushak-6.3 VAT invoice (Invoices)         a4 | 80  (2 copies unless set: buyer + supplier)
 *   prescription  clinic prescription (Clinic module)      a4 | a5  (a saved prescription pad overrides it)
 *
 * Stored in businesses.settings.print = {receipt: {size, copies}, …}. Outlets set up before this
 * existed follow their old "Invoice design" (settings.printFormat).
 */
final class PrintSettings
{
    public const DOCS = [
        'receipt' => ['label' => 'Sale receipt', 'sizes' => ['80', '58', 'a4', 'a5']],
        'invoice' => ['label' => 'Invoice', 'sizes' => ['a4', 'a5', '80', '58']],
        'challan' => ['label' => 'Delivery challan', 'sizes' => ['a4', 'a5']],
        'kot' => ['label' => 'Kitchen ticket', 'sizes' => ['80', '58']],
        'mushak' => ['label' => 'Mushak-6.3 VAT invoice', 'sizes' => ['a4', '80'], 'copies' => 2],
        // Clinic module: the default paper of a prescription (a doctor's / clinic's template can choose its own)
        'prescription' => ['label' => 'Prescription', 'sizes' => ['a4', 'a5'], 'module' => 'clinic'],
    ];

    public const SIZES = ['58' => '58 mm roll', '80' => '80 mm roll', 'a4' => 'A4 page', 'a5' => 'A5 page'];

    public const MAX_COPIES = 4;

    public const MODES = ['browser' => 'Print dialog', 'bluetooth' => 'Bluetooth thermal printer', 'rawbt' => 'RawBT app (Android)'];

    /**
     * The outlet's default printer behaviour (Setup › Printing), used by every device until that device
     * changes it in its own Printer setup: how receipts go out, the roll width, and what prints after a sale.
     *
     * @return array{mode: string, paper: string, auto: bool, kot: bool}
     */
    public static function printer(array $business): array
    {
        $p = (array) ($business['settings']['printer'] ?? []);
        $mode = (string) ($p['mode'] ?? 'browser');
        $paper = (string) ($p['paper'] ?? '');

        return [
            'mode' => isset(self::MODES[$mode]) ? $mode : 'browser', 'paper' => in_array($paper, ['58', '80'], true) ? $paper : '',
            'auto' => ! empty($p['auto']), 'kot' => ! empty($p['kot']),
        ];
    }

    /** @return array{mode: string, paper: string, auto: bool, kot: bool} validated printer_* fields of the Setup form */
    public static function printerFromForm(array $in, array $business): array
    {
        $cur = self::printer($business);
        $mode = (string) ($in['printer_mode'] ?? $cur['mode']);
        $paper = (string) ($in['printer_paper'] ?? $cur['paper']);

        return [
            'mode' => isset(self::MODES[$mode]) ? $mode : $cur['mode'], 'paper' => in_array($paper, ['58', '80'], true) ? $paper : '',
            'auto' => ! empty($in['printer_auto']), 'kot' => ! empty($in['printer_kot']),
        ];
    }

    /** @return array{size: string, copies: int} */
    public static function for(array $business, string $doc): array
    {
        $doc = isset(self::DOCS[$doc]) ? $doc : 'receipt';
        $all = (array) ($business['settings']['print'] ?? []);
        $set = (array) ($all[$doc] ?? []);
        $size = (string) ($set['size'] ?? self::legacy($business, $doc));
        if (! in_array($size, self::DOCS[$doc]['sizes'], true)) {
            $size = self::DOCS[$doc]['sizes'][0];
        }

        return ['size' => $size, 'copies' => max(1, min(self::MAX_COPIES, (int) ($set['copies'] ?? (self::DOCS[$doc]['copies'] ?? 1))))];
    }

    /** @return array<string, array{size: string, copies: int}> every document type */
    public static function all(array $business): array
    {
        $out = [];
        foreach (array_keys(self::DOCS) as $d) {
            $out[$d] = self::for($business, $d);
        }

        return $out;
    }

    /**
     * Validated settings.print from the Setup form (print_<doc>_size, print_<doc>_copies).
     *
     * @return array<string, array{size: string, copies: int}>
     */
    public static function fromForm(array $in, array $business): array
    {
        $out = [];
        foreach (self::DOCS as $d => $def) {
            $cur = self::for($business, $d);
            $size = (string) ($in['print_' . $d . '_size'] ?? $cur['size']);
            $out[$d] = [
                'size' => in_array($size, $def['sizes'], true) ? $size : $cur['size'],
                'copies' => max(1, min(self::MAX_COPIES, (int) ($in['print_' . $d . '_copies'] ?? $cur['copies']))),
            ];
        }

        return $out;
    }

    public static function isPage(string $size): bool
    {
        return $size === 'a4' || $size === 'a5';
    }

    private static function legacy(array $business, string $doc): string
    {
        $f = (string) ($business['settings']['printFormat'] ?? 'thermal');

        return match ($doc) {
            'receipt' => match ($f) { 'a4', 'letterhead' => 'a4', 'a5' => 'a5', default => '80' },
            'invoice' => $f === 'a5' ? 'a5' : 'a4',
            'challan' => $f === 'a5' ? 'a5' : 'a4',
            'mushak' => 'a4',
            default => '80',
        };
    }
}
