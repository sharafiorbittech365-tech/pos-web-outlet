<?php
/**
 * Super Admin > Locations: countries with their dataset size, add / edit a country, import a
 * CSV / JSON dataset (LocationImporter; format in docs/modules/locations.md).
 *
 * @var string               $table
 * @var list<array>          $rows    countries on this page (edit dialogs)
 * @var list<string>         $columns CSV columns
 * @var ?array<string, mixed> $result last import (flash)
 */
helper('ui');
$errors = (array) (session('errors') ?? []);
$lineErrors = array_filter($errors, static fn ($k): bool => str_starts_with((string) $k, 'line ') || $k === 'more' || $k === 'file' || str_starts_with((string) $k, 'country'), ARRAY_FILTER_USE_KEY);
$dialog = static function (?array $k): string {
    $isNew = $k === null;
    $k ??= ['code' => '', 'name' => '', 'name_bn' => '', 'phone_code' => '', 'city_label' => '', 'area_label' => ''];
    $f = static fn (string $name, string $label, int $max, string $ph = '', string $extra = ''): string => '<label class="field">' . $label
        . '<input class="input" name="' . $name . '" maxlength="' . $max . '" placeholder="' . esc($ph, 'attr') . '" value="' . esc((string) ($k[$name] ?? ''), 'attr') . '" ' . $extra . '></label>';

    return '<dialog class="modal-dlg" id="cty-' . esc($isNew ? 'new' : $k['code'], 'attr') . '"><form method="post" action="' . esc(site_url($isNew ? 'locations/countries' : 'locations/' . $k['code']), 'attr') . '">' . csrf_field()
        . '<div class="modal-h"><h3>' . ($isNew ? 'Add country' : 'Edit ' . esc($k['name'])) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal-b"><div class="form-grid">'
        . ($isNew ? $f('code', 'ISO code', 2, 'BD', 'required pattern="[A-Za-z]{2}"') : '<input type="hidden" name="code" value="' . esc($k['code'], 'attr') . '"><label class="field">ISO code<input class="input" value="' . esc($k['code'], 'attr') . '" disabled></label>')
        . $f('name', 'Name', 80, 'Bangladesh', 'required')
        . $f('name_bn', 'Local name (optional)', 80)
        . $f('phone_code', 'Dialling code', 8, '+880')
        . $f('city_label', 'Middle level is called', 24, 'District')
        . $f('area_label', 'Lowest level is called', 24, 'Thana')
        . '</div></div><div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">' . ($isNew ? 'Add country' : 'Save') . '</button></div></form></dialog>';
};
?>
<div class="page">
  <div class="page-h"><p class="page-sub">Country › City/District › Area/Thana lists for checkout address pickers. Disabled entries are hidden from customers; orders keep their address.</p><div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('locations/template.csv'), 'attr') ?>"><?= icon('download', 'sm') ?>CSV template</a>
    <button class="btn" type="button" data-modal-open="cty-new"><?= icon('plus', 'sm') ?>Add country</button>
    <button class="btn primary" type="button" data-modal-open="loc-import"><?= icon('file', 'sm') ?>Import dataset</button></div>

  <?php if ($lineErrors !== []): ?>
    <section class="card" role="alert"><div class="card-h"><h3><?= icon('alert', 'sm') ?> Import problems</h3><span class="muted">Nothing was imported</span></div>
      <ul style="margin:0;padding:10px 14px 14px 32px;font-size:13px"><?php foreach ($lineErrors as $where => $msg): ?><li><b><?= esc((string) $where) ?>:</b> <?= esc(is_array($msg) ? implode(' ', $msg) : (string) $msg) ?></li><?php endforeach ?></ul></section>
  <?php endif ?>
  <?php if (is_array($result) && ! empty($result['dry_run'])): ?>
    <section class="card"><div class="card-h"><h3><?= icon('check', 'sm') ?> Dry run passed</h3><span class="muted"><?= esc((string) $result['country']) ?></span></div>
      <p style="margin:0;padding:10px 14px 14px">The file is valid. Upload it again without "Dry run" to save it.</p></section>
  <?php endif ?>

  <section class="card"><?= $table ?></section>

  <section class="card"><div class="card-h"><h3>File format</h3></div>
    <div style="padding:10px 14px 14px;font-size:13px">
      <p style="margin-top:0">CSV (UTF-8, comma or semicolon separated) with a header row. One row per area; rows with an empty <code>area</code> add a city/district only. Only <code>city</code> is required.</p>
      <p><code><?= esc(implode(', ', $columns)) ?></code></p>
      <p class="muted" style="margin-bottom:0"><code>city_kind</code>: city | district · <code>area_kind</code>: area | thana | upazila · codes: your dataset's own ids (letters, digits, <code>.-_</code>) — re-imports match on them, else on the name. Re-importing is safe: existing entries are updated, nothing is deleted, enabled/disabled is kept. JSON: the same rows as a list, or <code>{"country": {…}, "cities": [{"name", "kind", "region", "code", "areas": [{"name", "kind", "postal_code"}]}]}</code>.</p>
    </div></section>
</div>

<dialog class="modal-dlg" id="loc-import"><form method="post" action="<?= esc(site_url('locations/import'), 'attr') ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="modal-h"><h3>Import a location dataset</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b"><div class="form-grid">
    <label class="field">Country ISO code<input class="input" name="code" maxlength="2" required pattern="[A-Za-z]{2}" placeholder="BD" value="<?= esc(old_or('code'), 'attr') ?>"><?= field_error('code') ?></label>
    <label class="field">Country name <small class="muted">(new countries)</small><input class="input" name="name" maxlength="80" placeholder="Bangladesh" value="<?= esc(old_or('name'), 'attr') ?>"></label>
    <label class="field">Middle level is called<input class="input" name="city_label" maxlength="24" placeholder="District" value="<?= esc(old_or('city_label'), 'attr') ?>"></label>
    <label class="field">Lowest level is called<input class="input" name="area_label" maxlength="24" placeholder="Thana" value="<?= esc(old_or('area_label'), 'attr') ?>"></label>
    <label class="field span2">File (.csv or .json, max 20 MB)<input class="input" type="file" name="file" accept=".csv,.json,text/csv,application/json" required></label>
    <label class="check span2"><input type="hidden" name="dry_run" value="0"><input type="checkbox" name="dry_run" value="1" checked>Dry run — check the file and show the counts, save nothing</label>
  </div></div>
  <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('file', 'sm') ?>Import</button></div>
</form></dialog>
<?= $dialog(null) ?>
<?php foreach ($rows as $r): ?><?= $dialog($r) ?><?php endforeach ?>
