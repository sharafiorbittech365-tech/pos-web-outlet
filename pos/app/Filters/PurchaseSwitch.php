<?php

declare(strict_types=1);

namespace App\Filters;

use App\Modules\Purchases\Services\PurchaseSettings;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ModuleDisabledException;

/**
 * A Suppliers & Purchases switch (Setup › Purchasing) must be on: ['filter' => 'pursw:purchase'].
 * "any" = suppliers or purchases.
 */
class PurchaseSwitch implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        foreach ((array) $arguments as $key) {
            $key = (string) $key;
            $ok = $key === 'any' ? PurchaseSettings::anyOn() : PurchaseSettings::on($key);
            if (! $ok) {
                $label = $key === 'any' ? 'Suppliers & purchases' : (PurchaseSettings::SWITCHES[$key][0] ?? $key);
                throw new ModuleDisabledException($label . ' is switched off. Turn it on in Setup › Purchasing.');
            }
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
