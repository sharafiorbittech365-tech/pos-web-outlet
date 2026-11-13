<?php
/**
 * Clinic › Setup: pick lists, investigations, prescription pads (clinic-wide or per doctor).
 *
 * @var array<string, list<array{id: int, value: string}>> $lists
 * @var list<array<string, mixed>>                         $tests
 * @var array<string, mixed>                               $pad
 * @var int                                                $scope   doctor id, 0 = clinic-wide
 * @var list<array<string, mixed>>                         $doctors
 */
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\ClinicFiles;

helper('ui');
$o = static fn (string $k) => old($k) ?? $pad[$k];
$byCat = [];
foreach ($tests as $t) {
    $byCat[$t['category']][] = $t;
}
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>Clinic setup</h2><p class="page-sub">Your own lists — nothing is filled in for you. Clinicians pick from them while writing a prescription, or type something else.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('clinic/doctors'), 'attr') ?>"><?= icon('stethoscope', 'sm') ?>Doctors</a>
  </div>

  <div class="card" data-tabs="lists">
    <div class="card-b">
      <div class="tabs"><button type="button" data-tab="lists">Pick lists</button><button type="button" data-tab="tests">Investigations</button><button type="button" data-tab="pad">Prescription pad</button></div>

      <div class="pane" data-pane="lists" id="lists">
        <div class="cl-grid">
          <?php foreach (ClinicConfig::LISTS as $key => $label): ?>
            <div class="cl-box"><b><?= esc($label) ?></b>
              <div class="cl-chips"><?php if (! $lists[$key]): ?><span class="muted">Empty</span><?php endif ?>
                <?php foreach ($lists[$key] as $v): ?><form class="inline cl-chip" method="post" action="<?= esc(site_url('clinic/setup/lists/' . $v['id'] . '/delete'), 'attr') ?>"><?= csrf_field() ?><span><?= esc($v['value']) ?></span><button type="submit" aria-label="Remove <?= esc($v['value'], 'attr') ?>"><?= icon('x', 'sm') ?></button></form><?php endforeach ?></div>
              <form method="post" action="<?= esc(site_url('clinic/setup/lists'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="list" value="<?= esc($key, 'attr') ?>">
                <textarea class="input" name="values" rows="2" maxlength="5000" placeholder="One per line" aria-label="Add to <?= esc($label, 'attr') ?>"></textarea>
                <button class="btn sm" type="submit"><?= icon('plus', 'sm') ?>Add</button></form>
            </div>
          <?php endforeach ?>
        </div>
      </div>

      <div class="pane" data-pane="tests" id="tests" hidden>
        <form class="cl-row" method="post" action="<?= esc(site_url('clinic/setup/tests'), 'attr') ?>"><?= csrf_field() ?>
          <label class="field cl-inline">Category<select class="select" name="category"><?php foreach (ClinicConfig::TEST_CATS as $k => $l): ?><option value="<?= $k ?>"><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="field cl-inline grow">Tests (one per line)<textarea class="input" name="names" rows="2" maxlength="5000"></textarea><?= field_error('names') ?></label>
          <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add tests</button></form>
        <?php if (! $tests): ?><p class="muted">The test list is empty. Add the tests your clinic orders; they appear as tick boxes on the prescription.</p><?php endif ?>
        <div class="cl-grid">
          <?php foreach (ClinicConfig::TEST_CATS as $k => $l): if (empty($byCat[$k])) { continue; } ?>
            <div class="cl-box"><b><?= esc($l) ?></b><div class="cl-chips">
              <?php foreach ($byCat[$k] as $t): ?><form class="inline cl-chip <?= (int) $t['is_active'] ? '' : 'off' ?>" method="post" action="<?= esc(site_url('clinic/setup/tests/' . $t['id'] . '/toggle'), 'attr') ?>"><?= csrf_field() ?><span><?= esc($t['name']) ?></span>
                <button type="submit" title="<?= (int) $t['is_active'] ? 'Hide from the pad' : 'Show on the pad' ?>"><?= icon((int) $t['is_active'] ? 'eye' : 'plus', 'sm') ?></button></form><?php endforeach ?></div></div>
          <?php endforeach ?>
        </div>
      </div>

      <div class="pane" data-pane="pad" id="pad" hidden>
        <form class="cl-row" method="get" action="<?= esc(site_url('clinic/setup'), 'attr') ?>">
          <label class="field cl-inline">Pad of<select class="select" name="doctor" data-cl-autosubmit><option value="0">The clinic (every doctor without an own pad)</option>
            <?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $scope ? 'selected' : '' ?>><?= esc($d['name']) ?></option><?php endforeach ?></select></label>
          <noscript><button class="btn sm" type="submit">Open</button></noscript>
          <?php if ($scope > 0): ?><span class="muted"><?= $pad['saved'] ? 'This doctor has an own pad.' : 'Not saved yet — this doctor prints on the clinic\'s pad.' ?></span><?php endif ?>
        </form>
        <form method="post" action="<?= esc(site_url('clinic/setup/pad') . '#pad', 'attr') ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="doctor_id" value="<?= $scope ?>">
          <div class="form-grid">
            <label class="field">Paper<select class="select" name="paper"><?php foreach (['a4' => 'A4', 'a5' => 'A5'] as $k => $l): ?><option value="<?= $k ?>" <?= ($o('paper') ?: 'a4') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
            <label class="field">Design<select class="select" name="style"><?php foreach (ClinicConfig::STYLES as $k => $l): ?><option value="<?= $k ?>" <?= ($o('style') ?: 'classic') === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
            <label class="field">Header<select class="select" name="header_mode"><?php foreach (['text' => 'Doctor & clinic details (text)', 'image' => 'Header image', 'none' => 'None — pre-printed letterhead (space kept blank)'] as $k => $l): ?><option value="<?= $k ?>" <?= $o('header_mode') === $k ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
            <label class="field">Doctor block<select class="select" name="doctor_block"><option value="left" <?= $o('doctor_block') === 'left' ? 'selected' : '' ?>>Left (clinic right)</option><option value="right" <?= $o('doctor_block') === 'right' ? 'selected' : '' ?>>Right (clinic left)</option></select></label>
            <label class="field">Font size (pt)<input class="input" type="number" min="7" max="18" step="0.5" name="font_size" value="<?= esc((string) (float) $o('font_size'), 'attr') ?>"><?= field_error('font_size') ?></label>
            <label class="field span2">Extra header lines (text header)<textarea class="input" name="header_text" rows="2" maxlength="2000" placeholder="e.g. chamber hours, appointment phone"><?= esc((string) $o('header_text')) ?></textarea></label>
            <label class="field span2">Footer text<textarea class="input" name="footer_text" rows="2" maxlength="2000"><?= esc((string) $o('footer_text')) ?></textarea></label>
            <label class="field">Header image<input class="input" type="file" name="header_image" accept="image/png,image/jpeg,image/webp"><?= field_error('header_image') ?>
              <?php if ($pad['header_file_id']): ?><span class="cl-thumb wide"><img src="<?= esc(ClinicFiles::url((int) $pad['header_file_id']), 'attr') ?>" alt="Header"><label class="cl-check"><input type="checkbox" name="remove_header" value="1"> Remove</label></span><?php endif ?></label>
            <label class="field">Footer image<input class="input" type="file" name="footer_image" accept="image/png,image/jpeg,image/webp"><?= field_error('footer_image') ?>
              <?php if ($pad['footer_file_id']): ?><span class="cl-thumb wide"><img src="<?= esc(ClinicFiles::url((int) $pad['footer_file_id']), 'attr') ?>" alt="Footer"><label class="cl-check"><input type="checkbox" name="remove_footer" value="1"> Remove</label></span><?php endif ?></label>
            <?php foreach (['margin_top' => 'Top', 'margin_right' => 'Right', 'margin_bottom' => 'Bottom', 'margin_left' => 'Left'] as $k => $l): ?>
              <label class="field">Margin <?= strtolower($l) ?> (mm)<input class="input" type="number" min="0" max="60" name="<?= $k ?>" value="<?= esc((string) (int) $o($k), 'attr') ?>"><?= field_error($k) ?></label>
            <?php endforeach ?>
            <label class="field">Accent colour<input class="input" name="accent" maxlength="7" placeholder="#0F766E" value="<?= esc((string) $o('accent'), 'attr') ?>"><?= field_error('accent') ?></label>
            <label class="field cl-check"><input type="checkbox" name="show_signature" value="1" <?= $o('show_signature') ? 'checked' : '' ?>> Print the doctor's signature image</label>
          </div>
          <div class="cl-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save pad</button></div>
        </form>
        <?php if ($scope > 0 && $pad['saved']): ?><form method="post" action="<?= esc(site_url('clinic/setup/pad/reset'), 'attr') ?>" data-confirm="Use the clinic's pad for this doctor?"><?= csrf_field() ?><input type="hidden" name="doctor_id" value="<?= $scope ?>"><button class="btn sm" type="submit">Use the clinic's pad instead</button></form><?php endif ?>
        <p class="muted">Paper size: a saved pad decides; otherwise Setup › Invoice settings › Printing › Prescription. There is no PDF engine on the server — print the prescription, or choose "Save as PDF" as the printer.</p>
      </div>
    </div>
  </div>
</div>
