<?php
/**
 * New prescription: pick the patient (search, or add quickly) and the doctor.
 *
 * @var ?array<string, mixed>      $patient  preselected (?patient=)
 * @var list<array<string, mixed>> $doctors  doctors the user may prescribe as
 * @var list<string>               $blood
 */
use App\Modules\Clinic\Services\PatientService;

helper('ui');
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>New prescription</h2><p class="page-sub">Choose the patient and the doctor — the pad opens next. Patients with an appointment today can also be started from the appointments queue.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('clinic/prescriptions'), 'attr') ?>"><?= icon('back', 'sm') ?>Prescriptions</a>
    <?php if (service('posSession')->can('clinic.appointments')): ?><a class="btn" href="<?= esc(site_url('clinic/appointments'), 'attr') ?>"><?= icon('calendar', 'sm') ?>Today's queue</a><?php endif ?>
  </div>
  <?php if (! $doctors): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>No doctor you can prescribe as. Add doctors (Clinic › Doctors) or ask the owner to link your user to your doctor profile.</span></div>
  <?php else: ?>
  <form class="card rxn" method="post" action="<?= esc(site_url('clinic/rx'), 'attr') ?>" data-cl-appt data-search-url="<?= esc(site_url('clinic/patients/search'), 'attr') ?>" data-slots-url="">
    <?= csrf_field() ?>
    <div class="card-b">
      <div class="form-grid">
        <label class="field span2">Doctor *<select class="select" name="doctor_id" required><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"><?= esc($d['name']) ?><?= $d['specialty'] ? ' · ' . esc($d['specialty']) : '' ?></option><?php endforeach ?></select></label>
        <div class="field span2"><span>Patient</span>
          <input class="input" type="search" placeholder="Search a registered patient: name, phone or P-number" data-cl-psearch autocomplete="off" aria-label="Search patient" <?= $patient ? 'hidden' : '' ?>>
          <div class="cl-results" data-cl-presults hidden></div>
          <input type="hidden" name="patient_id" value="<?= $patient ? (int) $patient['id'] : '' ?>" data-cl-pid>
          <div class="cl-picked" data-cl-picked <?= $patient ? '' : 'hidden' ?>><?php if ($patient): ?><span><b><?= esc($patient['name']) ?></b> <small class="muted"><?= esc(PatientService::code((int) $patient['seq']) . ' · ' . PatientService::ageSex($patient)) ?></small></span><button class="btn sm" type="button" data-cl-unpick>Change</button><?php endif ?></div></div>
        <div class="cl-newp span2" data-cl-newp <?= $patient ? 'hidden' : '' ?>>
          <b class="rxn-h">Or add a new patient</b>
          <div class="form-grid">
            <label class="field">Patient name *<input class="input" name="patient_name" maxlength="120" data-cl-req><?= field_error('patient.name') ?></label>
            <label class="field">Phone *<input class="input" name="patient_phone" inputmode="tel" maxlength="20" data-cl-req><?= field_error('patient.phone') ?></label>
            <label class="field">Age (years)<input class="input" type="number" min="0" max="130" name="patient_age"></label>
            <label class="field">Sex<select class="select" name="patient_sex"><option value="">—</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></label>
          </div><small class="muted">If this name and phone are already registered, that patient is used.</small></div>
      </div>
      <div class="cl-actions"><button class="btn primary" type="submit"><?= icon('notebook-pen', 'sm') ?>Open the prescription pad</button></div>
    </div>
  </form>
  <?php endif ?>
</div>
