<?php
/**
 * Doctor: profile, weekly sessions (repeatable rows), leave dates.
 *
 * @var array<string, mixed>              $d
 * @var list<array{id: int, name: string}> $users
 */
use OmniPOS\Core\Services\ClinicSchedule;

helper('ui');
$id = (int) $d['id'];
$dayOpts = static function (int $sel): string {
    $o = '';
    foreach (ClinicSchedule::WEEKDAYS as $i => $n) {
        $o .= '<option value="' . $i . '"' . ($i === $sel ? ' selected' : '') . '>' . esc($n) . '</option>';
    }

    return $o;
};
$row = static fn (string $i, array $s): string => '<div class="bo-row cl-srow" data-row style="--cols:6">'
    . '<label class="field">Day<select class="select" name="sessions[' . $i . '][weekday]">' . $dayOpts((int) ($s['weekday'] ?? 6)) . '</select></label>'
    . '<label class="field">From<input class="input" type="time" name="sessions[' . $i . '][starts]" value="' . esc(substr((string) ($s['starts'] ?? ''), 0, 5), 'attr') . '" required></label>'
    . '<label class="field">To<input class="input" type="time" name="sessions[' . $i . '][ends]" value="' . esc(substr((string) ($s['ends'] ?? ''), 0, 5), 'attr') . '" required></label>'
    . '<label class="field">Slot (min)<input class="input" type="number" min="5" max="240" name="sessions[' . $i . '][slot_minutes]" value="' . esc((string) ($s['slot_minutes'] ?? 15), 'attr') . '" required></label>'
    . '<label class="field">Max patients<input class="input" type="number" min="1" max="500" name="sessions[' . $i . '][max_patients]" value="' . esc((string) ($s['max_patients'] ?? ''), 'attr') . '" placeholder="one per slot"></label>'
    . '<label class="field">Chamber (if different)<input class="input" name="sessions[' . $i . '][chamber]" maxlength="255" value="' . esc((string) ($s['chamber'] ?? ''), 'attr') . '"></label>'
    . '<button class="x-btn" type="button" data-row-del aria-label="Remove session">' . icon('trash', 'sm') . '</button></div>';
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2><?= esc($d['name']) ?></h2><p class="page-sub"><?= esc(implode(' · ', array_filter([(string) $d['degrees'], (string) $d['specialty'], $d['reg_no'] ? 'Reg. ' . $d['reg_no'] : '']))) ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('clinic/doctors'), 'attr') ?>"><?= icon('back', 'sm') ?>Doctors</a>
    <a class="btn" href="<?= esc(site_url('clinic/setup') . '?doctor=' . $id . '#pad', 'attr') ?>"><?= icon('rx', 'sm') ?>Prescription pad</a>
    <a class="btn" href="<?= esc(site_url('clinic/appointments') . '?doctor=' . $id, 'attr') ?>"><?= icon('calendar', 'sm') ?>Appointments</a>
  </div>

  <section class="card">
    <div class="card-h"><h3>Profile</h3></div>
    <form class="card-b" method="post" action="<?= esc(site_url('clinic/doctors/' . $id), 'attr') ?>" enctype="multipart/form-data"><?= csrf_field() ?>
      <?= view('App\Modules\Clinic\Views\_doctor_fields', ['d' => $d, 'users' => $users]) ?>
      <div class="cl-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save profile</button></div>
    </form>
  </section>

  <section class="card" id="schedule">
    <div class="card-h"><h3><?= icon('clock', 'sm') ?> Weekly schedule</h3><span class="muted">Each session is cut into slots; one patient per slot, at most "max patients" per session.</span></div>
    <form class="card-b" method="post" action="<?= esc(site_url('clinic/doctors/' . $id . '/schedule'), 'attr') ?>"><?= csrf_field() ?>
      <?php foreach (session('errors') ?? [] as $k => $msg): if (str_starts_with((string) $k, 'sessions.')): ?><p class="field-err"><?= esc((string) $msg) ?></p><?php endif; endforeach ?>
      <div data-rows="sessions" data-next="<?= count($d['sessions']) ?>"><?php foreach ($d['sessions'] as $i => $s): ?><?= $row((string) $i, $s) ?><?php endforeach ?></div>
      <p class="muted" data-rows-empty="sessions" <?= $d['sessions'] ? 'hidden' : '' ?>>No sessions yet — add the days and hours the doctor sees patients.</p>
      <template data-row-tpl="sessions"><?= $row('__i__', []) ?></template>
      <div class="cl-actions"><button class="btn" type="button" data-row-add="sessions"><?= icon('plus', 'sm') ?>Add session</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save schedule</button></div>
    </form>
  </section>

  <section class="card" id="leave">
    <div class="card-h"><h3><?= icon('calendar', 'sm') ?> Leave &amp; days off</h3></div>
    <form class="card-b cl-row" method="post" action="<?= esc(site_url('clinic/doctors/' . $id . '/leaves'), 'attr') ?>"><?= csrf_field() ?>
      <label class="field cl-inline">From<input class="input" type="date" name="from_date" required min="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
      <label class="field cl-inline">To<input class="input" type="date" name="to_date" min="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
      <label class="field cl-inline grow">Note<input class="input" name="note" maxlength="200" placeholder="e.g. conference"></label>
      <button class="btn" type="submit"><?= icon('plus', 'sm') ?>Add leave</button><?= field_error('from_date') ?>
    </form>
    <div class="cl-list">
      <?php foreach ($d['leaves'] as $l): ?>
        <div class="cl-item"><span class="grow"><b><?= esc(date_str($l['from_date'])) ?><?= $l['to_date'] !== $l['from_date'] ? ' – ' . esc(date_str($l['to_date'])) : '' ?></b><?= $l['note'] ? ' · ' . esc($l['note']) : '' ?></span>
          <form class="inline" method="post" action="<?= esc(site_url('clinic/doctors/' . $id . '/leaves/' . $l['id'] . '/delete'), 'attr') ?>"><?= csrf_field() ?><button class="btn sm" type="submit" aria-label="Remove leave"><?= icon('trash', 'sm') ?></button></form></div>
      <?php endforeach ?>
    </div>
    <p class="card-b muted">Appointments already booked on leave days stay — call those patients to move them.</p>
  </section>

  <form class="cl-danger" method="post" action="<?= esc(site_url('clinic/doctors/' . $id . '/delete'), 'attr') ?>" data-confirm="Remove <?= esc($d['name'], 'attr') ?>? Past appointments and prescriptions are kept."><?= csrf_field() ?>
    <button class="btn danger" type="submit"><?= icon('trash', 'sm') ?>Remove doctor</button></form>
</div>
