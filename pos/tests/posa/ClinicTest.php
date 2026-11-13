<?php

declare(strict_types=1);

use App\Modules\Clinic\Services\AppointmentDesk;
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\DoctorAdmin;
use App\Modules\Clinic\Services\MedicineSearch;
use App\Modules\Clinic\Services\PatientService;
use App\Modules\Clinic\Services\PrescriptionService;
use OmniPOS\Core\Exceptions\BusinessRuleException;
use OmniPOS\Core\Exceptions\ConflictException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use OmniPOS\Core\Exceptions\ValidationException;
use Tests\Support\PosA\TestCase;

/**
 * Clinic module: doctor schedules & availability, double-booking prevention and serial numbers,
 * the visit workflow, access to medical records, prescription revisions, the pad & print page.
 *
 * @internal
 */
final class ClinicTest extends TestCase
{
    private string $day;

    protected function businessType(): string
    {
        return 'clinic';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = date('Y-m-d', strtotime('+2 days'));
    }

    /** A doctor seeing patients on $this->day 10:00–11:00 in 15-minute slots, at most $max patients. */
    private function doctor(?int $max = 3, string $name = 'Dr. Test'): int
    {
        $admin = new DoctorAdmin();
        $id = $admin->save(null, ['name' => $name, 'degrees' => 'MBBS', 'specialty' => 'Medicine', 'reg_no' => 'A-1', 'is_active' => 1, 'is_public' => 1]);
        $admin->saveSchedule($id, [['weekday' => (int) date('w', strtotime($this->day)), 'starts' => '10:00', 'ends' => '11:00', 'slot_minutes' => 15, 'max_patients' => $max === null ? '' : (string) $max]]);

        return $id;
    }

    private function book(int $doctor, string $time, string $phone = '01711000001', string $name = 'Rahim'): array
    {
        return service('clinicSchedule')->book(['doctor_id' => $doctor, 'starts_at' => $this->day . ' ' . $time . ':00', 'source' => 'web', 'online' => true,
            'patient' => ['name' => $name, 'phone' => $phone, 'sex' => 'male', 'age' => 40]]);
    }

    private function role(string $code, array $perms): void
    {
        $db = db_connect();
        $db->table('roles')->insert(['tenant_id' => $this->fx['tenant_id'], 'code' => $code, 'name' => ucfirst($code), 'color' => '#000000', 'icon' => 'user', 'home' => 'dashboard',
            'max_discount' => 0, 'is_locked' => 0, 'permissions' => json_encode($perms), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $rid = (int) $db->insertID();
        $db->table('users')->insert(['tenant_id' => $this->fx['tenant_id'], 'name' => ucfirst($code) . ' user', 'pin_hash' => password_hash('9999', PASSWORD_DEFAULT), 'is_owner' => 0, 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $uid = (int) $db->insertID();
        $db->table('user_businesses')->insert(['user_id' => $uid, 'business_id' => $this->fx['business_id'], 'role_id' => $rid]);
        $this->fx['users'][$code] = $uid;
    }

    public function testSlotsSerialsAndDoubleBookingPrevention(): void
    {
        $doc = $this->doctor(3);
        $slots = service('clinicSchedule')->slots($doc, $this->day);
        $this->assertSame(['10:00', '10:15', '10:30', '10:45'], array_column($slots['slots'], 'time'));
        $this->assertTrue($slots['slots'][0]['free']);

        $a = $this->book($doc, '10:00');
        $this->assertSame(1, $a['serial_no']);
        $this->assertSame('appointment', db_connect()->table('bookings')->select('kind')->where('id', $a['id'])->get()->getRow('kind'));
        // the same slot again: refused
        $this->assertThrowsOmni(ConflictException::class, fn () => $this->book($doc, '10:00', '01711000002', 'Karim'));
        $this->assertSame(2, $this->book($doc, '10:15', '01711000002', 'Karim')['serial_no']);
        $this->assertSame(3, $this->book($doc, '10:30', '01711000003', 'Salma')['serial_no']);
        // the session takes at most 3 patients
        $left = service('clinicSchedule')->slots($doc, $this->day)['slots'];
        $this->assertSame('full', $left[3]['reason']);
        $this->assertThrowsOmni(ConflictException::class, fn () => $this->book($doc, '10:45', '01711000004', 'Nila'));
        // a time that is not one of the doctor's slots
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->book($doc, '10:07', '01711000004', 'Nila'));

        // cancelling frees the slot; serial numbers are never reused
        (new AppointmentDesk())->act((int) $a['id'], 'cancelled');
        $this->assertTrue(service('clinicSchedule')->slots($doc, $this->day)['slots'][0]['free']);
        $this->assertSame(4, $this->book($doc, '10:00', '01711000004', 'Nila')['serial_no']);

        // the same patient (name + phone) is not registered twice
        $this->assertSame(4, db_connect()->table('clinic_patients')->where('business_id', $this->fx['business_id'])->countAllResults());

        // leave closes the day
        (new DoctorAdmin())->addLeave($doc, ['from_date' => $this->day, 'to_date' => $this->day]);
        $this->assertTrue(service('clinicSchedule')->slots($doc, $this->day)['on_leave']);
        $this->assertSame([], service('clinicSchedule')->slots($doc, $this->day)['slots']);
    }

    public function testScheduleValidation(): void
    {
        $doc = $this->doctor();
        $admin = new DoctorAdmin();
        $this->assertThrowsOmni(ValidationException::class, fn () => $admin->saveSchedule($doc, [['weekday' => 1, 'starts' => '12:00', 'ends' => '11:00', 'slot_minutes' => 15]]));
        $this->assertThrowsOmni(ValidationException::class, fn () => $admin->saveSchedule($doc, [
            ['weekday' => 1, 'starts' => '10:00', 'ends' => '12:00', 'slot_minutes' => 15], ['weekday' => 1, 'starts' => '11:00', 'ends' => '13:00', 'slot_minutes' => 15]]));
        // a doctor hidden from the website cannot be booked online
        db_connect()->table('clinic_doctors')->where('id', $doc)->update(['is_public' => 0]);
        $this->assertThrowsOmni(ValidationException::class, fn () => $this->book($doc, '10:00'));
    }

    public function testVisitWorkflowAndCounterBooking(): void
    {
        $doc = $this->doctor(null);
        $desk = new AppointmentDesk();
        $row = $desk->book(['doctor_id' => $doc, 'starts_at' => $this->day . ' 10:30:00', 'patient_name' => 'Walk Patient', 'patient_phone' => '01811000000', 'patient_age' => '30']);
        $this->assertSame(1, $row['serial_no']);
        $bk = static fn (): array => db_connect()->table('bookings')->select('status, visit_stage, source')->where('id', $row['id'])->get()->getRowArray();
        $this->assertSame(['status' => 'confirmed', 'visit_stage' => null, 'source' => 'pos'], $bk());
        $desk->act((int) $row['id'], 'arrived');
        $desk->act((int) $row['id'], 'in_consult');
        $this->assertSame('in_consult', $bk()['visit_stage']);
        $desk->act((int) $row['id'], 'done');
        $this->assertSame('done', $bk()['status']);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $desk->act((int) $row['id'], 'arrived'));

        // a walk-in gets the next serial without a slot
        $walk = $desk->book(['doctor_id' => $doc, 'walk_in' => '1', 'patient_name' => 'Queue', 'patient_phone' => '01811000001']);
        $this->assertSame(date('Y-m-d'), substr((string) $walk['starts_at'], 0, 10));

        // online requests show in the POS Bookings badge (pending, from the web)
        $web = $this->book($doc, '10:00', '01911000000', 'Online');
        $this->assertSame('pending', db_connect()->table('bookings')->select('status')->where('id', $web['id'])->get()->getRow('status'));
        $this->assertGreaterThanOrEqual(1, \App\Modules\BackOffice\Support\NavCounts::forCurrentUser()['bookings'] ?? 0);
        $this->seeInDatabase('activity_log', ['action' => 'Appointment booked']);
    }

    public function testMedicalRecordsNeedPermissionAndAreLogged(): void
    {
        $doc = $this->doctor();
        $p = (new PatientService())->create(['name' => 'Private Patient', 'phone' => '01711999999', 'sex' => 'female', 'age' => '33']);
        $rx = new PrescriptionService();
        $id = $rx->start((int) $p['id'], $doc);
        $rx->save($id, ['diagnosis' => 'TEST-DIAGNOSIS-TEXT', 'items' => [['brand_name' => 'Typed Medicine', 'dose' => 'as typed']]]);
        $rx->finalize($id);

        // owner: record visible, access logged
        $r = $this->getPage('clinic/patients/' . $p['id']);
        $r->assertOK();
        $r->assertSee('TEST-DIAGNOSIS-TEXT');
        $this->seeInDatabase('clinic_access_log', ['patient_id' => $p['id'], 'action' => 'record viewed']);

        // front desk (appointments only): profile yes, record no
        $this->role('desk', ['clinic.appointments']);
        $this->act('desk');
        $r = $this->getPage('clinic/patients/' . $p['id']);
        $r->assertOK();
        $r->assertDontSee('TEST-DIAGNOSIS-TEXT');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => (new PatientService())->record((int) $p['id']));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => (new PrescriptionService())->open($id));
        // the routes refuse too (can:clinic.patients.view)
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/rx/' . $id));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/rx/' . $id . '/print'));

        // a role without any clinic permission cannot open patients at all
        $this->act('cashier');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/patients/' . $p['id']));
    }

    public function testPrescriptionRevisionsAreLocked(): void
    {
        $doc = $this->doctor();
        $p = (new PatientService())->create(['name' => 'Rev Patient', 'phone' => '01711888888']);
        $svc = new PrescriptionService();
        $id = $svc->start((int) $p['id'], $doc);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $svc->finalize($id)); // empty

        (new ClinicConfig())->addTests('lab', "CBC test\nOther lab");
        $test = (int) db_connect()->table('clinic_tests')->select('id')->where('business_id', $this->fx['business_id'])->where('name', 'CBC test')->get()->getRow('id');
        $svc->save($id, [
            'chief_complaints' => 'typed by clinician', 'diagnosis' => 'first', 'vitals' => ['bp' => '120/80'],
            'items' => [['medicine_id' => '999999999', 'brand_name' => 'Free Typed', 'generic' => 'gen', 'dose' => '1', 'frequency' => 'f', 'duration' => 'd', 'route' => 'oral', 'instructions' => 'i'],
                ['brand_name' => '', 'dose' => '']],
            'tests' => [$test], 'extra_tests' => [['name' => 'Typed X-ray', 'category' => 'imaging']], 'follow_up' => date('Y-m-d', strtotime('+7 days')),
        ]);
        $full = $svc->find($id);
        $this->assertCount(1, $full['items']);
        $this->assertNull($full['items'][0]['medicine_id'], 'unknown medicine ids are not kept');
        $this->assertSame('Free Typed', $full['items'][0]['brand_name']);
        $this->assertSame(['CBC test', 'Typed X-ray'], array_column($full['tests'], 'name'));
        $this->assertThrowsOmni(ValidationException::class, fn () => $svc->save($id, ['items' => [['dose' => 'no name']]]));

        $svc->finalize($id);
        $this->assertThrowsOmni(BusinessRuleException::class, fn () => $svc->save($id, ['diagnosis' => 'changed']));
        $this->assertThrowsOmni(ValidationException::class, fn () => $svc->revise($id, ''));

        $rev = $svc->revise($id, 'dose corrected');
        $this->assertSame($rev, $svc->revise($id, 'again'), 'one open revision at a time');
        $new = $svc->find($rev);
        $this->assertSame(2, (int) $new['revision']);
        $this->assertSame($full['rx_no'], $new['rx_no']);
        $this->assertSame('draft', $new['status']);
        $this->assertCount(1, $new['items']);
        $this->assertSame('final', $svc->find($id)['status'], 'the final revision stays until the new one is final');
        $svc->save($rev, ['diagnosis' => 'second', 'items' => [['brand_name' => 'Free Typed', 'dose' => '2']]]);
        $svc->finalize($rev);
        $this->assertSame('superseded', $svc->find($id)['status']);
        $this->assertSame('first', $svc->find($id)['diagnosis'], 'old revisions are never changed');
        $this->assertSame('final', $svc->find($rev)['status']);
        $this->seeInDatabase('activity_log', ['action' => 'Prescription revised']);
        $this->seeInDatabase('clinic_access_log', ['prescription_id' => $rev, 'action' => 'prescription finalized']);

        // a doctor's own sign-in only prescribes as that doctor
        $other = $this->doctor(3, 'Dr. Other');
        $this->role('doctor', ['clinic.appointments', 'clinic.patients.view', 'clinic.prescribe']);
        db_connect()->table('clinic_doctors')->where('id', $doc)->update(['user_id' => $this->fx['users']['doctor']]);
        $this->act('doctor');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => (new PrescriptionService())->start((int) $p['id'], $other));
        $this->assertGreaterThan(0, (new PrescriptionService())->start((int) $p['id'], $doc));
    }

    public function testMedicineSearchDegradesGracefully(): void
    {
        MedicineSearch::reset();
        $this->assertIsArray(MedicineSearch::search('pa'));
        $this->assertSame([], MedicineSearch::search('p'));
        $this->assertNull(MedicineSearch::find(0));
        if (! MedicineSearch::available()) {
            return; // no medicines table on this platform yet: search is simply empty
        }
        // a test-only row in the platform list: picked on a line, its details come from the list
        $u = bin2hex(random_bytes(4));
        db_connect()->table('medicines')->insert(['brand_id' => 'test-' . $u, 'brand_name' => 'Zzqtest ' . $u, 'slug' => 'zzqtest-' . $u, 'generic' => 'Test generic', 'strength' => '1 mg',
            'dosage_form' => 'Tablet', 'manufacturer' => 'Test Maker', 'is_active' => 1]);
        $mid = (int) db_connect()->insertID();
        $this->assertSame([$mid], array_column(MedicineSearch::search('Zzqtest ' . $u), 'id'));
        $doc = $this->doctor();
        $p = (new PatientService())->create(['name' => 'Med Patient', 'phone' => '01711777777']);
        $svc = new PrescriptionService();
        $id = $svc->start((int) $p['id'], $doc);
        $svc->save($id, ['items' => [['medicine_id' => (string) $mid, 'brand_name' => 'typed over', 'generic' => 'wrong', 'dose' => '1 typed']]]);
        $it = $svc->find($id)['items'][0];
        $this->assertSame([$mid, 'Zzqtest ' . $u, 'Test generic', 'Tablet', '1 typed'], [(int) $it['medicine_id'], $it['brand_name'], $it['generic'], $it['dosage_form'], $it['dose']]);
        db_connect()->table('medicines')->where('id', $mid)->delete();
        $this->assertSame('Zzqtest ' . $u, $svc->find($id)['items'][0]['brand_name'], 'the line keeps its text when the list changes');
    }

    public function testPagesPadAndPrint(): void
    {
        $doc = $this->doctor();
        foreach (['clinic/appointments', 'clinic/patients', 'clinic/doctors', 'clinic/doctors/' . $doc, 'clinic/setup', 'clinic/setup?doctor=' . $doc] as $uri) {
            $this->getPage($uri)->assertOK();
        }
        $r = $this->postForm('clinic/appointments', ['doctor_id' => $doc, 'starts_at' => $this->day . ' 10:15:00', 'patient_name' => 'Form Patient', 'patient_phone' => '01711222333']);
        $r->assertRedirect();
        $bk = db_connect()->table('bookings')->where('business_id', $this->fx['business_id'])->where('doctor_id', $doc)->get()->getRowArray();
        $this->assertSame(1, (int) $bk['serial_no']);

        $this->postForm('clinic/rx', ['patient_id' => $bk['patient_id'], 'doctor_id' => $doc, 'booking_id' => $bk['id']])->assertRedirect();
        $rxId = (int) db_connect()->table('clinic_prescriptions')->select('id')->where('booking_id', $bk['id'])->get()->getRow('id');
        $this->assertSame('in_consult', db_connect()->table('bookings')->select('visit_stage')->where('id', $bk['id'])->get()->getRow('visit_stage'));
        $r = $this->getPage('clinic/rx/' . $rxId);
        $r->assertOK();
        $r->assertSee('Finalize');
        $this->postForm('clinic/rx/' . $rxId, ['advice' => 'Typed advice', 'items' => [['brand_name' => 'Med A', 'dose' => '1']], 'finalize' => '1'])->assertRedirect();
        $this->assertSame('final', db_connect()->table('clinic_prescriptions')->select('status')->where('id', $rxId)->get()->getRow('status'));

        // the queue, the patient list and the locked prescription render with data
        $q = $this->getPage('clinic/appointments?date=' . $this->day);
        $q->assertOK();
        $q->assertSee('Form Patient');
        $q->assertSee('Prescription');
        $this->getPage('clinic/patients')->assertSee('Form Patient');
        $locked = $this->getPage('clinic/rx/' . $rxId);
        $locked->assertSee('Start a new revision');
        $locked->assertDontSee('name="finalize"');
        $this->getPage('clinic/patients/search?q=Form')->assertSee('Form Patient');
        $this->getPage('clinic/slots?doctor=' . $doc . '&date=' . $this->day)->assertSee('10:15');

        $print = $this->getPage('clinic/rx/' . $rxId . '/print');
        $print->assertOK();
        $print->assertSee('size: A4');
        $print->assertSee('Med A');

        // a doctor's own pad: A5, its margins
        (new ClinicConfig())->savePad($doc, ['paper' => 'a5', 'header_mode' => 'text', 'doctor_block' => 'right', 'font_size' => '10', 'margin_top' => '30', 'margin_right' => '10', 'margin_bottom' => '10', 'margin_left' => '10', 'show_signature' => '1']);
        $print = $this->getPage('clinic/rx/' . $rxId . '/print');
        $print->assertSee('size: A5');
        $print->assertSee('margin: 30mm 10mm 10mm 10mm');
        $this->assertThrowsOmni(ValidationException::class, fn () => (new ClinicConfig())->savePad(0, ['margin_top' => '99']));

        // pick lists are the clinic's own (empty until added)
        $cfg = new ClinicConfig();
        $this->assertSame([], $cfg->lists()['dose']);
        $this->assertSame(2, $cfg->addListValues('dose', "1 tablet\n1 tablet\n2 tablets"));
        $this->assertThrowsOmni(ValidationException::class, fn () => $cfg->addListValues('dose', '1 tablet'));
    }
}
