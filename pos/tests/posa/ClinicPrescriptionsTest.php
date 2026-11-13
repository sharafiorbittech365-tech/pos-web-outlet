<?php

declare(strict_types=1);

use App\Modules\Clinic\Services\DoctorAdmin;
use App\Modules\Clinic\Services\PatientService;
use App\Modules\Clinic\Services\PrescriptionService;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;
use Tests\Support\PosA\TestCase;

/**
 * Clinic › Prescriptions: the menu item, the list, "New prescription" (quick-add patient), the pad
 * editor (paper / design, copy medicines from an earlier visit), finalize → revise, print, access.
 *
 * @internal
 */
final class ClinicPrescriptionsTest extends TestCase
{
    protected function businessType(): string
    {
        return 'clinic';
    }

    private function doctor(string $name = 'Dr. Pad'): int
    {
        $id = (new DoctorAdmin())->save(null, ['name' => $name, 'degrees' => 'MBBS, FCPS', 'specialty' => 'Medicine', 'reg_no' => 'A-7', 'is_active' => 1, 'is_public' => 1]);
        (new DoctorAdmin())->saveSchedule($id, [['weekday' => 6, 'starts' => '17:00', 'ends' => '21:00', 'slot_minutes' => 15, 'max_patients' => '']]);

        return $id;
    }

    private function role(string $code, array $perms): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('roles')->insert(['tenant_id' => $this->fx['tenant_id'], 'code' => $code, 'name' => ucfirst($code), 'color' => '#000000', 'icon' => 'user', 'home' => 'dashboard',
            'max_discount' => 0, 'is_locked' => 0, 'permissions' => json_encode($perms), 'created_at' => $now, 'updated_at' => $now]);
        $rid = (int) $db->insertID();
        $db->table('users')->insert(['tenant_id' => $this->fx['tenant_id'], 'name' => ucfirst($code) . ' user', 'pin_hash' => password_hash('9999', PASSWORD_DEFAULT), 'is_owner' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $uid = (int) $db->insertID();
        $db->table('user_businesses')->insert(['user_id' => $uid, 'business_id' => $this->fx['business_id'], 'role_id' => $rid]);
        $this->fx['users'][$code] = $uid;
    }

    /** @return list<string> */
    private function menu(): array
    {
        return array_column(pos_nav_items(), 'path');
    }

    public function testMenuItemFollowsPermissionsAndBusinessType(): void
    {
        $this->assertContains('clinic/prescriptions', $this->menu());
        $this->role('desk', ['clinic.appointments']);
        $this->role('writer', ['clinic.prescribe']);
        $this->role('reader', ['clinic.patients.view']);
        $this->act('desk');
        $this->assertNotContains('clinic/prescriptions', $this->menu());
        $this->assertContains('clinic/appointments', $this->menu());
        $this->act('writer');
        $this->assertContains('clinic/prescriptions', $this->menu());
        $this->act('reader');
        $this->assertContains('clinic/prescriptions', $this->menu());
        $this->act('desk');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/prescriptions'));
    }

    public function testNotOnOtherBusinessTypes(): void
    {
        $this->fx = \Tests\Support\PosA\Fixture::workspace('grocery');
        $this->act('owner');
        $this->assertNotContains('clinic/prescriptions', $this->menu());
    }

    public function testNewListEditFinalizeReviseAndPrint(): void
    {
        $doc = $this->doctor();
        $this->getPage('clinic/prescriptions/new')->assertOK();

        // quick-add a patient and open the pad
        $r = $this->postForm('clinic/rx', ['doctor_id' => $doc, 'patient_name' => 'Quick Patient', 'patient_phone' => '01711333444', 'patient_age' => '41', 'patient_sex' => 'male']);
        $r->assertRedirect();
        $rx = db_connect()->table('clinic_prescriptions')->where('business_id', $this->fx['business_id'])->orderBy('id', 'desc')->get()->getRowArray();
        $this->assertSame('draft', $rx['status']);
        $id = (int) $rx['id'];
        $this->seeInDatabase('clinic_patients', ['business_id' => $this->fx['business_id'], 'name' => 'Quick Patient']);

        $page = $this->getPage('clinic/rx/' . $id);
        $page->assertOK();
        $page->assertSee('rxp-edit');
        $page->assertSee('data-medadd');
        $page->assertSee('Finalize &amp; print');
        $page->assertSee('Sat · 5:00 PM – 9:00 PM');

        // the pad is the form: lines in the order posted, paper & design for this prescription, Bangla text
        $this->postForm('clinic/rx/' . $id, [
            'pt' => ['name' => 'Quick Patient', 'age' => '41', 'sex' => 'male'], 'vitals' => ['bp' => '130/85', 'weight' => '70 kg'],
            'diagnosis' => 'Typed diagnosis', 'advice' => 'প্রচুর পানি পান করুন',
            'items' => ['n1' => ['brand_name' => 'Second Med', 'dose' => '1+0+1'], 'n0' => ['brand_name' => 'First Med', 'dose' => '0+0+1', 'route' => 'oral']],
            'paper' => 'a5', 'style' => 'modern', 'action' => 'draft',
        ])->assertRedirect();
        $full = (new PrescriptionService())->find($id);
        $this->assertSame(['Second Med', 'First Med'], array_column($full['items'], 'brand_name'));
        $this->assertSame(['a5', 'modern'], [$full['paper'], $full['style']]);

        $list = $this->getPage('clinic/prescriptions?status=draft');
        $list->assertOK();
        $list->assertSee('Quick Patient');
        $this->getPage('clinic/prescriptions?status=final')->assertDontSee('Quick Patient');

        $print = $this->getPage('clinic/rx/' . $id . '/print');
        $print->assertSee('size: A5');
        $print->assertSee('rxp-modern');
        $print->assertSee('প্রচুর পানি পান করুন');
        $print->assertSee('Noto+Sans+Bengali');
        $print->assertSee('BP 130/85');
        $print->assertSee('DRAFT');
        $this->getPage('clinic/rx/' . $id . '/print?paper=a4')->assertSee('size: A4');

        // finalize from the pad, then the locked view and a revision
        $this->postForm('clinic/rx/' . $id, ['diagnosis' => 'Typed diagnosis', 'items' => [['brand_name' => 'First Med']], 'paper' => 'a5', 'style' => 'modern', 'action' => 'finalize'])->assertRedirect();
        $this->assertSame('final', db_connect()->table('clinic_prescriptions')->select('status')->where('id', $id)->get()->getRow('status'));
        $locked = $this->getPage('clinic/rx/' . $id);
        $locked->assertSee('Start a new revision');
        $locked->assertDontSee('value="finalize"');
        $locked->assertDontSee('data-medadd');
        $this->getPage('clinic/rx/' . $id . '/print')->assertDontSee('DRAFT');
        $this->postForm('clinic/rx/' . $id . '/revise', ['reason' => 'dose changed'])->assertRedirect();
        $rev = db_connect()->table('clinic_prescriptions')->where('rx_no', $rx['rx_no'])->where('revision', 2)->get()->getRowArray();
        $this->assertSame(['draft', 'a5', 'modern'], [$rev['status'], $rev['paper'], $rev['style']]);
    }

    public function testCopyMedicinesFromAnEarlierVisitOnlyForThatPatient(): void
    {
        $doc = $this->doctor();
        $svc = new PrescriptionService();
        $p = (new PatientService())->create(['name' => 'Copy Patient', 'phone' => '01711555666']);
        $old = $svc->start((int) $p['id'], $doc);
        $svc->save($old, ['items' => [['brand_name' => 'Old Med', 'dose' => 'typed dose', 'duration' => '7 days']]]);
        $svc->finalize($old);
        $new = $svc->start((int) $p['id'], $doc);

        $page = $this->getPage('clinic/rx/' . $new);
        $page->assertSee('data-copy-from="' . $old . '"');
        $r = $this->getPage('clinic/rx/' . $old . '/items?into=' . $new);
        $items = json_decode((string) $r->response()->getBody(), true)['data'];
        $this->assertSame([['Old Med', 'typed dose', '7 days']], array_map(static fn (array $i): array => [$i['brand_name'], $i['dose'], $i['duration']], $items));
        $this->assertSame([], $svc->find($new)['items'], 'nothing is copied until the clinician saves');
        $this->seeInDatabase('clinic_access_log', ['prescription_id' => $old, 'action' => 'medicines copied']);

        // another patient's prescription cannot be copied in
        $q = (new PatientService())->create(['name' => 'Other Patient', 'phone' => '01711555777']);
        $other = $svc->start((int) $q['id'], $doc);
        $this->assertThrowsOmni(NotFoundException::class, fn () => $svc->itemsToCopy($old, $other));
    }

    public function testPrescriberWithoutRecordAccessOpensOnlyOwnPrescriptions(): void
    {
        $doc = $this->doctor();
        $p = (new PatientService())->create(['name' => 'Access Patient', 'phone' => '01711888999']);
        $ownersRx = (new PrescriptionService())->start((int) $p['id'], $doc);

        $this->role('writer', ['clinic.prescribe']);
        $this->act('writer');
        $mine = (new PrescriptionService())->start((int) $p['id'], $doc);
        $this->getPage('clinic/rx/' . $mine)->assertOK();
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/rx/' . $ownersRx));
        $list = $this->getPage('clinic/prescriptions');
        $list->assertOK();
        $this->assertStringContainsString('clinic&#x2F;rx&#x2F;' . $mine . '"', (string) $list->response()->getBody());
        $list->assertDontSee('clinic&#x2F;rx&#x2F;' . $ownersRx . '"');

        // no clinic permission at all
        $this->act('cashier');
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/rx/' . $mine . '/print'));
        $this->assertThrowsOmni(PermissionDeniedException::class, fn () => $this->getPage('clinic/prescriptions/new'));
    }
}
