<?php
/**
 * @var list<string> $columns
 * @var array|null   $result  {created, updated, skipped}
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Import products from CSV</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('products'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Products</a></p></div>
  </div>
  <?php if (is_array($result)): ?>
    <div class="stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
      <div class="stat"><small>Created</small><b class="pos"><?= (int) $result['created'] ?></b></div>
      <div class="stat"><small>Updated</small><b><?= (int) $result['updated'] ?></b></div>
      <div class="stat"><small>Skipped</small><b class="<?= $result['skipped'] ? 'neg' : '' ?>"><?= count($result['skipped']) ?></b></div>
    </div>
    <?php if ($result['skipped']): ?><section class="card"><div class="card-b muted bo-note"><?= implode('<br>', array_map('esc', array_slice($result['skipped'], 0, 20))) ?><?= count($result['skipped']) > 20 ? '<br>…' : '' ?></div></section><?php endif ?>
  <?php endif ?>
  <section class="card"><div class="card-b">
    <p class="muted" style="margin:0">Columns (first row = headers): <code><?= esc(implode(', ', $columns)) ?></code>. Only <b>name</b> and <b>price</b> are required.</p>
    <ul class="imp-rules">
      <li>A row whose <b>sku</b> (else barcode, else name) matches an existing product <b>updates</b> it; otherwise a new product is <b>created</b>.</li>
      <li>Unknown categories are created automatically.</li>
      <li><b>stock</b> sets the quantity of simple-count products (logged as a stock movement). Serial, option and batch stock stay in Inventory.</li>
    </ul>
    <form class="row-add" method="post" action="<?= esc(site_url('products/import'), 'attr') ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <a class="btn" href="<?= esc(site_url('products/export'), 'attr') ?>"><?= icon('download', 'sm') ?>Download current list as template</a>
      <input class="input" type="file" name="file" accept=".csv,text/csv" required>
      <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Import</button>
    </form>
  </div></section>
</div>
