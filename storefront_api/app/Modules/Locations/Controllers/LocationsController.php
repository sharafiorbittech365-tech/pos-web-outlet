<?php

declare(strict_types=1);

namespace App\Modules\Locations\Controllers;

use App\Controllers\ApiController;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\LocationCatalog;

/**
 * GET /v1/locations/countries, /v1/locations/cities?country=BD, /v1/locations/areas?city=12
 *
 * The address catalogue (Country -> City/District -> Area/Thana) for checkout pickers. Only active
 * entries; served from the cache (LocationCatalog::CACHE_TTL) with an ETag and a public max-age.
 */
final class LocationsController extends ApiController
{
    private const MAX_AGE = 3600;

    public function countries(): ResponseInterface
    {
        return $this->cached((new LocationCatalog())->countries(), [], self::MAX_AGE);
    }

    public function cities(): ResponseInterface
    {
        $country = trim((string) ($this->request->getGet('country') ?? ''));
        if ($country === '') {
            throw new ValidationException('The country is required.', ['country' => 'Add ?country=<2-letter code>.']);
        }

        return $this->cached((new LocationCatalog())->cities($country), [], self::MAX_AGE);
    }

    public function areas(): ResponseInterface
    {
        $city = trim((string) ($this->request->getGet('city') ?? ''));
        if (! ctype_digit($city) || strlen($city) > 10) {
            throw new ValidationException('The city is required.', ['city' => 'Add ?city=<city id>.']);
        }

        return $this->cached((new LocationCatalog())->areas((int) $city), [], self::MAX_AGE);
    }
}
