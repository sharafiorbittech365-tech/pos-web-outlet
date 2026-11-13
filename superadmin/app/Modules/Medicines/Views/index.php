<?php
/**
 * Super Admin › Medicine database: stats, import (with dry-run report), the list.
 *
 * @var string                    $table
 * @var array<string, mixed>      $stats
 * @var list<string>              $fields
 * @var list<string>              $required
 * @var ?array<string, mixed>     $report last import (flash)
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><p class="page-sub">Platform-wide reference list used by the Pharmacy module (“Add from medicine database”). Shops link their own products to it; they never change it.</p><div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('medicines/template.csv'), 'attr') ?>"><?= icon('download', 'sm') ?>CSV template</a>
    <button class="btn primary" type="button" data-modal-open="med-import"><?= icon('file', 'sm') ?>Import dataset</button></div>

  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'pill', 'label' => 'Medicines', 'value' => number_format($stats['total']) . ($stats['active'] < $stats['total'] ? ' (' . number_format($stats['active']) . ' on)' : '')]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'tag', 'label' => 'Generics', 'value' => number_format($stats['generics'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'store', 'label' => 'Manufacturers', 'value' => number_format($stats['manufacturers'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'box', 'label' => 'Linked shop products', 'value' => number_format($stats['linked'])]) ?>
  </div>

  <?php if (is_array($report)): ?>
    <section class="card" <?= $report['invalid'] > 0 ? 'role="alert"' : '' ?>>
      <div class="card-h"><h3><?= icon($report['invalid'] > 0 ? 'alert' : 'check', 'sm') ?> <?= $report['dry_run'] ? 'Dry run report' : 'Import report' ?></h3><span class="muted"><?= esc((string) ($report['file'] ?? '')) ?></span></div>
      <div style="padding:10px 14px 14px;font-size:13px">
        <p style="margin-top:0"><b><?= number_format($report['rows']) ?></b> row(s) read · <b><?= number_format($report['valid']) ?></b> valid · <?= number_format($report['inserted']) ?> new · <?= number_format($report['updated']) ?> updated · <?= number_format($report['unchanged']) ?> unchanged · <b class="<?= $report['invalid'] > 0 ? 'neg' : '' ?>"><?= number_format($report['invalid']) ?></b> with errors (skipped).</p>
        <?php if ($report['dry_run']): ?><p>Nothing was saved. Upload the file again without “Dry run” to import the valid rows<?= $report['invalid'] > 0 ? ' (rows with errors are skipped — fix them first if they matter)' : '' ?>.</p><?php endif ?>
        <?php if ($report['errors'] !== []): ?>
          <div class="tbl-wrap"><table class="tbl"><thead><tr><th class="num">Line</th><th>brand_id</th><th>Problem</th></tr></thead><tbody>
            <?php foreach ($report['errors'] as $e): ?><tr><td class="num"><?= (int) $e['line'] ?></td><td><code><?= esc((string) $e['brand_id']) ?></code></td><td><?= esc((string) $e['message']) ?></td></tr><?php endforeach ?>
          </tbody></table></div>
          <?php if ($report['errors_total'] > count($report['errors'])): ?><p class="muted">… and <?= number_format($report['errors_total'] - count($report['errors'])) ?> more.</p><?php endif ?>
        <?php endif ?>
      </div>
    </section>
  <?php endif ?>

  <section class="card"><?= $table ?></section>

  <section class="card"><div class="card-h"><h3>File format</h3></div>
    <div style="padding:10px 14px 14px;font-size:13px">
      <p style="margin-top:0">CSV (UTF-8; comma, semicolon or tab separated) with a header row naming exactly these ten columns, in any order — or JSON: a list of objects with the same keys (or <code>{"data": [...]}</code>).</p>
      <p><code><?= esc(implode(', ', $fields)) ?></code></p>
      <p class="muted" style="margin-bottom:0">Required: <code><?= esc(implode('</code>, <code>', $required), 'raw') ?></code>. <code>brand_id</code> is the dataset's own id and the import key: re-importing updates changed rows and skips identical ones (nothing is deleted; enabled/disabled is kept). <code>slug</code> must be unique (lower-case letters, digits, <code>- _ .</code>); when empty it is made from brand name, strength and dosage form. Rows with errors (missing fields, a repeated brand_id, a slug used by another brand_id) are reported and skipped. Large files: <code>php spark medicines:import file.csv [--dry-run]</code>.</p>
    </div></section>
</div>

<dialog class="modal-dlg" id="med-import"><form method="post" action="<?= esc(site_url('medicines/import'), 'attr') ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="modal-h"><h3>Import the medicine dataset</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b"><div class="form-grid">
    <label class="field span2">File (.csv or .json, max 30 MB)<input class="input" type="file" name="file" accept=".csv,.json,text/csv,application/json" required><?= field_error('file') ?></label>
    <label class="check span2"><input type="hidden" name="dry_run" value="0"><input type="checkbox" name="dry_run" value="1" checked>Dry run — check the file and show the report, save nothing</label>
  </div></div>
  <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('file', 'sm') ?>Import</button></div>
</form></dialog>
