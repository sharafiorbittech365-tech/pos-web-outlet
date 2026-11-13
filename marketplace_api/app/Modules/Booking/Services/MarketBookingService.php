<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Buyers\Services\BuyerAuthService;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Shops\Services\ListedShops;
use Config\Market;
use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\ModuleDisabledException;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\ValidationException;

/**
 * Book a service from the marketplace through the core BookingService (source "marketplace"),
 * in the shop's tenant context. Only shops whose "bookings" module is on (ModuleAccess) take
 * bookings; the booking kind follows the shop's business type (appointment, room, pickup, quote …).
 * A one-time form token (made by the website when it shows the form) stops a double submit from
 * booking twice: the result is kept under it and returned again.
 */
final class MarketBookingService
{
    private const CACHE = 'mk_booking_';
    public const DAYS = 7;

    public function __construct(private readonly ListedShops $shops)
    {
    }

    /** @return array<string, mixed>|null */
    public function result(string $token): ?array
    {
        if (! CheckoutService::validToken($token)) {
            return null;
        }
        $r = cache(self::CACHE . $token);

        return is_array($r) ? $r : null;
    }

    /**
     * What the listing page needs for the booking form: days, the chosen day's free slots.
     *
     * @return array<string, mixed>
     */
    public function form(array $item, string $date): array
    {
        $shop = $item['shop'];
        $days = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $t = strtotime('today +' . $i . ' day');
            $days[] = ['value' => date('Y-m-d', $t), 'label' => $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : date('D j', $t))];
        }
        $valid = array_column($days, 'value');
        $date = in_array($date, $valid, true) ? $date : '';
        $slots = null;
        if ($shop['booking_timed'] && $date !== '') {
            $slots = service('tenant')->runAs($shop['tenant_id'], $shop['id'], static fn (): array => service('bookings')->slots($date, (int) $item['id']));
        }

        return ['kind' => $shop['booking_kind'], 'timed' => $shop['booking_timed'], 'days' => $days, 'date' => $date, 'slots' => $slots];
    }

    /** @return array<string, mixed> the booking (reference, starts_at, shop) */
    public function book(int $businessId, int $productId, array $in, ?array $buyer): array
    {
        $token = (string) ($in['token'] ?? '');
        if (! CheckoutService::validToken($token)) {
            throw new ConflictException('This booking form has expired. Please pick the time again.');
        }
        if (($prev = $this->result($token)) !== null) {
            return $prev;
        }
        $shop = $this->shops->getOrFail($businessId);
        if (! $shop['takes_bookings']) {
            throw new ModuleDisabledException($shop['name'] . ' does not take online bookings. Please call the shop.');
        }
        $service = db_connect()->table('products')->select('id, name, duration_min')->where('business_id', $businessId)->where('id', $productId)
            ->where('is_online', 1)->where('is_active', 1)->where('deleted_at', null)->get()->getRowArray()
            ?? throw new NotFoundException('This service is no longer available.');
        $phone = BuyerAuthService::normalisePhone((string) $in['phone']);
        if (! BuyerAuthService::validPhone($phone)) {
            throw new ValidationException('Enter a valid mobile number.', ['phone' => 'Use a mobile number like 01712345678.']);
        }
        $startsAt = null;
        if ($shop['booking_timed']) {
            $startsAt = (string) ($in['starts_at'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $startsAt)) {
                throw new ValidationException('Pick a time.', ['starts_at' => 'Pick a time.']);
            }
        }
        $staff = trim((string) ($in['staff'] ?? ''));
        $row = service('tenant')->runAs($shop['tenant_id'], $shop['id'], function () use ($shop, $service, $in, $phone, $startsAt, $staff, $buyer, $productId): array {
            service('tenant')->set($shop['tenant_id'], $shop['id'], null, 'Marketplace');
            if ($startsAt !== null) {
                // the slot must still be free (BookingService re-checks a named staff member inside its transaction)
                $free = array_column(service('bookings')->slots(substr($startsAt, 0, 10), $productId), 'staff', 'starts_at');
                $key = strlen($startsAt) === 16 ? $startsAt . ':00' : $startsAt;
                if (! isset($free[$key]) || ($staff !== '' && $staff !== 'Any' && ! in_array($staff, $free[$key], true))) {
                    throw new ConflictException('That time was just taken. Please pick another slot.');
                }
            }

            return service('bookings')->create([
                'kind' => $shop['booking_kind'], 'source' => 'marketplace', 'buyer_id' => $buyer['id'] ?? null, 'product_id' => (int) $service['id'],
                'customer_name' => (string) $in['name'], 'customer_phone' => $phone,
                'customer_email' => $buyer['email'] ?? null, 'staff_name' => $staff !== '' ? $staff : null, 'starts_at' => $startsAt,
                'details' => array_filter(['via' => 'marketplace', 'buyer_id' => $buyer['id'] ?? null, 'service' => $service['name'], 'address' => trim((string) ($in['address'] ?? '')) ?: null]),
                'note' => trim('Booked on ' . \OmniPOS\Ui\Brand::name() . ' Market. ' . trim((string) ($in['note'] ?? ''))),
            ]);
        });
        $result = ['reference' => $row['reference'], 'starts_at' => $row['starts_at'], 'kind' => $row['kind'], 'shop' => $shop['name'], 'shop_phone' => $shop['phone'],
            'service' => $service['name'], 'staff' => $row['staff_name'], 'shop_slug' => $shop['site_slug'], 'token' => $token];
        cache()->save(self::CACHE . $token, $result, config(Market::class)->resultDays * 86400);

        return $result;
    }
}
