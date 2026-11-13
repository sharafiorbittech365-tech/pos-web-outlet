<?php

declare(strict_types=1);

namespace App\Modules\Location\Controllers;

use App\Controllers\MarketController;
use App\Libraries\Geo;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Geo as GeoConfig;
use OmniPOS\Core\Config\OmniPOS;

/** The location picker's data and resolving a chosen location; plus the site-wide facts the website shows. */
final class LocationController extends MarketController
{
    /** GET /v1/market/meta — radius chips, the offline area list, number of listed shops, POS address. */
    public function meta(): ResponseInterface
    {
        $geo = config(GeoConfig::class);
        $areas = [];
        foreach (Geo::cities() as $city) {
            $areas[] = ['city' => $city, 'areas' => array_column(Geo::areasIn($city), 'area')];
        }

        return $this->ok([
            'brand' => \OmniPOS\Ui\Brand::name(),
            'radius_chips' => $geo->radiusChips,
            'default_radius_km' => $geo->defaultRadiusKm,
            'areas' => $areas,
            'shop_count' => count(service('listedShops')->ids()),
            'pos_url' => config(OmniPOS::class)->posUrl,
        ]);
    }

    /**
     * POST /v1/market/location — {lat, lng} (browser geolocation) or {area} (offline list) →
     * the resolved location for the website to keep. A signed-in buyer keeps it on the account.
     */
    public function resolve(): ResponseInterface
    {
        $in = $this->input();
        $store = service('marketLocation');
        if (isset($in['lat']) && $in['lat'] !== '' && $in['lat'] !== null) {
            $v = $this->check($in, [
                'lat' => 'required|decimal|greater_than_equal_to[-90]|less_than_equal_to[90]',
                'lng' => 'required|decimal|greater_than_equal_to[-180]|less_than_equal_to[180]',
            ]);
            $loc = $store->fromCoordinates((float) $v['lat'], (float) $v['lng']);
        } else {
            $v = $this->check($in, ['area' => 'required|max_length[64]']);
            $loc = $store->fromArea((string) $v['area']);
        }
        $buyer = $this->buyer();
        if ($buyer !== null) {
            $store->rememberFor((int) $buyer['id'], $loc);
        }

        return $this->ok(['location' => $loc, 'label' => Geo::label($loc)]);
    }
}
