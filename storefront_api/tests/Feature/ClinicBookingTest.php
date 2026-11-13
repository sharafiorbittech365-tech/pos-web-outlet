<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\ApiTestCase;
use Tests\Support\Fixture;

/**
 * Clinic sites: GET /v1/doctors, GET /v1/doctors/{id}/slots and POST /v1/bookings with a doctor
 * (slot re-checked on the server, serial numbers, patient profile, limits).
 *
 * @internal
 */
final class ClinicBookingTest extends ApiTestCase
{
    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = date('Y-m-d', strtotime('+3 days'));
    }

    private function clinic(): Fixture
    {
        return Fixture::create(['type' => 'clinic']);
    }

    /** A doctor seeing patients on $this->day 17:00–18:00, 20-minute slots. */
    private function doctor(Fixture $f, bool $public = true, string $name = 'Dr. Web'): int
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('clinic_doctors')->insert(['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'name' => $name, 'degrees' => 'MBBS', 'specialty' => 'Medicine',
            'reg_no' => 'typed-1', 'fee' => 500, 'is_active' => 1, 'is_public' => $public ? 1 : 0, 'created_at' => $now]);
        $id = (int) $db->insertID();
        $db->table('clinic_schedules')->insert(['tenant_id' => $f->tenantId, 'business_id' => $f->businessId, 'doctor_id' => $id, 'weekday' => (int) date('w', strtotime($this->day)),
            'starts' => '17:00:00', 'ends' => '18:00:00', 'slot_minutes' => 20, 'max_patients' => null, 'is_active' => 1]);

        return $id;
    }

    private function at(string $time): string
    {
        return $this->day . 'T' . $time . ':00' . date('P');
    }

    private function appt(int $doctor, string $time, string $phone = '01711000000', string $name = 'Nadia'): array
    {
        return ['doctor_id' => $doctor, 'starts_at' => $this->at($time), 'patient' => ['name' => $name, 'phone' => $phone, 'sex' => 'female', 'age' => 29], 'note' => 'first visit'];
    }

    public function testDoctorsAndSlots(): void
    {
        $f = $this->clinic();
        $doc = $this->doctor($f);
        $this->doctor($f, false, 'Dr. Hidden');

        $r = $this->storefront($f, 'GET', 'v1/doctors');
        $r->assertStatus(200);
        $list = $this->body($r)['data'];
        $this->assertSame(['Dr. Web'], array_column($list, 'name'), 'only doctors marked for the website');
        $this->assertSame('typed-1', $list[0]['registration_no']);
        $this->assertSame('17:00', $list[0]['schedule'][0]['from']);

        $one = $this->body($this->storefront($f, 'GET', 'v1/doctors/' . $doc))['data'];
        $this->assertContains($this->day, $one['open_dates']);

        $s = $this->body($this->storefront($f, 'GET', 'v1/doctors/' . $doc . '/slots?date=' . $this->day));
        $this->assertSame(['17:00', '17:20', '17:40'], array_column($s['data'], 'time'));
        $this->assertSame(3, $s['meta']['free']);
        $this->assertError($this->storefront($f, 'GET', 'v1/doctors/' . $doc . '/slots?date=nope'), 422, 'validation_failed');

        // another clinic's doctor is not found here
        $other = $this->clinic();
        $this->assertError($this->storefront($other, 'GET', 'v1/doctors/' . $doc), 404, 'not_found');

        // a shop that is not a clinic, or the module switched off
        $shop = Fixture::create();
        $this->assertError($this->storefront($shop, 'GET', 'v1/doctors'), 403, 'module_disabled');
        $f->setModule('clinic', false);
        $this->assertError($this->storefront($f, 'GET', 'v1/doctors'), 403, 'module_disabled');
    }

    public function testBookingIsCheckedOnTheServer(): void
    {
        $f = $this->clinic();
        $doc = $this->doctor($f);
        $r = $this->storefront($f, 'POST', 'v1/bookings', $this->appt($doc, '17:20'));
        $r->assertStatus(201);
        $d = $this->body($r)['data'];
        $this->assertSame(1, $d['serial_no']);
        $this->assertSame('pending', $d['status']);
        $this->assertSame('Dr. Web', $d['doctor']['name']);
        $this->assertSame(strtotime($this->at('17:40')), strtotime($d['ends_at']));
        $bk = db_connect()->table('bookings')->where('reference', $d['reference'])->get()->getRowArray();
        $this->assertSame($doc, (int) $bk['doctor_id']);
        $this->assertSame('web', $bk['source']);
        $this->assertSame('Nadia', db_connect()->table('clinic_patients')->select('name')->where('id', $bk['patient_id'])->get()->getRow('name'));

        // the same slot: 409; a time that is not a slot: 422; the slot list no longer offers it
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->appt($doc, '17:20', '01811000000', 'Karim')), 409, 'slot_taken');
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->appt($doc, '17:10', '01811000000', 'Karim')), 422, 'validation_failed');
        $this->assertNotContains('17:20', array_column($this->body($this->storefront($f, 'GET', 'v1/doctors/' . $doc . '/slots?date=' . $this->day))['data'], 'time'));
        $this->assertSame(2, $this->body($this->storefront($f, 'POST', 'v1/bookings', $this->appt($doc, '17:00', '01811000000', 'Karim')))['data']['serial_no']);

        // a doctor hidden from the website, a doctor of another clinic, a missing doctor / patient
        $hidden = $this->doctor($f, false, 'Dr. Hidden');
        $this->assertError($this->storefront($f, 'POST', 'v1/bookings', $this->appt($hidden, '17:00')), 422, 'validation_failed');
        $other = $this->clinic();
        $this->assertError($this->storefront($other, 'POST', 'v1/bookings', $this->appt($doc, '17:40')), 422, 'validation_failed');
        $r = $this->storefront($f, 'POST', 'v1/bookings', ['kind' => 'appointment', 'starts_at' => $this->at('17:40'), 'customer' => ['name' => 'X', 'phone' => '01711000009']]);
        $this->assertError($r, 422, 'validation_failed');
        $this->assertArrayHasKey('doctor_id', $this->body($r)['error']['details']);
        $r = $this->storefront($f, 'POST', 'v1/bookings', ['doctor_id' => $doc, 'starts_at' => $this->at('17:40'), 'patient' => ['name' => '']]);
        $this->assertArrayHasKey('patient.phone', $this->body($r)['error']['details']);
    }

    public function testIdempotentAndRateLimited(): void
    {
        $f = $this->clinic();
        $doc = $this->doctor($f);
        $body = $this->appt($doc, '17:00', '01911000000', 'Same');
        $first = $this->storefront($f, 'POST', 'v1/bookings', $body, ['Idempotency-Key' => 'clinic-key-1']);
        $first->assertStatus(201);
        $again = $this->storefront($f, 'POST', 'v1/bookings', $body, ['Idempotency-Key' => 'clinic-key-1']);
        $this->assertSame('true', $again->response()->getHeaderLine('Idempotent-Replayed'));
        $this->assertSame(1, db_connect()->table('bookings')->where('business_id', $f->businessId)->countAllResults());

        // one phone number: at most 5 appointment requests an hour per clinic
        $codes = [];
        foreach (['17:20', '17:40', '17:20', '17:40', '17:20'] as $t) {
            $codes[] = $this->storefront($f, 'POST', 'v1/bookings', $this->appt($doc, $t, '01911000000', 'Same'))->response()->getStatusCode();
        }
        $this->assertSame(429, end($codes), implode(',', $codes));
    }
}
