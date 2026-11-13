<?php
/**
 * Patient profile; the medical record (prescriptions) only for clinic.patients.view.
 *
 * @var array<string, mixed>            $p
 * @var list<array<string, mixed>>      $visits
 * @var ?list<array<string, mixed>>     $record   null = no permission
 * @var ?list<array<string, mixed>>     $access   record access log (clinic.manage)
 * @var list<string>                    $blood
 * @var list<array<string, mixed>>      $doctors  doctors the user may prescribe as
 */
use App\Modules\Clinic\Services\AppointmentDesk;
use App\Modules\Clinic\Services\PatientService;

helper('ui');
$s = service('posSession');
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2><?= esc($p['name']) ?></h2><p class="page-sub"><span class="mono"><?= esc(PatientService::code($p['seq'])) ?></span><?= PatientService::ageSex($p) !== '' ? ' · ' . esc(PatientService::ageSex($p)) : '' ?><?= $p['phone'] ? ' · ' . esc($p['phone']) : '' ?><?= $p['blood_group'] ? ' · Blood ' . esc($p['blood_group']) : '' ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('clinic/patients'), 'attr') ?>"><?= icon('back', 'sm') ?>Patients</a>
  </div>

  <div class="cl-cols">
    <div>
      <?php if ($record !== null): ?>
        <section class="card">
          <div class="card-h"><h3><?= icon('rx', 'sm') ?> Prescriptions</h3><span class="muted"><?= count($record) ?></span></div>
          <?php if ($doctors): ?>
            <form class="card-b cl-row" method="post" action="<?= esc(site_url('clinic/rx'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int) $p['id'] ?>">
              <label class="field cl-inline">Doctor<select class="select" name="doctor_id"><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"><?= esc($d['name']) ?></option><?php endforeach ?></select></label>
              <button class="btn primary" type="submit"><?= icon('notebook-pen', 'sm') ?>New prescription</button></form>
          <?php endif ?>
          <?php if (! $record): ?><div class="empty"><span class="empty-ic"><?= icon('rx') ?></span><b>No prescriptions yet</b></div><?php endif ?>
          <div class="cl-list">
            <?php foreach ($record as $r): ?>
              <a class="cl-item" href="<?= esc(site_url('clinic/rx/' . $r['id']), 'attr') ?>">
                <span class="grow"><b><?= esc(date_str($r['visit_date'])) ?> · <?= esc((string) $r['doctor_name']) ?></b><br>
                  <small class="muted"><?= esc(mb_strimwidth((string) ($r['diagnosis'] ?: $r['chief_complaints'] ?: 'No diagnosis written'), 0, 140, '…')) ?></small></span>
                <span class="mono muted"><?= esc($r['rx_no']) ?><?= (int) $r['revision'] > 1 ? ' r' . (int) $r['revision'] : '' ?></span>
                <?= $r['status'] === 'draft' ? tag_html('Draft', 'warn') : tag_html('Final', 'ok') ?>
              </a>
            <?php endforeach ?>
          </div>
        </section>
      <?php else: ?>
        <div class="sbanner"><?= icon('lock', 'sm') ?><span>Visit notes and prescriptions are medical records — only clinicians and authorised staff can open them.</span></div>
      <?php endif ?>

      <section class="card">
        <div class="card-h"><h3><?= icon('calendar', 'sm') ?> Appointments</h3><span class="muted"><?= count($visits) ?></span></div>
        <?php if (! $visits): ?><div class="empty"><b>No appointments yet</b></div><?php endif ?>
        <div class="cl-list">
          <?php foreach ($visits as $v): ?>
            <div class="cl-item"><span class="grow"><b><?= esc(date_str($v['starts_at'])) ?> · <?= esc(time_str($v['starts_at'])) ?></b><br><small class="muted"><?= esc((string) $v['doctor_name']) ?> · serial <?= (int) $v['serial_no'] ?> · <?= esc($v['reference']) ?></small></span><?= AppointmentDesk::stageTag($v) ?></div>
          <?php endforeach ?>
        </div>
      </section>

      <?php if ($access !== null): ?>
        <section class="card">
          <div class="card-h"><h3><?= icon('shield', 'sm') ?> Record access log</h3><span class="muted">latest <?= count($access) ?></span></div>
          <div class="cl-list">
            <?php foreach ($access as $a): ?><div class="cl-item"><span class="grow"><b><?= esc((string) $a['user_name']) ?></b> · <?= esc($a['action']) ?><?= $a['prescription_id'] ? ' · RX #' . (int) $a['prescription_id'] : '' ?></span><small class="muted"><?= esc(datetime_str($a['created_at'])) ?></small></div><?php endforeach ?>
          </div>
        </section>
      <?php endif ?>
    </div>

    <section class="card">
      <div class="card-h"><h3><?= icon('user', 'sm') ?> Profile</h3></div>
      <form class="card-b" method="post" action="<?= esc(site_url('clinic/patients/' . $p['id']), 'attr') ?>"><?= csrf_field() ?>
        <?= view('App\Modules\Clinic\Views\_patient_fields', ['p' => $p, 'blood' => $blood]) ?>
        <div class="cl-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save profile</button></div>
      </form>
    </section>
  </div>
</div>
