<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

/**
 * Small dependency-free charts for the Reports page (pure HTML/CSS/SVG, colours come from the report-palette classes in
 * reports.css so dark mode and the shop's brand colour keep working). Every value is escaped; every chart has a text
 * alternative (legend or title) so it can be read without seeing the graphic.
 */
final class Charts
{
    /** 1250 -> 1.3k, 2 400 000 -> 2.4M */
    public static function compact(float $v): string
    {
        $a = abs($v);
        $s = $v < 0 ? '-' : '';
        if ($a >= 1_000_000) {
            return $s . rtrim(rtrim(number_format($a / 1_000_000, 1), '0'), '.') . 'M';
        }
        if ($a >= 1_000) {
            return $s . rtrim(rtrim(number_format($a / 1_000, 1), '0'), '.') . 'k';
        }

        return $s . (string) (int) round($a);
    }

    /**
     * One stacked bar: how every 100 of sales was used.
     *
     * @param list<array{label: string, value: float, cls: string}> $parts values must be >= 0
     * @param callable(float): string                              $money
     */
    public static function stack(array $parts, callable $money): string
    {
        $parts = array_values(array_filter($parts, static fn (array $p): bool => $p['value'] > 0.004));
        $total = array_sum(array_column($parts, 'value'));
        if ($total <= 0) {
            return '<p class="muted rp-empty">No sales in this period yet.</p>';
        }
        $bar = '';
        $leg = '';
        foreach ($parts as $p) {
            $pc = $p['value'] / $total * 100;
            $bar .= '<span class="rp-seg ' . $p['cls'] . '" style="width:' . number_format($pc, 2, '.', '') . '%" title="' . esc($p['label'] . ': ' . strip_tags($money($p['value'])) . ' (' . round($pc) . '%)', 'attr') . '"></span>';
            $leg .= '<li><i class="rp-dot ' . $p['cls'] . '"></i><span>' . esc($p['label']) . '</span><b>' . $money($p['value']) . '</b><small>' . round($pc) . '%</small></li>';
        }

        return '<div class="rp-stack" role="img" aria-label="Where the sales money went">' . $bar . '</div><ul class="rp-legend rp-legend-grid">' . $leg . '</ul>';
    }

    /**
     * Vertical bars over time (sales vs expenses). $rows: [label, sales, expenses, tooltip].
     *
     * @param list<array{0: string, 1: float, 2: float, 3: string}> $rows
     */
    public static function trend(array $rows): string
    {
        if ($rows === []) {
            return '<p class="muted rp-empty">No sales in this period yet.</p>';
        }
        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, $r[1], $r[2]);
        }
        if ($max <= 0) {
            return '<p class="muted rp-empty">No sales in this period yet.</p>';
        }
        // round the top of the scale to a friendly number
        $mag = 10 ** floor(log10($max));
        $top = ceil($max / $mag * 2) / 2 * $mag;
        $top = $top > 0 ? $top : $max;
        $n = count($rows);
        $every = max(1, (int) ceil($n / 12));
        $grid = '';
        for ($i = 4; $i >= 0; $i--) {
            $grid .= '<span class="rp-gl" style="bottom:' . ($i * 25) . '%"><small>' . esc(self::compact($top * $i / 4)) . '</small></span>';
        }
        $cols = '';
        foreach ($rows as $i => [$label, $sales, $exp, $tip]) {
            $cols .= '<div class="rp-col" title="' . esc($tip, 'attr') . '"><div class="rp-bars"><span class="rp-b rp-c0" style="height:' . number_format(min(100, $sales / $top * 100), 2, '.', '') . '%"></span>' . ($exp > 0 ? '<span class="rp-b rp-c3" style="height:' . number_format(min(100, $exp / $top * 100), 2, '.', '') . '%"></span>' : '') . '</div><small class="rp-x">' . ($i % $every === 0 ? esc($label) : '&nbsp;') . '</small></div>';
        }

        return '<div class="rp-trend" style="--n:' . $n . '"><div class="rp-plot">' . $grid . '<div class="rp-cols">' . $cols . '</div></div></div>'
            . '<ul class="rp-legend rp-legend-row"><li><i class="rp-dot rp-c0"></i><span>Sales</span></li><li><i class="rp-dot rp-c3"></i><span>Expenses</span></li></ul>';
    }

    /**
     * Donut with a legend.
     *
     * @param list<array{label: string, value: float}> $items
     * @param callable(float): string                  $money
     */
    public static function donut(array $items, callable $money, string $centre = 'Total'): string
    {
        $items = array_values(array_filter($items, static fn (array $i): bool => $i['value'] > 0));
        $total = array_sum(array_column($items, 'value'));
        if ($total <= 0) {
            return '<p class="muted rp-empty">No data in this period</p>';
        }
        // keep the chart readable: top 5 + "Other"
        if (count($items) > 6) {
            $rest = array_slice($items, 5);
            $items = array_slice($items, 0, 5);
            $items[] = ['label' => 'Other', 'value' => array_sum(array_column($rest, 'value'))];
        }
        $r = 15.9155; // circumference = 100
        $off = 0.0;
        $svg = '';
        $leg = '';
        foreach ($items as $i => $it) {
            $pc = $it['value'] / $total * 100;
            $svg .= '<circle class="rp-s rp-s' . $i . '" r="' . $r . '" cx="21" cy="21" fill="none" stroke-width="6" stroke-dasharray="' . number_format(max(0, $pc - 0.6), 2, '.', '') . ' ' . number_format(100 - max(0, $pc - 0.6), 2, '.', '') . '" stroke-dashoffset="' . number_format(25 - $off, 2, '.', '') . '"><title>' . esc($it['label']) . ' ' . round($pc) . '%</title></circle>';
            $off += $pc;
            $leg .= '<li><i class="rp-dot rp-c' . $i . '"></i><span>' . esc($it['label']) . '</span><b>' . $money($it['value']) . '</b><small>' . round($pc) . '%</small></li>';
        }

        return '<div class="rp-donut"><div class="rp-ring"><svg viewBox="0 0 42 42" role="img" aria-label="' . esc($centre, 'attr') . ' breakdown"><circle r="' . $r . '" cx="21" cy="21" fill="none" stroke-width="6" class="rp-track"/>' . $svg . '</svg>'
            . '<div class="rp-mid"><small>' . esc($centre) . '</small><b>' . esc(self::compact($total)) . '</b></div></div><ul class="rp-legend">' . $leg . '</ul></div>';
    }

    /**
     * Horizontal bars. $items: label, value, optional note (second figure shown small).
     *
     * @param list<array{label: string, value: float, note?: string}> $items
     * @param callable(float): string                                 $money
     */
    public static function hbars(array $items, callable $money, string $cls = 'rp-c0'): string
    {
        $items = array_values(array_filter($items, static fn (array $i): bool => $i['value'] > 0));
        if ($items === []) {
            return '<p class="muted rp-empty">No data in this period</p>';
        }
        $max = max(array_column($items, 'value'));
        $out = '<ul class="rp-hbars">';
        foreach ($items as $it) {
            $out .= '<li><div class="rp-hl"><span>' . esc($it['label']) . '</span><b>' . $money($it['value']) . '</b></div><div class="rp-ht"><span class="' . $cls . '" style="width:' . number_format($it['value'] / $max * 100, 1, '.', '') . '%"></span></div>'
                . (isset($it['note']) && $it['note'] !== '' ? '<small class="muted">' . esc($it['note']) . '</small>' : '') . '</li>';
        }

        return $out . '</ul>';
    }
}
