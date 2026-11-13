<?php
/**
 * Products › Print labels. assets/pos/labels.js draws the labels (Code 128 barcode, QR code) from
 * the JSON below and prints them through assets/pos/print.js.
 *
 * @var list<array<string, mixed>> $items    LabelService::items()
 * @var list<int>                  $selected product ids chosen on the product list
 * @var array<string, mixed>       $template LabelService::template()
 * @var bool                       $canManage
 */
use App\Modules\Products\Services\LabelService;

helper('ui');
$s = service('posSession');
$b = $s->business();
$cfg = [
    'items' => $items, 'selected' => $selected, 'template' => $template, 'presets' => LabelService::PRESETS,
    'shop' => (string) ($b['name'] ?? ''), 'currency' => $s->currency(), 'canManage' => $canManage,
    'saveUrl' => site_url('products/labels/design'),
];
$t = $template;
$v = static fn (string $f): string => is_file(FCPATH . $f) ? (string) filemtime(FCPATH . $f) : '1';
?>
<div class="page lbl-page">
  <div class="page-h">
    <div><h2>Print labels</h2><p class="page-sub">Barcode and QR labels that scan straight back to the product at the till.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('products'), 'attr') ?>"><?= icon('back', 'sm') ?>Products</a>
  </div>

  <div class="lbl-grid">
    <section class="card lbl-items">
      <div class="card-h"><h3>Items</h3><span class="muted" id="lbl-count">0 labels</span></div>
      <div class="lbl-tools">
        <label class="search"><?= icon('search', 'sm') ?><input type="search" id="lbl-q" placeholder="Search name, code or category…" autocomplete="off" aria-label="Search items"></label>
        <button class="btn sm" type="button" data-lbl="pick-shown">Select shown</button>
        <button class="btn sm" type="button" data-lbl="qty-stock" title="One label per item in stock">Qty = stock</button>
        <button class="btn sm" type="button" data-lbl="clear">Clear</button>
      </div>
      <div class="lbl-list" id="lbl-list" role="list"></div>
      <?php if ($canManage): ?>
        <form method="post" action="<?= esc(site_url('products/labels/codes'), 'attr') ?>" id="lbl-codes" class="lbl-missing" hidden>
          <?= csrf_field() ?>
          <span><?= icon('alert', 'sm') ?> <b id="lbl-missing-n">0</b> selected item(s) have no barcode or SKU, so their label would not scan.</span>
          <button class="btn sm primary" type="submit">Create missing codes</button>
        </form>
      <?php endif ?>
    </section>

    <section class="card lbl-serial">
      <div class="card-h"><h3>Serial codes</h3><span class="muted" id="lbl-serial-n">none queued</span></div>
      <div class="card-b">
        <p class="muted" style="margin:0 0 10px;font-size:13px">Print a run of numbered barcodes or QR codes — asset tags, bags, shelves, tickets — as many as fit on each sheet, in order. They print after any product labels above.</p>
        <form id="lbl-serial" class="form-grid" onsubmit="return false">
          <label class="field">Prefix<input class="input" name="prefix" maxlength="16" placeholder="e.g. TAG-" value=""></label>
          <label class="field">Start at<input class="input" type="number" name="from" min="0" max="999999999" value="1"></label>
          <label class="field">How many<input class="input" type="number" name="count" min="1" max="2000" value="24"></label>
          <label class="field">Digits (zero padded)<input class="input" type="number" name="pad" min="0" max="10" value="4"></label>
          <label class="field">Step<input class="input" type="number" name="step" min="1" max="1000" value="1"></label>
          <label class="field">Copies of each<input class="input" type="number" name="copies" min="1" max="20" value="1"></label>
          <label class="field span2">Caption on every label (optional)<input class="input" name="caption" maxlength="60" placeholder="e.g. Property of Digital Zone"></label>
          <div class="field span2 lbl-serial-prev"><small class="muted">First codes</small><code id="lbl-serial-prev">TAG-0001, TAG-0002, TAG-0003 …</code></div>
        </form>
        <div class="lbl-actions">
          <button class="btn primary" type="button" data-lbl="serial-add"><?= icon('plus', 'sm') ?>Queue serial codes</button>
          <button class="btn" type="button" data-lbl="serial-clear">Clear serials</button>
        </div>
      </div>
    </section>

    <section class="card lbl-design">
      <div class="card-h"><h3>Label design</h3></div>
      <div class="card-b">
        <form id="lbl-form" class="form-grid" onsubmit="return false">
          <label class="field span2">Label size<select class="select" name="preset"><?php foreach (LabelService::PRESETS as $k => $p): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($t['preset'], $k) ?>><?= esc($p['label']) ?></option><?php endforeach ?></select></label>
          <label class="field lbl-custom">Width (mm)<input class="input" type="number" name="w" min="15" max="120" step="0.5" value="<?= esc((string) $t['w'], 'attr') ?>"></label>
          <label class="field lbl-custom">Height (mm)<input class="input" type="number" name="h" min="10" max="120" step="0.5" value="<?= esc((string) $t['h'], 'attr') ?>"></label>
          <label class="field lbl-sheetcustom">Columns across<input class="input" type="number" name="cols" min="1" max="8" value="<?= (int) ($t['cols'] ?? 3) ?>"></label>
          <label class="field lbl-sheetonly">Start at label no.<input class="input" type="number" name="start" min="1" max="200" value="1" title="Re-use a part-used sheet: skip this many labels first"></label>
          <label class="field">Code<select class="select" name="codeType"><?php foreach (LabelService::CODES as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($t['codeType'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="field">Text size (pt)<input class="input" type="number" name="fontSize" min="6" max="16" value="<?= (int) $t['fontSize'] ?>"></label>
          <div class="field span2">Show
            <div class="lbl-fields"><?php foreach (LabelService::FIELDS as $k => $l): ?><label class="check"><input type="checkbox" name="fields[<?= $k ?>]" value="1" <?= checked(! empty($t['fields'][$k])) ?>><?= esc($l) ?></label><?php endforeach ?></div>
          </div>
          <div class="field span2">Colours
            <div class="lbl-colors">
              <label>Text<input type="color" name="colors[text]" value="<?= esc($t['colors']['text'], 'attr') ?>"></label>
              <label>Price<input type="color" name="colors[price]" value="<?= esc($t['colors']['price'], 'attr') ?>"></label>
              <label>Background<input type="color" name="colors[bg]" value="<?= esc($t['colors']['bg'], 'attr') ?>"></label>
              <label>Border<input type="color" name="colors[border]" value="<?= esc($t['colors']['border'], 'attr') ?>"></label>
              <label class="check"><input type="checkbox" name="border" value="1" <?= checked(! empty($t['border'])) ?>>Draw border</label>
            </div>
          </div>
        </form>
        <div class="lbl-preview-wrap"><small class="muted">Preview (actual size)</small><div id="lbl-preview" class="lbl-preview"></div></div>
        <div class="lbl-actions">
          <button class="btn primary" type="button" data-lbl="print" disabled><?= icon('print', 'sm') ?><span>Print labels</span></button>
          <?php if ($canManage): ?><button class="btn" type="button" data-lbl="save"><?= icon('check', 'sm') ?>Save design</button><?php endif ?>
        </div>
        <p class="muted" id="lbl-pages" style="margin:8px 0 0;font-size:13px"></p>
        <p class="muted lbl-note">Roll sizes print one label per page — choose the label printer and its label size in the print dialog. Sheets print on A4 label paper. Codes are Code 128 / QR, read by any scanner.</p>
      </div>
    </section>
  </div>
</div>
<script type="application/json" id="lbl-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= esc(base_url('assets/pos/vendor/qrcode-generator-1.4.4.js') . '?v=' . $v('assets/pos/vendor/qrcode-generator-1.4.4.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/labels.js') . '?v=' . $v('assets/pos/labels.js'), 'attr') ?>" defer></script>
