<?php
/**
 * Prescription editor. LEFT: the prescription pad itself (doctor's / clinic's design), every part
 * click-to-edit — its inputs are the form fields. RIGHT: patient & earlier visits (copy medicines on
 * request), vitals (mirrored on the pad), appointment, pick lists, paper & design, revisions, actions.
 * Final prescriptions are shown locked; "Revise" starts a new revision (reason required).
 *
 * @var array<string, mixed>                               $rx
 * @var array<string, mixed>                               $view      PadView::build()
 * @var bool                                               $editable
 * @var array<string, list<array{id: int, value: string}>> $lists
 * @var list<array<string, mixed>>                         $tests
 * @var ?array<string, mixed>                              $booking
 * @var list<array<string, mixed>>                         $revisions
 * @var bool                                               $canRx
 * @var bool                                               $medicinesOn
 * @var list<array<string, mixed>>                         $history   earlier final prescriptions of the patient
 */
use App\Modules\Clinic\Services\AppointmentDesk;
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\PatientService;
use App\Modules\Clinic\Services\PrescriptionService;

helper('ui');
$id = (int) $rx['id'];
$p = $rx['patient'];
$asset = static fn (string $f): string => esc(base_url('assets/pos/' . $f) . '?v=' . (is_file(FCPATH . 'assets/pos/' . $f) ? filemtime(FCPATH . 'assets/pos/' . $f) : 0), 'attr');
$statusTag = match ($rx['status']) { 'draft' => tag_html('Draft', 'warn'), 'final' => tag_html('Final · locked', 'ok'), default => tag_html('Superseded') };
$printUrl = site_url('clinic/rx/' . $id . '/print');
$justSaved = (int) (session('rx_print') ?? 0) === $id;
$vit = (array) $rx['vitals'];
$chips = static function (string $list, string $target) use ($lists): string {
    $out = '';
    foreach ($lists[$list] ?? [] as $x) {
        $out .= '<button class="cl-chip-b" type="button" data-insert="' . esc($target, 'attr') . '" data-value="' . esc($x['value'], 'attr') . '">' . esc($x['value']) . '</button>';
    }

    return $out;
};
?>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $asset('rxpad.css') ?>">
<div class="page rxe" id="rxe" data-view="doc">
  <header class="rxe-head">
    <a class="rxe-back" href="<?= esc(site_url('clinic/prescriptions'), 'attr') ?>" aria-label="Back to prescriptions"><?= icon('back', 'sm') ?></a>
    <div class="rxe-head-t">
      <h2><span class="mono"><?= esc($rx['rx_no']) ?></span><?= (int) $rx['revision'] > 1 ? ' <small class="muted">rev ' . (int) $rx['revision'] . '</small>' : '' ?> <?= $statusTag ?></h2>
      <p><?php if ($editable): ?>Click any part of the prescription to write. Medicines: search the list or type; Enter on the last field adds a line, Alt + ↑ / ↓ moves it.<?php elseif ($rx['status'] === 'final'): ?>Finalized <?= esc(datetime_str($rx['finalized_at'])) ?> — locked. Use “Revise” to change it; this version is kept.<?php else: ?>Read-only.<?php endif ?></p>
    </div>
    <a class="btn" href="<?= esc($printUrl, 'attr') ?>" target="_blank" rel="noopener"><?= icon('print', 'sm') ?><?= $rx['status'] === 'final' ? 'Print / PDF' : 'Print preview' ?></a>
  </header>

  <?php if ($justSaved): ?><div class="sbanner ok" role="status"><?= icon('print', 'sm') ?><span><?= $rx['status'] === 'final' ? 'Finalized. Print it, or choose “Save as PDF” in the print dialog.' : 'Draft saved — the preview shows the saved version.' ?></span><a class="btn sm primary" href="<?= esc($printUrl . '?auto=1', 'attr') ?>" target="_blank" rel="noopener" data-autoprint><?= icon('print', 'sm') ?>Open print</a></div><?php endif ?>
  <?php if (session('errors')): ?><div class="sbanner warn" role="alert"><?= icon('alert', 'sm') ?><span>Please check the highlighted parts — your text is kept.</span></div><?php endif ?>
  <?php if ($rx['status'] === 'superseded'): ?><div class="sbanner warn"><?= icon('alert', 'sm') ?><span>A newer revision of this prescription exists. This one is kept unchanged for the record.</span></div><?php endif ?>
  <?php if ($rx['status'] === 'draft' && ! $editable): ?><div class="sbanner"><?= icon('lock', 'sm') ?><span>Draft — only the prescribing doctor (or staff allowed to write for them) can edit it.</span></div><?php endif ?>

  <div class="rxe-tabs" role="tablist" aria-label="Prescription editor">
    <button type="button" role="tab" data-view="doc" id="rxe-tab-doc" aria-controls="rxe-doc" aria-selected="true"><?= icon('rx', 'sm') ?>Prescription</button>
    <button type="button" role="tab" data-view="side" id="rxe-tab-side" aria-controls="rxe-side" aria-selected="false" tabindex="-1"><?= icon('user', 'sm') ?>Patient &amp; details</button>
  </div>

  <?= $editable ? '<form class="rxe-grid" method="post" action="' . esc(site_url('clinic/rx/' . $id), 'attr') . '" id="rx-form" data-rx data-med-url="' . esc(site_url('clinic/rx/medicines'), 'attr') . '" data-med-on="' . ($medicinesOn ? '1' : '0') . '" data-copy-url="' . esc(site_url('clinic/rx'), 'attr') . '" data-id="' . $id . '">' . csrf_field() : '<div class="rxe-grid">' ?>
    <section class="rxe-doc" id="rxe-doc" role="tabpanel" aria-labelledby="rxe-tab-doc" aria-label="Prescription pad<?= $editable ? ' (editable)' : '' ?>">
      <div class="rxe-paper" data-paper><?= view('App\Modules\Clinic\Views\_pad', ['rx' => $rx, 'view' => $view, 'edit' => $editable, 'tests' => $tests, 'booking' => $booking]) ?></div>
    </section>

    <div class="rxe-side" id="rxe-side" role="tabpanel" aria-labelledby="rxe-tab-side">
      <section class="rxe-card">
        <h3 class="rxe-card-h"><?= icon('user', 'sm') ?>Patient</h3>
        <div class="rxe-pt"><b><?= esc((string) ($p['name'] ?? '')) ?></b><span class="mono muted"><?= esc(PatientService::code((int) ($p['seq'] ?? 0))) ?></span></div>
        <p class="muted rxe-sm"><?= esc(implode(' · ', array_filter([PatientService::ageSex($p), (string) ($p['phone'] ?? ''), ! empty($p['blood_group']) ? 'Blood ' . $p['blood_group'] : '']))) ?></p>
        <?php if (! empty($p['id'])): ?><a class="link rxe-sm" href="<?= esc(site_url('clinic/patients/' . $p['id']), 'attr') ?>">Patient profile &amp; all visits</a><?php endif ?>
        <?php if ($history): ?>
          <h4 class="rxe-sub">Earlier visits</h4>
          <ul class="rxe-hist">
            <?php foreach ($history as $h): ?>
              <li><div class="grow"><b><?= esc(date_str($h['visit_date'])) ?></b> <small class="muted"><?= esc((string) $h['doctor_name']) ?></small><br><small class="muted"><?= esc(mb_strimwidth((string) ($h['diagnosis'] ?: 'No diagnosis written'), 0, 80, '…')) ?></small></div>
                <span class="rxe-hist-a"><a class="btn sm" href="<?= esc(site_url('clinic/rx/' . $h['id']), 'attr') ?>" target="_blank" rel="noopener" aria-label="Open the prescription of <?= esc(date_str($h['visit_date']), 'attr') ?>"><?= icon('eye', 'sm') ?></a>
                <?php if ($editable && (int) $h['n_items'] > 0): ?><button class="btn sm" type="button" data-copy-from="<?= (int) $h['id'] ?>" title="Add this visit's medicines to the pad — check every line"><?= icon('copy', 'sm') ?>Copy medicines</button><?php endif ?></span></li>
            <?php endforeach ?>
          </ul>
        <?php endif ?>
      </section>

      <section class="rxe-card">
        <h3 class="rxe-card-h"><?= icon('thermometer', 'sm') ?>Vitals <small>Printed on the pad only when filled</small></h3>
        <div class="rxe-vitals">
          <?php foreach (PrescriptionService::VITALS as $k => $l): ?><label class="rxe-field"><span><?= esc($l) ?></span><input class="input" data-bind="vitals[<?= $k ?>]" value="<?= esc((string) ($vit[$k] ?? ''), 'attr') ?>" maxlength="30" <?= $editable ? '' : 'readonly' ?>></label><?php endforeach ?>
        </div>
      </section>

      <?php if ($booking !== null): ?>
        <section class="rxe-card">
          <h3 class="rxe-card-h"><?= icon('calendar', 'sm') ?>Appointment</h3>
          <p class="rxe-sm"><b>Serial <?= (int) $booking['serial_no'] ?></b> · <?= esc(date_str($booking['starts_at'])) ?> <?= esc(time_str($booking['starts_at'])) ?> · <?= AppointmentDesk::stageTag($booking) ?></p>
          <p class="muted rxe-sm"><?= esc($booking['reference']) ?> · <?= $booking['source'] === 'pos' ? 'Desk' : 'Online' ?><?= $booking['note'] ? ' · ' . esc($booking['note']) : '' ?></p>
        </section>
      <?php endif ?>

      <?php if ($editable && ($lists['complaint'] || $lists['advice'])): ?>
        <section class="rxe-card">
          <h3 class="rxe-card-h"><?= icon('notebook-pen', 'sm') ?>Your pick lists <small>Click to add to the pad — set them up in Clinic setup</small></h3>
          <?php if ($lists['complaint']): ?><div><small class="muted">Chief complaints</small><div class="cl-chips sm"><?= $chips('complaint', 'chief_complaints') ?></div></div><?php endif ?>
          <?php if ($lists['advice']): ?><div><small class="muted">Advice</small><div class="cl-chips sm"><?= $chips('advice', 'advice') ?></div></div><?php endif ?>
        </section>
      <?php endif ?>

      <section class="rxe-card">
        <h3 class="rxe-card-h"><?= icon('file-text', 'sm') ?>Paper &amp; design</h3>
        <fieldset class="rxe-seg" <?= $editable ? '' : 'disabled' ?>><legend>Paper</legend>
          <?php foreach (['a4' => 'A4', 'a5' => 'A5'] as $k => $l): ?><label><input type="radio" name="paper" value="<?= $k ?>" data-paper-opt <?= $view['paper'] === $k ? 'checked' : '' ?>><span><?= $l ?></span></label><?php endforeach ?>
        </fieldset>
        <fieldset class="rxe-seg" <?= $editable ? '' : 'disabled' ?>><legend>Design</legend>
          <?php foreach (ClinicConfig::STYLES as $k => $l): ?><label><input type="radio" name="style" value="<?= $k ?>" data-style-opt <?= $view['style'] === $k ? 'checked' : '' ?>><span><?= esc($l) ?></span></label><?php endforeach ?>
        </fieldset>
        <?php if (service('posSession')->can('clinic.manage')): ?><a class="link rxe-sm" href="<?= esc(site_url('clinic/setup') . '?doctor=' . (int) $rx['doctor_id'] . '#pad', 'attr') ?>" target="_blank" rel="noopener">Header, footer, margins, accent &amp; signature: prescription pad setup</a><?php endif ?>
      </section>

      <?php if (count($revisions) > 1 || $rx['status'] === 'final'): ?>
        <section class="rxe-card">
          <h3 class="rxe-card-h"><?= icon('refresh', 'sm') ?>Revisions</h3>
          <ul class="rxe-hist">
            <?php foreach ($revisions as $r): ?><li class="<?= (int) $r['id'] === $id ? 'on' : '' ?>"><a class="grow" href="<?= esc(site_url('clinic/rx/' . $r['id']), 'attr') ?>"><b>Revision <?= (int) $r['revision'] ?></b><br><small class="muted"><?= esc(datetime_str($r['finalized_at'] ?? $r['created_at'])) ?><?= $r['by_name'] ? ' · ' . esc($r['by_name']) : '' ?><?= $r['revision_reason'] ? ' · ' . esc($r['revision_reason']) : '' ?></small></a><?= $r['status'] === 'final' ? tag_html('Final', 'ok') : ($r['status'] === 'draft' ? tag_html('Draft', 'warn') : tag_html('Old')) ?></li><?php endforeach ?>
          </ul>
        </section>
      <?php endif ?>

      <?php if (! $editable && $rx['status'] === 'final' && $canRx): ?>
      <section class="rxe-card rxe-revise">
          <h3 class="rxe-card-h"><?= icon('edit', 'sm') ?>Revise this prescription</h3>
          <form class="cl-row" method="post" action="<?= esc(site_url('clinic/rx/' . $id . '/revise'), 'attr') ?>"><?= csrf_field() ?>
            <label class="field cl-inline grow">Reason for the change *<input class="input" name="reason" required minlength="3" maxlength="300"><?= field_error('reason') ?></label>
            <button class="btn" type="submit">Start a new revision</button></form>
          <small class="muted">This version stays as it is; the new revision replaces it once finalized.</small>
        </section>
      <?php endif ?>

      <div class="rxe-actions">
        <?php if ($editable): ?>
          <button class="btn" type="submit" name="action" value="draft"><?= icon('check', 'sm') ?>Save<span class="rxe-lbl"> draft</span></button>
          <button class="btn" type="submit" name="action" value="preview" aria-label="Save and print preview" title="Save and print preview"><?= icon('eye', 'sm') ?><span class="rxe-lbl">Print preview</span></button>
          <button class="btn primary rxe-cta" type="submit" name="action" value="finalize" data-confirm="Finalize and lock this prescription? Later changes need a new revision with a reason."><?= icon('lock', 'sm') ?>Finalize &amp; print</button>
          <button class="btn sm danger rxe-discard" type="submit" form="rx-discard" aria-label="Discard draft" title="Discard draft"><?= icon('trash', 'sm') ?></button>
        <?php elseif ($rx['status'] === 'final'): ?>
          <a class="btn primary rxe-cta" href="<?= esc($printUrl, 'attr') ?>" target="_blank" rel="noopener"><?= icon('print', 'sm') ?>Print / Save as PDF</a>
        <?php endif ?>
      </div>
    </div>
  <?= $editable ? '</form>' : '</div>' ?>

  <?php if ($editable): ?>
    <form id="rx-discard" method="post" action="<?= esc(site_url('clinic/rx/' . $id . '/discard'), 'attr') ?>" data-confirm="Discard this draft?" hidden><?= csrf_field() ?></form>
    <?php foreach (['dose', 'frequency', 'duration', 'route', 'instruction'] as $l): ?><datalist id="dl-<?= $l ?>"><?php foreach ($lists[$l] as $x): ?><option value="<?= esc($x['value'], 'attr') ?>"></option><?php endforeach ?></datalist><?php endforeach ?>
  <?php endif ?>
</div>
