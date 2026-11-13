<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\BuyerToken;
use App\Modules\Booking\Services\MarketBookingService;
use App\Modules\Buyers\Services\BuyerAuthService;
use App\Modules\Buyers\Services\BuyerHistory;
use App\Modules\Checkout\Services\CartService;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Discovery\Services\DiscoveryService;
use App\Modules\Listing\Services\ListingService;
use App\Modules\Location\Services\LocationStore;
use App\Modules\Shops\Services\ListedShops;
use CodeIgniter\Config\BaseService;

/**
 * Marketplace API services. The shared domain services (orders, bookings, tenant, moduleAccess,
 * loginThrottle …) come from omnipos/core's Config\Services.
 */
class Services extends BaseService
{
    public static function listedShops(bool $getShared = true): ListedShops
    {
        return $getShared ? static::getSharedInstance('listedShops') : new ListedShops();
    }

    public static function marketLocation(bool $getShared = true): LocationStore
    {
        return $getShared ? static::getSharedInstance('marketLocation') : new LocationStore();
    }

    public static function discovery(bool $getShared = true): DiscoveryService
    {
        return $getShared ? static::getSharedInstance('discovery') : new DiscoveryService(static::listedShops());
    }

    public static function listing(bool $getShared = true): ListingService
    {
        return $getShared ? static::getSharedInstance('listing') : new ListingService(static::listedShops());
    }

    public static function cart(bool $getShared = true): CartService
    {
        return $getShared ? static::getSharedInstance('cart') : new CartService(static::listedShops());
    }

    public static function checkout(bool $getShared = true): CheckoutService
    {
        return $getShared ? static::getSharedInstance('checkout') : new CheckoutService(static::listedShops(), static::cart());
    }

    public static function marketBookings(bool $getShared = true): MarketBookingService
    {
        return $getShared ? static::getSharedInstance('marketBookings') : new MarketBookingService(static::listedShops());
    }

    public static function buyers(bool $getShared = true): BuyerAuthService
    {
        return $getShared ? static::getSharedInstance('buyers') : new BuyerAuthService();
    }

    public static function buyerHistory(bool $getShared = true): BuyerHistory
    {
        return $getShared ? static::getSharedInstance('buyerHistory') : new BuyerHistory(static::listedShops());
    }

    /** Signs and checks buyer sign-in tokens (X-Buyer-Token). */
    public static function buyerTokens(bool $getShared = true): BuyerToken
    {
        if ($getShared) {
            return static::getSharedInstance('buyerTokens');
        }
        $cfg = config(Market::class);

        return new BuyerToken($cfg->buyerKey(), $cfg->buyerTokenDays);
    }
}
