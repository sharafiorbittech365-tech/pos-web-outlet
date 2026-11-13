<?php
/**
 * The prescription pad — one markup for the editor (left side, every part click-to-edit; the inputs ARE
 * the form fields) and the print page (plain text, empty parts left out). Styles: assets/pos/rxpad.css.
 *
 * @var array<string, mixed>        $rx
 * @var array<string, mixed>        $view    PadView::build()
 * @var bool                        $edit    editable draft
 * @var list<array<string, mixed>>  $tests   the clinic's active tests (editor)
 * @var ?array<string, mixed>       $booking
 */
use App\Modules\Clinic\Services\ClinicConfig;
use App\Modules\Clinic\Services\PadView;
use App\Modules\Clinic\Services\PrescriptionService;

$edit ??= false;
$tests ??= [];
$booking ??= null;
$ps = $rx['patient_snapshot'];
$v = $view;
$doc = $v['doctor'];
$cl = $v['clinic'];
$old = static fn (string $k, mixed $d): string => (string) (old($k) ?? $d ?? '');
$text = static fn (?string $t): string => nl2br(esc(trim((string) $t)));
// one block of clinician text: a document-styled textarea when editing, the text when printing
$block = static function (string $name, string $label, string $placeholder, int $rows = 2) use ($rx, $edit, $old, $text): string {
    $val = $old($name, $rx[$name] ?? '');
    if (! $edit) {
        return trim($val) === '' ? '' : '<section class="rxp-sec"><h4>' . esc($label) . '</h4><div class="rxp-txt">' . $text($val) . '</div></section>';
    }

    return '<section class="rxp-sec"><h4><label for="rxd-' . $name . '">' . esc($label) . '</label></h4><textarea class="rxd" id="rxd-' . $name . '" name="' . $name . '" rows="' . $rows . '" maxlength="5000" placeholder="' . esc($placeholder, 'attr') . '" data-target="' . $name . '">'
        . esc($val) . '</textarea>' . field_error($name) . '</section>';
};
$items = $rx['items'];
$med = static function (string $i, array $it) use ($edit): string {
    $a = static fn (string $k): string => esc((string) ($it[$k] ?? ''), 'attr');
    if (! $edit) {
        $sig = implode(' — ', array_filter([(string) ($it['dose'] ?? ''), (string) ($it['frequency'] ?? ''), (string) ($it['duration'] ?? ''), (string) ($it['route'] ?? '')]));

        return '<li class="rxp-med"><div class="rxp-med-h"><span class="rxp-form">' . esc((string) ($it['dosage_form'] ?? '')) . '</span> <b>' . esc((string) $it['brand_name']) . '</b> <span class="rxp-str">' . esc((string) ($it['strength'] ?? '')) . '</span></div>'
            . (! empty($it['generic']) ? '<div class="rxp-gen">' . esc((string) $it['generic']) . '</div>' : '')
            . ($sig !== '' ? '<div class="rxp-sig">' . esc($sig) . '</div>' : '') . (! empty($it['instructions']) ? '<div class="rxp-ins">' . esc((string) $it['instructions']) . '</div>' : '') . '</li>';
    }
    $f = static fn (string $k, string $ph, int $max, string $cls, string $list = '', string $extra = ''): string => '<input class="rxd ' . $cls . '" name="items[' . $i . '][' . $k . ']" value="' . $a($k) . '" maxlength="' . $max . '" placeholder="' . esc($ph, 'attr') . '" aria-label="' . esc($ph, 'attr') . '"' . ($list !== '' ? ' list="dl-' . $list . '"' : '') . ' ' . $extra . '>';

    return '<li class="rxp-med" data-med><input type="hidden" name="items[' . $i . '][medicine_id]" value="' . $a('medicine_id') . '" data-mid>'
        . '<div class="rxp-med-h">' . $f('dosage_form', 'Form', 80, 'rxd-form', '', 'data-form') . $f('brand_name', 'Medicine — search or type', 200, 'rxd-brand', '', 'data-brand autocomplete="off" aria-autocomplete="list"') . $f('strength', 'Strength', 120, 'rxd-str', '', 'data-strength') . '</div>'
        . $f('generic', 'Generic name', 255, 'rxd-gen', '', 'data-generic')
        . '<div class="rxp-sig">' . $f('dose', 'Dose', 120, 'rxd-s', 'dose') . '<span aria-hidden="true">—</span>' . $f('frequency', 'Frequency', 120, 'rxd-s', 'frequency') . '<span aria-hidden="true">—</span>'
        . $f('duration', 'Duration', 120, 'rxd-s', 'duration') . '<span aria-hidden="true">—</span>' . $f('route', 'Route', 80, 'rxd-s', 'route') . '</div>'
        . $f('instructions', 'Instructions (e.g. after meal)', 300, 'rxd-ins', 'instruction')
        . '<span class="rxp-tools"><button class="rxp-tb" type="button" data-move="-1" aria-label="Move medicine up">&#8593;</button><button class="rxp-tb" type="button" data-move="1" aria-label="Move medicine down">&#8595;</button><button class="rxp-tb del" type="button" data-del aria-label="Remove medicine">&#215;</button></span>'
        . '<div class="rxp-sugg" role="listbox" data-sugg hidden></div></li>';
};
$picked = array_map(static fn (array $t): int => (int) $t['test_id'], array_filter($rx['tests'], static fn (array $t): bool => $t['test_id'] !== null));
$extra = array_values(array_filter($rx['tests'], static fn (array $t): bool => $t['test_id'] === null));
$vit = (array) $rx['vitals'];
$cats = ClinicConfig::TEST_CATS;
?>
<article class="rxp rxp-<?= esc($v['style'], 'attr') ?> rxp-<?= esc($v['paper'], 'attr') ?><?= $edit ? ' rxp-edit' : '' ?>" style="--rx-accent:<?= css_color($v['accent']) ?>;--rx-fs:<?= rtrim(rtrim(number_format((float) $v['font'], 2, '.', ''), '0'), '.') ?>pt;--rx-mt:<?= $v['margins'][0] ?>mm;--rx-mr:<?= $v['margins'][1] ?>mm;--rx-mb:<?= $v['margins'][2] ?>mm;--rx-ml:<?= $v['margins'][3] ?>mm">
  <?php if ($rx['status'] !== 'final'): ?><div class="rxp-mark" aria-hidden="true"><?= $rx['status'] === 'draft' ? 'DRAFT' : 'SUPERSEDED' ?></div><?php endif ?>

  <?php if ($v['header'] === 'image'): ?>
    <img class="rxp-himg" src="<?= esc((string) $v['headerImg'], 'attr') ?>" alt="<?= esc($doc['name'] . ' — ' . $cl['name'], 'attr') ?>">
  <?php elseif ($v['header'] === 'letterhead'): ?>
    <div class="rxp-lh" aria-hidden="true"><?= $edit ? 'Pre-printed letterhead — this space prints blank' : '' ?></div>
  <?php else: ?>
    <header class="rxp-head<?= $v['pad']['doctor_block'] === 'right' ? ' rev' : '' ?>">
      <div class="rxp-dr">
        <h1><?= esc($doc['name']) ?></h1>
        <?php if ($doc['degrees'] !== ''): ?><p class="rxp-deg"><?= esc($doc['degrees']) ?></p><?php endif ?>
        <?php if ($doc['specialty'] !== ''): ?><p class="rxp-spec"><?= esc($doc['specialty']) ?></p><?php endif ?>
        <?php if ($doc['designation'] !== ''): ?><p><?= esc($doc['designation']) ?></p><?php endif ?>
        <?php if ($doc['reg_no'] !== ''): ?><p class="rxp-reg">Reg. No: <?= esc($doc['reg_no']) ?></p><?php endif ?>
      </div>
      <div class="rxp-cl">
        <b><?= esc($cl['name']) ?></b>
        <?php if ($cl['chamber'] !== ''): ?><p><?= esc($cl['chamber']) ?></p><?php endif ?>
        <?php foreach ($cl['timings'] as $t): ?><p class="rxp-time"><?= esc($t) ?></p><?php endforeach ?>
        <?php if ($cl['phone'] !== ''): ?><p>Phone: <?= esc($cl['phone']) ?></p><?php endif ?>
        <?php if ($cl['extra'] !== ''): ?><p class="rxp-extra"><?= $text($cl['extra']) ?></p><?php endif ?>
      </div>
    </header>
  <?php endif ?>

  <div class="rxp-pt">
    <?php if ($edit): ?>
      <label class="rxp-f grow"><span>Name</span><input class="rxd rxd-strong" name="pt[name]" value="<?= esc($old('pt.name', $ps['name'] ?? ''), 'attr') ?>" maxlength="120" aria-label="Patient name on this prescription"></label>
      <label class="rxp-f"><span>Age</span><input class="rxd rxd-age" name="pt[age]" value="<?= esc($old('pt.age', $ps['age'] ?? ''), 'attr') ?>" inputmode="numeric" maxlength="3" aria-label="Age in years"></label>
      <label class="rxp-f"><span>Sex</span><select class="rxd rxd-sel" name="pt[sex]" aria-label="Sex"><option value="">—</option><?php foreach (['male' => 'M', 'female' => 'F', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= ($old('pt.sex', $ps['sex'] ?? '')) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
    <?php else: ?>
      <span class="rxp-f grow"><span>Name</span><b><?= esc((string) ($ps['name'] ?? '')) ?></b></span>
      <?php if (isset($ps['age']) && $ps['age'] !== null && $ps['age'] !== ''): ?><span class="rxp-f"><span>Age</span><b><?= (int) $ps['age'] ?> y</b></span><?php endif ?>
      <?php if (! empty($ps['sex'])): ?><span class="rxp-f"><span>Sex</span><b><?= esc(ucfirst((string) $ps['sex'])) ?></b></span><?php endif ?>
    <?php endif ?>
    <span class="rxp-f"><span>Date</span><b><?= esc(date('d M Y', (int) strtotime((string) $rx['visit_date']))) ?></b></span>
    <span class="rxp-f"><span>ID</span><b><?= esc((string) ($ps['code'] ?? '')) ?></b></span>
    <?php if ($booking !== null && $booking['serial_no']): ?><span class="rxp-f"><span>Serial</span><b><?= (int) $booking['serial_no'] ?></b></span><?php endif ?>
  </div>
  <?php if ($edit): ?>
    <div class="rxp-vit rxp-vit-edit" aria-label="Vitals (printed only when filled)">
      <?php foreach (PrescriptionService::VITALS as $k => $l): ?><label><span><?= esc($l) ?></span><input class="rxd" name="vitals[<?= $k ?>]" value="<?= esc($old('vitals.' . $k, $vit[$k] ?? ''), 'attr') ?>" maxlength="30" placeholder="—"></label><?php endforeach ?>
      <span class="rxp-vit-hint">Vitals: fill them in the side card — only filled ones print</span>
    </div>
  <?php elseif (PadView::vitalsText($vit) !== ''): ?>
    <div class="rxp-vit"><?= esc(PadView::vitalsText($vit)) ?></div>
  <?php endif ?>

  <div class="rxp-body">
    <aside class="rxp-l">
      <?= $block('chief_complaints', 'Chief complaints', 'Type the complaints') ?>
      <?= $block('history', 'History', 'History') ?>
      <?= $block('examination', 'On examination', 'Findings', 2) ?>
      <?= $block('diagnosis', 'Diagnosis', 'Diagnosis') ?>
      <?php if ($edit): ?>
        <section class="rxp-sec rxp-inv"><h4>Investigations</h4>
          <?php if (! $tests): ?><p class="rxp-hint">Your test list is empty<?= service('posSession')->can('clinic.manage') ? ' — add tests in <a href="' . esc(site_url('clinic/setup') . '#tests', 'attr') . '" target="_blank" rel="noopener">Clinic setup</a>' : '' ?>.</p><?php endif ?>
          <?php foreach ($cats as $ck => $cn): $set = array_filter($tests, static fn (array $t): bool => $t['category'] === $ck); if (! $set) { continue; } ?>
            <div class="rxp-tg"><small><?= esc($cn) ?></small><?php foreach ($set as $t): ?><label class="rxp-test"><input type="checkbox" name="tests[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $picked, true) ? 'checked' : '' ?>><span><?= esc($t['name']) ?></span></label><?php endforeach ?></div>
          <?php endforeach ?>
          <ul class="rxp-xt" data-xtests data-next="<?= count($extra) ?>"><?php foreach ($extra as $i => $t): ?>
            <li><input class="rxd" name="extra_tests[<?= $i ?>][name]" value="<?= esc($t['name'], 'attr') ?>" maxlength="160" aria-label="Test"><input type="hidden" name="extra_tests[<?= $i ?>][category]" value="<?= esc($t['category'], 'attr') ?>"><button class="rxp-tb del" type="button" data-xdel aria-label="Remove test">&#215;</button></li>
          <?php endforeach ?></ul>
          <button class="rxp-add" type="button" data-xadd>+ Type a test</button>
        </section>
      <?php elseif ($rx['tests']): ?>
        <section class="rxp-sec"><h4>Investigations</h4><ul class="rxp-list"><?php foreach ($rx['tests'] as $t): ?><li><?= esc($t['name']) ?><?= $t['note'] ? ' <small>(' . esc($t['note']) . ')</small>' : '' ?></li><?php endforeach ?></ul></section>
      <?php endif ?>
    </aside>

    <main class="rxp-r">
      <div class="rxp-rx" aria-hidden="true">R<span>x</span></div>
      <?php if ($edit): ?>
        <?php foreach (session('errors') ?? [] as $k => $msg): if (str_starts_with((string) $k, 'items')): ?><p class="field-err"><?= esc((string) $msg) ?></p><?php endif; endforeach ?>
        <ol class="rxp-meds" id="rxp-meds" data-next="<?= count($items) ?>"><?php foreach ($items as $i => $it): ?><?= $med((string) $i, $it) ?><?php endforeach ?></ol>
        <button class="rxp-add" type="button" data-medadd>+ Add medicine <small>(Enter on the last field)</small></button>
        <template id="rxp-med-tpl"><?= $med('__i__', []) ?></template>
      <?php elseif ($items): ?>
        <ol class="rxp-meds"><?php foreach ($items as $it): ?><?= $med('', $it) ?><?php endforeach ?></ol>
      <?php endif ?>
      <?= $block('advice', 'Advice', 'Advice for the patient', 3) ?>
      <?= $block('notes', 'Notes', 'Notes (printed)') ?>
      <?php if ($edit): ?>
        <section class="rxp-sec rxp-fu"><h4>Follow-up</h4><div class="rxp-fu-row"><input class="rxd rxd-date" type="date" name="follow_up" min="<?= esc(date('Y-m-d'), 'attr') ?>" value="<?= esc($old('follow_up', $rx['follow_up']), 'attr') ?>" aria-label="Follow-up date">
          <input class="rxd" name="follow_up_note" maxlength="200" value="<?= esc($old('follow_up_note', $rx['follow_up_note']), 'attr') ?>" placeholder="e.g. with reports" aria-label="Follow-up note"></div><?= field_error('follow_up') ?></section>
      <?php elseif ($rx['follow_up'] || $rx['follow_up_note']): ?>
        <section class="rxp-sec"><h4>Follow-up</h4><div class="rxp-txt"><?= $rx['follow_up'] ? esc(date('d M Y', (int) strtotime((string) $rx['follow_up']))) : '' ?><?= $rx['follow_up_note'] ? ' · ' . esc($rx['follow_up_note']) : '' ?></div></section>
      <?php endif ?>
    </main>
  </div>

  <footer class="rxp-foot">
    <div class="rxp-signs"><div class="rxp-sign">
      <?php if ($v['signature'] !== null && ($rx['status'] === 'final' || $edit)): ?><img src="<?= esc($v['signature'], 'attr') ?>" alt="Signature of <?= esc($doc['name'], 'attr') ?>"><?php endif ?>
      <b><?= esc($doc['name']) ?></b><?php if ($doc['degrees'] !== ''): ?><small><?= esc($doc['degrees']) ?></small><?php endif ?>
    </div></div>
    <?php if ($v['footerImg']): ?><img class="rxp-fimg" src="<?= esc((string) $v['footerImg'], 'attr') ?>" alt=""><?php endif ?>
    <?php if ($v['footerText'] !== ''): ?><div class="rxp-ftext"><?= $text($v['footerText']) ?></div><?php endif ?>
    <div class="rxp-meta"><span><?= esc($rx['rx_no']) ?><?= (int) $rx['revision'] > 1 ? ' · revision ' . (int) $rx['revision'] : '' ?></span><span><?= $rx['finalized_at'] ? esc(datetime_str($rx['finalized_at'])) : 'Not finalized' ?></span></div>
  </footer>
</article>
