<?php
/**
 * Clinic › Doctors.
 *
 * @var list<array<string, mixed>>        $doctors
 * @var list<array{id: int, name: string}> $users
 */
use App\Modules\Clinic\Services\ClinicFiles;
use OmniPOS\Core\Services\ClinicSchedule;

helper('ui');
$short = static fn (int $wd): string => substr(ClinicSchedule::WEEKDAYS[$wd], 0, 3);
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>Doctors</h2><p class="page-sub">Profiles, weekly schedules and leave. Patients see the doctors marked for the website and book their free slots.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('clinic/setup'), 'attr') ?>"><?= icon('setup', 'sm') ?>Clinic setup</a>
    <button class="btn primary" type="button" data-modal-open="dlg-doctor"><?= icon('plus', 'sm') ?>Add doctor</button>
  </div>

  <?php if (! $doctors): ?><div class="card"><div class="empty"><span class="empty-ic"><?= icon('stethoscope') ?></span><b>No doctors yet</b><span>Add the first doctor, then their weekly sessions.</span></div></div><?php endif ?>
  <div class="cl-docs">
    <?php foreach ($doctors as $d): ?>
      <a class="card cl-doc <?= (int) $d['is_active'] ? '' : 'off' ?>" href="<?= esc(site_url('clinic/doctors/' . $d['id']), 'attr') ?>">
        <span class="cl-av"><?php if ($d['photo_file_id']): ?><img src="<?= esc(ClinicFiles::url((int) $d['photo_file_id']), 'attr') ?>" alt=""><?php else: ?><?= esc(initials($d['name'])) ?><?php endif ?></span>
        <span class="grow"><b><?= esc($d['name']) ?></b><br><small class="muted"><?= esc(implode(' · ', array_filter([(string) $d['degrees'], (string) $d['specialty']]))) ?></small>
          <span class="cl-sess"><?php if (! $d['sessions']): ?><span class="tag warn">No schedule yet</span><?php endif ?>
            <?php foreach ($d['sessions'] as $s): ?><span class="tag"><?= esc($short((int) $s['weekday'])) ?> <?= esc(substr($s['starts'], 0, 5)) ?>–<?= esc(substr($s['ends'], 0, 5)) ?></span><?php endforeach ?></span></span>
        <span class="cl-flags"><?= (int) $d['is_active'] ? '' : tag_html('Not seeing patients') ?><?= (int) $d['is_public'] && (int) $d['is_active'] ? tag_html('Online', 'primary') : '' ?></span>
      </a>
    <?php endforeach ?>
  </div>
</div>
<dialog class="modal-dlg wide" id="dlg-doctor">
  <form method="post" action="<?= esc(site_url('clinic/doctors'), 'attr') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('stethoscope', 'sm') ?> Add doctor</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><?= view('App\Modules\Clinic\Views\_doctor_fields', ['d' => [], 'users' => $users]) ?></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Add doctor</button></div>
  </form>
</dialog>
