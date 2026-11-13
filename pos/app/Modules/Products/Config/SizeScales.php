<?php

declare(strict_types=1);

namespace App\Modules\Products\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Size scales and colour suggestions for the size x colour option generator (product form).
 * Configuration, not data: change or extend the lists here (or per server in .env, e.g.
 * sizeScales.colours) — nothing is stored per product until a person generates its options.
 * The outlet's business-type template may pick the default scale (settings.features.sizeScale).
 */
class SizeScales extends BaseConfig
{
    /** @var array<string, array{label: string, sizes: string}> sizes as typed in the generator (ranges allowed) */
    public array $scales = [
        'eu' => ['label' => 'EU adult (36–45)', 'sizes' => '36-45'],
        'eu_women' => ['label' => 'EU women (35–41)', 'sizes' => '35-41'],
        'eu_men' => ['label' => 'EU men (39–46)', 'sizes' => '39-46'],
        'eu_kids' => ['label' => 'EU kids (24–35)', 'sizes' => '24-35'],
        'uk' => ['label' => 'UK (3–11)', 'sizes' => '3-11'],
        'us_men' => ['label' => 'US men (6–12)', 'sizes' => '6-12'],
        'us_women' => ['label' => 'US women (5–10)', 'sizes' => '5-10'],
        'apparel' => ['label' => 'Clothing (XS–XXL)', 'sizes' => 'XS, S, M, L, XL, XXL'],
    ];

    /** Colour chips offered in the generator (free text is always allowed). */
    public string $colours = 'Black, Brown, Tan, White, Navy, Grey, Red, Blue, Golden';
}
