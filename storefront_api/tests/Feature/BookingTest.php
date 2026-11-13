<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * @internal
 */
final class BookingTest extends ApiTestCase
{
    private function at(string $time): string
    {
        return date('Y-m-d', strtotime('+2 days')) . 'T' . $time . ':00' . date('P');
    }

    private function booking(Fixture $f, string $time, ?string $staff = null): array
    {
        return ['kind' => 'appointment', 'customer' => ['name' => 'Nadia', 'phone' => '01911000000'], 'product_id' => $f->products['repair'],
            'staff' => $staff, 'starts_at' => $this->at($time), 'note' => 'Cracked screen'];
    }

    public function testSlots(): void
    {
        $f = Fixture::create();
        $r = $this->storefront($f, 'GET', 'v1/bookings/slots?date=' . date('Y-m-d', strtotime('+2 days')) . '&product_id=' . $f->products['repair']);
        $r->assertStatus(200);
        $b = $this->body($r);
        $this->assertSame(['Rahim', 'Karim'], $b['meta']['staff']);
        $this->assertSame(60, $b['meta']['duration_min']);
        $this->assertSame('10:00', $b['data'][0]['time']);
        $this->assertSame(['Rahim', 'Karim'], $b['data'][0]['staff']);
        $this->assertSame('17:00', end($b['data'])['time']);   // a 60-minute service must end by 18:00
    }

    public function testSlotClashIs409(): void
    {
        $f = Fixture::create();
        $first = $this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '11:00', 'Rahim'));
        $first->assertStatus(201);
        $d = $this->body($first)['data'];
        $this->assertSame('pending', $d['status']);
        $this->assertSame('Rahim', $d['staff']);
        $this->assertMatchesRegularExpression('/^BK[0-9A-F]{6}$/', $d['reference']);
        $this->assertSame(strtotime($this->at('12:00')), strtotime($d['ends_at']));

        // same staff, overlapping time
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '11:30', 'Rahim')), 409, 'slot_taken');
        // "any" staff takes the next free member, then the slot is full
        $any = $this->body($this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '11:00')))['data'];
        $this->assertSame('Karim', $any['staff']);
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '11:00')), 409, 'slot_taken');

        // tracking by reference
        $t = $this->body($this->storefront($f, 'GET', 'v1/orders/' . $d['reference']))['data'];
        $this->assertSame('booking', $t['type']);
        $this->assertSame('pending', $t['status']);
    }

    public function testValidationAndIdempotency(): void
    {
        $f = Fixture::create();
        $bad = $this->storefront($f, 'POST', 'v1/bookings', ['kind' => 'appointment', 'customer' => ['name' => '', 'phone' => '12'], 'starts_at' => $this->at('11:00')]);
        $this->assertError($bad, 422, 'validation_failed');
        $this->assertArrayHasKey('customer.phone', $this->body($bad)['error']['details']);

        $h = ['Idempotency-Key' => 'bk-' . bin2hex(random_bytes(4))];
        $a = $this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '14:00', 'Karim'), $h);
        $b = $this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '14:00', 'Karim'), $h);
        $this->assertSame($this->body($a)['data']['reference'], $this->body($b)['data']['reference']);
        $this->assertSame(1, db_connect()->table('bookings')->where('business_id', $f->businessId)->countAllResults());
    }

    public function testBookingsModuleRequired(): void
    {
        $f = Fixture::create();
        $f->setModule('bookings', false);
        $this->assertError($this->storefront($f, 'GET', 'v1/bookings/slots?date=' . date('Y-m-d', strtotime('+2 days'))), 403, 'module_disabled');
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->booking($f, '11:00', 'Rahim')), 403, 'module_disabled');
        $this->assertFalse($this->body($this->storefront($f, 'GET', 'v1/storefront'))['data']['site']['bookings_enabled']);
    }
}
