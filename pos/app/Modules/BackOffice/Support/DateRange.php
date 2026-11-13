<?php

declare(strict_types=1);

namespace App\Modules\BackOffice\Support;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * Report / list period from the query string: ?range=today|yesterday|7d|month|lastmonth|30d|custom&from=Y-m-d&to=Y-m-d
 * from is inclusive, to is exclusive (both 'Y-m-d H:i:s'), so every query is "col >= from AND col < to"
 * on an indexed datetime column.
 */
final class DateRange
{
    public const RANGES = ['today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', 'month' => 'This month', 'lastmonth' => 'Last month', '30d' => 'Last 30 days', 'custom' => 'Custom'];

    private function __construct(public readonly string $key, public readonly string $from, public readonly string $to)
    {
    }

    public static function fromRequest(IncomingRequest $request, string $default = 'today'): self
    {
        $key = (string) ($request->getGet('range') ?? $default);

        return self::make(array_key_exists($key, self::RANGES) ? $key : $default, (string) ($request->getGet('from') ?? ''), (string) ($request->getGet('to') ?? ''));
    }

    public static function make(string $key, string $from = '', string $to = ''): self
    {
        $d0 = strtotime('today');
        $fmt = static fn (int $t): string => date('Y-m-d H:i:s', $t);
        $tomorrow = strtotime('tomorrow');

        return match ($key) {
            'yesterday' => new self($key, $fmt(strtotime('yesterday')), $fmt($d0)),
            '7d' => new self($key, $fmt(strtotime('-6 days', $d0)), $fmt($tomorrow)),
            '30d' => new self($key, $fmt(strtotime('-29 days', $d0)), $fmt($tomorrow)),
            'month' => new self($key, date('Y-m-01 00:00:00'), $fmt($tomorrow)),
            'lastmonth' => new self($key, date('Y-m-01 00:00:00', strtotime('first day of last month')), date('Y-m-01 00:00:00')),
            'custom' => self::custom($from, $to),
            default => new self('today', $fmt($d0), $fmt($tomorrow)),
        };
    }

    private static function custom(string $from, string $to): self
    {
        $ok = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $f = $ok($from) ? $from : date('Y-m-d');
        $t = $ok($to) ? $to : $f;
        if ($t < $f) {
            [$f, $t] = [$t, $f];
        }
        // at most two years in one report
        if (strtotime($t) - strtotime($f) > 731 * 86400) {
            $f = date('Y-m-d', strtotime($t . ' -731 days'));
        }

        return new self('custom', $f . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($t . ' +1 day')));
    }

    public function label(): string
    {
        if ($this->key === 'custom') {
            return date('d M Y', strtotime($this->from)) . ' – ' . date('d M Y', strtotime($this->to) - 1);
        }

        return self::RANGES[$this->key];
    }

    /** Date inputs for the custom range (to shown inclusive). */
    public function fromDate(): string
    {
        return substr($this->from, 0, 10);
    }

    public function toDate(): string
    {
        return date('Y-m-d', strtotime($this->to) - 1);
    }

    /** @return array<string, string> query params that reproduce this range */
    public function params(): array
    {
        return $this->key === 'custom' ? ['range' => 'custom', 'from' => $this->fromDate(), 'to' => $this->toDate()] : ['range' => $this->key];
    }
}
