<?php

declare(strict_types=1);

namespace App\Modules\Buyers\Models;

use OmniPOS\Core\Models\Generated\MarketplaceBuyersModel;

/** Marketplace buyer accounts (platform-level table: no tenant scope). */
class BuyersModel extends MarketplaceBuyersModel
{
    /** @return array<string, mixed>|null */
    public function byPhone(string $phone): ?array
    {
        $row = $this->where('phone', $phone)->first();

        return is_array($row) ? $row : null;
    }
}
