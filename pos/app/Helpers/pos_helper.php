<?php

declare(strict_types=1);

/*
 * POS view helpers (load with helper('pos')). Same wording as the design build.
 */

if (! function_exists('pay_label')) {
    /** "Cash", "Card", "bKash / Nagad" … */
    function pay_label(?string $method, string $currencyCode = 'BDT'): string
    {
        $m = (string) $method;
        if ($m === 'mobile' && $currencyCode === 'BDT') {
            return 'bKash / Nagad / Rocket';
        }

        return ['cash' => 'Cash', 'card' => 'Card', 'mobile' => 'Mobile money', 'bank' => 'Bank Transfer', 'cheque' => 'Cheque', 'credit' => 'Credit Sale', 'exchange' => 'Exchange credit', 'cod' => 'Cash on delivery'][$m] ?? ucfirst($m);
    }
}

if (! function_exists('pay_icon')) {
    function pay_icon(?string $method): string
    {
        return ['cash' => 'cash', 'card' => 'card', 'mobile' => 'mobile', 'bank' => 'bank', 'cheque' => 'cheque', 'credit' => 'credit', 'cod' => 'truck'][(string) $method] ?? 'cash';
    }
}

if (! function_exists('amount_words')) {
    /** Amount in words, lakh / crore for BDT: "Taka One Lakh Twenty Thousand Only". */
    function amount_words(float $n, string $code = 'BDT'): string
    {
        $a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = static fn (int $x): string => $x < 20 ? $a[$x] : $t[intdiv($x, 10)] . ($x % 10 ? ' ' . $a[$x % 10] : '');
        $three = static fn (int $x): string => $x >= 100 ? $a[intdiv($x, 100)] . ' Hundred' . ($x % 100 ? ' ' . $two($x % 100) : '') : $two($x);
        $intl = $code !== 'BDT';
        $conv = static function (int $x) use (&$conv, $two, $three, $intl): string {
            if ($x === 0) {
                return 'Zero';
            }
            $out = [];
            if ($intl) {
                foreach ([[1000000000, 'Billion'], [1000000, 'Million'], [1000, 'Thousand']] as [$d, $w]) {
                    $q = intdiv($x, $d);
                    if ($q) {
                        $out[] = $three($q) . ' ' . $w;
                        $x %= $d;
                    }
                }
            } else {
                $crore = intdiv($x, 10000000);
                $x %= 10000000;
                $lakh = intdiv($x, 100000);
                $x %= 100000;
                $th = intdiv($x, 1000);
                $x %= 1000;
                if ($crore) {
                    $out[] = $conv($crore) . ' Crore';
                }
                if ($lakh) {
                    $out[] = $two($lakh) . ' Lakh';
                }
                if ($th) {
                    $out[] = $two($th) . ' Thousand';
                }
            }
            if ($x) {
                $out[] = $three($x);
            }

            return implode(' ', $out);
        };
        $words = ['BDT' => ['Taka', 'Paisa'], 'USD' => ['Dollars', 'Cents'], 'VND' => ['Dong', ''], 'SAR' => ['Riyals', 'Halalas']][$code] ?? [$code, 'Cents'];
        $whole = (int) floor(abs($n));
        $frac = (int) round((abs($n) - $whole) * 100);

        return $words[0] . ' ' . $conv($whole) . ($frac && $words[1] ? ' and ' . $two($frac) . ' ' . $words[1] : '') . ' Only';
    }
}

if (! function_exists('pos_home')) {
    /**
     * Landing page for a role's "home" setting — only when the signed-in user can open it
     * (permission, module, feature as in Config\PosNav), otherwise the dashboard.
     */
    function pos_home(?string $home): string
    {
        $path = match ((string) $home) {
            'pos' => 'sell',
            'floor', 'kitchen', 'inventory', 'reports', 'tasks', 'orders', 'customers' => (string) $home,
            default => 'dashboard',
        };
        if ($path === 'dashboard') {
            return $path;
        }
        $s = service('posSession');
        $b = $s->business();
        $features = (array) ($b['settings']['features'] ?? []);
        $features['bookingKind'] = ! empty($b['booking_kind']);
        $features['stockEnabled'] = (bool) ($b['stock_enabled'] ?? false);
        $features['purchasing'] = \App\Modules\Purchases\Services\PurchaseSettings::anyOn($b);
        $features['seats'] = ! empty($features['seats']) && ! empty($b['settings']['seats']);
        foreach (config('PosNav')->items as $i) {
            if ($i['path'] !== $path) {
                continue;
            }
            $ok = ($i['perm'] === null || array_filter(explode('|', $i['perm']), [$s, 'can']) !== []) && ($i['module'] === null || $s->moduleOn($i['module'])) && ($i['feature'] === null || ! empty($features[$i['feature']]));

            return $ok ? $path : 'dashboard';
        }

        return 'dashboard';
    }
}

if (! function_exists('order_status_tag')) {
    /** Order status chip (adds the kitchen / web wording the design build uses). */
    function order_status_tag(array $o): string
    {
        $map = ['open' => ['Open', 'warn'], 'held' => ['On hold', 'warn'], 'paid' => ['Paid', 'ok'], 'partial' => ['Partially paid', 'warn'], 'due' => ['Due', 'danger'],
            'cancelled' => ['Void', ''], 'returned' => ['Refunded', 'danger']];
        [$l, $k] = $map[(string) ($o['status'] ?? '')] ?? [ucfirst((string) ($o['status'] ?? '')), ''];
        $html = tag_html($l, $k);
        if ((float) ($o['returned_total'] ?? 0) > 0 && ($o['status'] ?? '') !== 'returned') {
            $html .= ' ' . tag_html('Part return', '');
        }

        return $html;
    }
}

if (! function_exists('ship_label')) {
    function ship_label(?string $s): string
    {
        return ['ordered' => 'Ordered', 'packed' => 'Packed', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'][(string) $s] ?? ucfirst(str_replace('_', ' ', (string) $s));
    }
}

if (! function_exists('rate_str')) {
    function rate_str(float|string|null $r): string
    {
        return rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
    }
}

if (! function_exists('pos_nav_items')) {
    /**
     * Config\PosNav items the signed-in user can open (permission, module, feature), in the
     * business's own words ("{customers}" → "Patients", "{floor}" → "Rooms" …). Sidebar and mobile tabs.
     *
     * @return list<array{path: string, label: string, icon: string, group: string}>
     */
    function pos_nav_items(): array
    {
        $s = service('posSession');
        $b = $s->business();
        $access = $s->access();
        $features = (array) ($b['settings']['features'] ?? []);
        $features['bookingKind'] = ! empty($b['booking_kind']);
        $features['stockEnabled'] = (bool) ($b['stock_enabled'] ?? false);
        $features['purchasing'] = \App\Modules\Purchases\Services\PurchaseSettings::anyOn($b);
        $features['seats'] = ! empty($features['seats']) && ! empty($b['settings']['seats']);
        $out = [];
        foreach (config('PosNav')->items as $i) {
            if (($i['perm'] === null || array_filter(explode('|', $i['perm']), [$s, 'can']) !== []) && ($i['module'] === null || $access->has($i['module'])) && ($i['feature'] === null || ! empty($features[$i['feature']]))) {
                $out[] = ['path' => $i['path'], 'label' => terms_text($i['label']), 'icon' => $i['icon'], 'group' => $i['group']];
            }
        }

        return $out;
    }
}

if (! function_exists('term')) {
    /** A business word of the current outlet: term('customers') = "Patients" in a clinic. Not escaped. */
    function term(string $key): string
    {
        return service('posSession')->term($key);
    }
}

if (! function_exists('terms_text')) {
    /** Replace "{key}" business words in a text: terms_text('New {order}') = "New Appointment". Not escaped. */
    function terms_text(string $text): string
    {
        return str_contains($text, '{') ? (string) preg_replace_callback('/\{([a-zA-Z]+)\}/', static fn (array $m): string => term($m[1]), $text) : $text;
    }
}

if (! function_exists('pos_theme')) {
    /**
     * The outlet's back-office look (settings.theme: primary, sidebar, radius, font from the business
     * type), as html attributes and CSS custom properties for the layouts.
     *
     * @return array{primary: string, attrs: string, css: string}
     */
    function pos_theme(): array
    {
        $t = (array) (service('posSession')->business()['settings']['theme'] ?? []);
        $primary = (string) ($t['primary'] ?? '#4F46E5');
        $sidebar = ($t['sidebar'] ?? '') === 'dark' ? 'dark' : 'light';
        $css = '--primary:' . css_color($primary) . ';--primary-ink:#fff';
        if (isset($t['radius']) && is_numeric($t['radius'])) {
            $css .= ';--radius:' . max(0, min(24, (int) $t['radius'])) . 'px';
        }
        $fonts = \OmniPOS\Core\Services\BusinessTypeSetup::THEME_FONTS;
        if (isset($t['font'], $fonts[$t['font']])) {
            $css .= ";--font:'" . $fonts[$t['font']] . "', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
        }

        return ['primary' => $primary, 'attrs' => ' data-sidebar="' . $sidebar . '"', 'css' => $css];
    }
}

if (! function_exists('pos_nav_counts')) {
    /**
     * Unread badge counts for the menu (App\Modules\BackOffice\Support\NavCounts), once per request.
     *
     * @return array<string, int> nav path => count
     */
    function pos_nav_counts(): array
    {
        static $counts = null;

        return $counts ??= \App\Modules\BackOffice\Support\NavCounts::forCurrentUser();
    }
}

if (! function_exists('nav_badge')) {
    /** Badge markup kept up to date by assets/pos/badges.js ($cls: count | badge-n). */
    function nav_badge(string $path, string $cls = 'count'): string
    {
        $c = pos_nav_counts();
        if (! array_key_exists($path, $c)) {
            return '';
        }
        $n = (int) $c[$path];

        return '<i class="' . esc($cls, 'attr') . ' nav-n" data-nav-count="' . esc($path, 'attr') . '"' . ($n > 0 ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</i>';
    }
}

if (! function_exists('biz_logo_url')) {
    /** The website logo of an outlet (Setup › Website, sites.brand.logo) as a safe address, or '' when none is set. */
    function biz_logo_url(?array $b = null): string
    {
        static $urls = [];
        $b ??= service('posSession')->business();
        $id = (int) ($b['id'] ?? 0);
        if (! array_key_exists($id, $urls)) {
            $urls[$id] = '';
            try {
                $brand = db_connect()->table('sites')->select('brand')->where('business_id', $id)->get()->getRow('brand');
                $u = trim((string) ((json_decode((string) $brand, true) ?: [])['logo'] ?? ''));
                $urls[$id] = preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $u) === 1 ? $u : '';
                // an image kept in the shared database is served by this app too: no dependency on the API app being up
                if ($urls[$id] !== '' && preg_match('#/media/(\d+-[0-9a-f]{16}\.(?:png|jpe?g|webp|gif|ico))$#i', $urls[$id], $m) === 1) {
                    $urls[$id] = '/media/' . $m[1];
                }
            } catch (\Throwable) {
                // no website record / table: the symbol is used
            }
        }

        return $urls[$id];
    }
}

if (! function_exists('biz_logo')) {
    /**
     * The outlet's logo as inline markup: the website logo when one is set, else the Setup symbol.
     * One logo for the admin panel, the till, the printed documents and the online shop.
     *
     * @param array<string, mixed>|null $b the business (default: the signed-in outlet)
     */
    function biz_logo(?array $b = null, string $class = 'sm'): string
    {
        $b ??= service('posSession')->business();
        $u = biz_logo_url($b);
        if ($u !== '') {
            return '<img class="logo-img" src="' . esc($u, 'attr') . '" alt="" loading="lazy">';
        }

        return sym_icon($b['settings']['logoIcon'] ?? $b['type_icon'] ?? null, $class);
    }
}

if (! function_exists('doc_logo')) {
    /** Logo block of a printed document ($d = OrderDocs::build): the image when there is one, else the symbol; '' when logo printing is off. */
    function doc_logo(array $d, string $symClass = 'lg'): string
    {
        if (isset($d['showLogo']) && ! $d['showLogo']) {
            return '';
        }
        if (! empty($d['logoUrl'])) {
            return '<img class="doc-logo" src="' . esc((string) $d['logoUrl'], 'attr') . '" alt="">';
        }

        return sym_icon($d['logo'] ?? null, $symClass);
    }
}
