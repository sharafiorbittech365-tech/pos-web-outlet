<?php
/**
 * Clinic › Appointments: the day's queue (serial numbers per doctor) and the "New appointment" dialog.
 *
 * @var string                     $table
 * @var string                     $date
 * @var int                        $doctorId
 * @var list<array<string, mixed>> $doctors
 * @var array<string, int>         $counts
 */
helper('ui');
$s = service('posSession');
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$q = static fn (string $d) => site_url('clinic/appointments') . '?' . http_build_query(array_filter(['date' => $d, 'doctor' => $doctorId ?: null]));
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>Appointments</h2><p class="page-sub">Serial numbers per doctor and day. Confirm online requests, mark patients as they arrive and send them in.</p></div>
    <div class="spacer"></div>
    <?php if ($s->can('clinic.manage')): ?><a class="btn" href="<?= esc(site_url('clinic/setup'), 'attr') ?>"><?= icon('setup', 'sm') ?>Clinic setup</a><?php endif ?>
    <?php if ($doctors): ?><button class="btn primary" type="button" data-modal-open="dlg-appt"><?= icon('plus', 'sm') ?>New appointment</button><?php endif ?>
  </div>

  <?php if (! $doctors): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>Add your doctors and their weekly schedule first — then appointments can be booked here and on your website.</span>
      <?php if ($s->can('clinic.manage')): ?><a class="btn sm" href="<?= esc(site_url('clinic/doctors'), 'attr') ?>">Doctors</a><?php endif ?></div>
  <?php endif ?>

  <form class="cl-bar" method="get" action="<?= esc(site_url('clinic/appointments'), 'attr') ?>">
    <a class="btn sm" href="<?= esc($q($prev), 'attr') ?>" aria-label="Previous day"><?= icon('chevl', 'sm') ?></a>
    <label class="field cl-inline">Day<input class="input" type="date" name="date" value="<?= esc($date, 'attr') ?>" data-cl-autosubmit></label>
    <a class="btn sm" href="<?= esc($q($next), 'attr') ?>" aria-label="Next day"><?= icon('chev', 'sm') ?></a>
    <?php if ($date !== date('Y-m-d')): ?><a class="btn sm" href="<?= esc($q(date('Y-m-d')), 'attr') ?>">Today</a><?php endif ?>
    <label class="field cl-inline">Doctor<select class="select" name="doctor" data-cl-autosubmit><option value="">All doctors</option>
      <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $doctorId ? 'selected' : '' ?>><?= esc($d['name']) ?></option><?php endforeach ?></select></label>
    <noscript><button class="btn sm" type="submit">Show</button></noscript>
  </form>

  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'calendar', 'label' => date('D j M', strtotime($date)), 'value' => (string) $counts['total']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'New (to confirm)', 'value' => (string) $counts['pending'], 'color' => $counts['pending'] ? 'var(--warn)' : null]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'clock', 'label' => 'Waiting / with doctor', 'value' => (string) $counts['waiting']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'check', 'label' => 'Seen', 'value' => (string) $counts['seen']]) ?>
  </div>
  <div class="card"><?= $table ?></div>
</div>

<?php if ($doctors): ?>
<dialog class="modal-dlg" id="dlg-appt">
  <form method="post" action="<?= esc(site_url('clinic/appointments'), 'attr') ?>" data-cl-appt data-slots-url="<?= esc(site_url('clinic/slots'), 'attr') ?>" data-search-url="<?= esc(site_url('clinic/patients/search'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('stethoscope', 'sm') ?> New appointment</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field">Doctor *<select class="select" name="doctor_id" required data-cl-doctor>
        <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $doctorId ? 'selected' : '' ?>><?= esc($d['name']) ?><?= $d['specialty'] ? ' · ' . esc($d['specialty']) : '' ?></option><?php endforeach ?></select></label>
      <label class="field">Date<input class="input" type="date" name="date" value="<?= esc(max($date, date('Y-m-d')), 'attr') ?>" min="<?= esc(date('Y-m-d'), 'attr') ?>" data-cl-date></label>
      <label class="field span2 cl-check"><input type="checkbox" name="walk_in" value="1" data-cl-walkin> Walk-in now (next serial, no time slot)</label>
      <div class="field span2" data-cl-slotbox><span>Time *</span><div class="cl-slots" data-cl-slots role="radiogroup" aria-label="Time"><span class="muted">Choose a doctor and a date.</span></div></div>

      <div class="field span2"><span>Patient</span>
        <input class="input" type="search" placeholder="Search a registered patient: name, phone or P-number" data-cl-psearch autocomplete="off" aria-label="Search patient">
        <div class="cl-results" data-cl-presults hidden></div>
        <input type="hidden" name="patient_id" value="" data-cl-pid>
        <div class="cl-picked" data-cl-picked hidden></div></div>
      <div class="cl-newp span2" data-cl-newp>
        <div class="form-grid">
          <label class="field">Patient name *<input class="input" name="patient_name" maxlength="120" data-cl-req></label>
          <label class="field">Phone *<input class="input" name="patient_phone" inputmode="tel" maxlength="20" data-cl-req></label>
          <label class="field">Sex<select class="select" name="patient_sex"><option value="">—</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select></label>
          <label class="field">Age (years)<input class="input" type="number" min="0" max="130" name="patient_age"></label>
        </div><small class="muted">A new patient profile is made unless this name and phone are already registered.</small></div>
      <label class="field span2">Note<input class="input" name="note" maxlength="500" placeholder="e.g. follow-up, reports to show"></label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Book</button></div>
  </form>
</dialog>
<?php endif ?>
