<?php
/**
 * Clinic › Patients.
 *
 * @var string       $table
 * @var list<string> $blood
 */
helper('ui');
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>Patients</h2><p class="page-sub">Everyone who booked or visited. Visit history and prescriptions open only for clinicians and authorised staff.</p></div>
    <div class="spacer"></div>
    <button class="btn primary" type="button" data-modal-open="dlg-patient"><?= icon('userplus', 'sm') ?>New patient</button>
  </div>
  <div class="card"><?= $table ?></div>
</div>
<dialog class="modal-dlg" id="dlg-patient">
  <form method="post" action="<?= esc(site_url('clinic/patients'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('userplus', 'sm') ?> New patient</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><?= view('App\Modules\Clinic\Views\_patient_fields', ['p' => [], 'blood' => $blood]) ?></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Register patient</button></div>
  </form>
</dialog>
