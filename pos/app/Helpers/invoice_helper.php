<?php

declare(strict_types=1);

/*
 * Invoices view helpers (load with helper('invoice')).
 */

if (! function_exists('invoice_state_tag')) {
    /** Invoice state chip: draft | due | partial | paid | returned | void */
    function invoice_state_tag(string $state): string
    {
        helper('ui');
        $map = ['draft' => ['Draft', ''], 'due' => ['Issued · unpaid', 'danger'], 'partial' => ['Partially paid', 'warn'], 'paid' => ['Paid', 'ok'], 'returned' => ['Refunded', 'danger'], 'void' => ['Void', '']];
        [$l, $k] = $map[$state] ?? [ucfirst($state), ''];

        return tag_html($l, $k);
    }
}

if (! function_exists('invoice_source_tag')) {
    /** Where an invoice came from: pos | web | invoice (the invoice form). */
    function invoice_source_tag(string $source, string $table = ''): string
    {
        helper('ui');
        $map = ['pos' => ['POS', 'primary'], 'web' => ['Website', 'ok'], 'table' => ['Table QR', 'warn'], 'invoice' => ['Invoice', '']];
        [$l, $k] = $map[$source] ?? [ucfirst($source), ''];
        if ($source === 'table' && $table !== '') {
            $l = $table;
        }

        return tag_html($l, $k);
    }
}

if (! function_exists('mushak_num')) {
    /** Amount on the Mushak-6.3 table: 1,234.50 (no currency sign; the form says "(টাকায়)"). */
    function mushak_num(float|int|string|null $n, int $decimals = 2): string
    {
        return number_format((float) $n, $decimals);
    }
}
