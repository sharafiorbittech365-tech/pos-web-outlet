<?php
/**
 * Setup › Outlets › Import products into an outlet from another outlet of the same business type.
 *
 * @var array<string, mixed>       $target   the outlet receiving the products
 * @var list<array<string, mixed>> $sources  outlets of the same business type (with product counts)
 * @var array<string, mixed>|null  $source   the chosen one
 * @var list<array<string, mixed>> $products the source's products (ProductImport::catalogue)
 * @var array<string, mixed>       $cur      currency of the source outlet
 * @var int                        $max
 */
helper('ui');
$asset = static fn (string $f): string => base_url('assets/pos/' . $f) . '?v=' . (is_file(FCPATH . 'assets/pos/' . $f) ? filemtime(FCPATH . 'assets/pos/' . $f) : 0);
$picSrc = static fn (string $a): string => preg_match('#^https?://#i', $a) === 1 ? $a : base_url(ltrim($a, '/'));
$num = static fn (mixed $n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
$cats = array_values(array_unique(array_filter(array_map(static fn (array $p): string => (string) ($p['category'] ?? ''), $products))));
$new = array_filter($products, static fn (array $p): bool => ! $p['exists']);
$stockTypes = ['simple' => 'Stock', 'variant' => 'Size × colour', 'serial' => 'Serial / IMEI', 'batch' => 'Batch / expiry', 'none' => 'Not stocked'];
?>
<link rel="stylesheet" href="<?= esc($asset('outlets.css'), 'attr') ?>">
<div class="page imp">
  <div class="page-h">
    <div><h2>Import products into <?= esc((string) $target['name']) ?></h2>
      <p class="page-sub"><a class="link" href="<?= esc(site_url('settings/outlets'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Outlets</a> · copy products from another <?= esc((string) $target['type_name']) ?> outlet instead of adding them one by one.</p></div>
  </div>

  <?php if ($sources === []): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('store') ?></span><b>No outlet to copy from</b>
      <span>Products can be imported only from an open outlet with the same business type (<?= esc((string) $target['type_name']) ?>) that you can open.</span>
      <a class="btn" href="<?= esc(site_url('settings/outlets'), 'attr') ?>">Back to outlets</a></div></div>
  <?php else: ?>

  <section class="card"><div class="card-b">
    <form method="get" class="imp-src" aria-label="Outlet to copy from">
      <b class="imp-step">1</b>
      <div class="grow"><b>Copy from</b><br><small class="muted">Only outlets of the same business type are listed.</small></div>
      <div class="imp-src-list">
        <?php foreach ($sources as $s): $on = $source !== null && (int) $source['id'] === (int) $s['id']; ?>
          <button class="imp-src-o <?= $on ? 'on' : '' ?>" type="submit" name="from" value="<?= (int) $s['id'] ?>" <?= $on ? 'aria-current="true"' : '' ?>>
            <span class="biz-logo emo"><?= sym_icon((string) ($s['type_icon'] ?? 'store')) ?></span>
            <span><b><?= esc((string) $s['name']) ?></b><small class="muted"><?= esc(trim((string) ($s['area'] ?? '')) ?: (string) $s['type_name']) ?> · <?= (int) $s['products'] ?> products</small></span>
            <?= $on ? icon('check', 'sm') : '' ?>
          </button>
        <?php endforeach ?>
      </div>
    </form>
  </div></section>

  <?php if ($source === null): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('package') ?></span><b>Choose the outlet to copy from</b><span>Its products appear here — select the ones you want and set their opening stock.</span></div></div>
  <?php elseif ($products === []): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('package') ?></span><b><?= esc((string) $source['name']) ?> has no products yet</b></div></div>
  <?php else: ?>

  <form method="post" action="<?= esc(site_url('settings/outlets/' . (int) $target['id'] . '/import'), 'attr') ?>" class="card imp-form" data-import>
    <?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= (int) $source['id'] ?>">
    <input type="hidden" name="items" value="" data-items>
    <div class="card-h imp-head">
      <div class="imp-head-t"><b class="imp-step">2</b><div><h3>Choose products &amp; opening stock</h3>
        <small class="muted"><?= count($products) ?> products in <?= esc((string) $source['name']) ?><?= count($products) > count($new) ? ' · ' . (count($products) - count($new)) . ' already in ' . esc((string) $target['name']) : '' ?></small></div></div>
    </div>

    <div class="imp-tools">
      <label class="check imp-all-m"><input type="checkbox" data-imp-all <?= $new === [] ? 'disabled' : '' ?>>Select all</label>
      <label class="imp-search"><?= icon('search', 'sm') ?><input class="input" type="search" placeholder="Search name, SKU or barcode" data-imp-q aria-label="Search products"></label>
      <?php if (count($cats) > 1): ?>
        <select class="select imp-cat" data-imp-cat aria-label="Category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option><?= esc($c) ?></option><?php endforeach ?></select>
      <?php endif ?>
      <div class="imp-bulk" role="group" aria-label="Opening stock for the selected products">
        <span class="muted">Opening stock for selected</span>
        <input class="input" type="number" min="0" step="any" value="0" inputmode="decimal" data-imp-bulk aria-label="Opening stock">
        <button class="btn sm" type="button" data-imp-apply>Apply</button>
        <button class="btn sm" type="button" data-imp-copy title="Use each product's current stock in <?= esc((string) $source['name'], 'attr') ?>">Use current stock</button>
      </div>
    </div>

    <div class="table-wrap">
      <table class="tbl imp-tbl">
        <thead><tr>
          <th class="imp-ck"><input type="checkbox" data-imp-all aria-label="Select all products" <?= $new === [] ? 'disabled' : '' ?>></th>
          <th>Product</th><th>Category</th><th class="num">Price</th><th class="num">Stock in <?= esc((string) $source['name']) ?></th><th>Opening stock here</th>
        </tr></thead>
        <tbody>
        <?php foreach ($products as $p): $id = (int) $p['id']; $type = (string) $p['stock_type']; $img = (string) ($p['image_path'] ?? ''); ?>
          <tr class="<?= $p['exists'] ? 'imp-has' : '' ?>" data-row data-id="<?= $id ?>" data-type="<?= esc($type, 'attr') ?>" data-cat="<?= esc((string) ($p['category'] ?? ''), 'attr') ?>"
              data-q="<?= esc(mb_strtolower(trim($p['name'] . ' ' . ($p['sku'] ?? '') . ' ' . ($p['barcode'] ?? ''))), 'attr') ?>">
            <td class="imp-ck"><input type="checkbox" name="ids[]" value="<?= $id ?>" data-imp-ck aria-label="Import <?= esc((string) $p['name'], 'attr') ?>" <?= $p['exists'] ? 'disabled data-has' : '' ?>></td>
            <td><div class="imp-p">
              <span class="imp-pic"><?= $img !== '' ? '<img src="' . esc($picSrc($img), 'attr') . '" alt="" loading="lazy">' : sym_icon((string) ($p['icon'] ?: 'package')) ?></span>
              <span class="imp-pn"><b><?= esc((string) $p['name']) ?></b>
                <small class="muted"><?= esc(implode(' · ', array_filter([(string) ($p['sku'] ?? ''), $stockTypes[$type] ?? $type, (int) $p['is_active'] ? '' : 'Inactive']))) ?></small>
                <?= $p['exists'] ? '<span class="tag">Already in ' . esc((string) $target['name']) . '</span>' : '' ?></span>
            </div></td>
            <td><?= esc((string) ($p['category'] ?? '—')) ?></td>
            <td class="num"><?= esc(money($p['price'], $cur)) ?></td>
            <td class="num"><?= in_array($type, ['none'], true) ? '—' : esc($num($p['stock_qty'])) . ($p['unit'] ? ' <small class="muted">' . esc((string) $p['unit']) . '</small>' : '') ?></td>
            <td class="imp-st">
              <?php if ($p['exists']): ?><span class="muted">—</span>
              <?php elseif ($type === 'simple'): ?>
                <input class="input imp-q" type="number" name="stock[<?= $id ?>]" min="0" step="any" value="0" inputmode="decimal" data-imp-q-in data-src="<?= esc($num($p['stock_qty']), 'attr') ?>" aria-label="Opening stock of <?= esc((string) $p['name'], 'attr') ?>">
              <?php elseif ($type === 'variant' && $p['variants'] !== []): ?>
                <details class="imp-vars"><summary><span data-imp-vsum>0</span> across <?= count($p['variants']) ?> options</summary>
                  <div class="imp-vgrid">
                    <?php foreach ($p['variants'] as $v): ?>
                      <label><span><?= esc($v['name']) ?></span><input class="input imp-q" type="number" name="vstock[<?= $id ?>][<?= (int) $v['id'] ?>]" min="0" step="any" value="0" inputmode="decimal" data-imp-v="<?= (int) $v['id'] ?>" data-src="<?= esc($num($v['stock']), 'attr') ?>"></label>
                    <?php endforeach ?>
                  </div>
                </details>
              <?php elseif ($type === 'serial' || $type === 'batch'): ?>
                <small class="muted">Starts at 0 — receive <?= $type === 'serial' ? 'serial numbers' : 'batches' ?> through Purchases</small>
              <?php else: ?><small class="muted">Not stocked</small>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      <div class="empty" data-imp-none hidden><span class="empty-ic"><?= icon('search') ?></span><b>No products match</b></div>
    </div>

    <div class="imp-bar">
      <span class="grow"><b data-imp-count>0</b> of <?= count($new) ?> selected<span class="muted imp-bar-n"> · prices, VAT, options, pictures and categories are copied; stock starts at the opening stock you set</span></span>
      <a class="btn" href="<?= esc(site_url('settings/outlets'), 'attr') ?>">Skip for now</a>
      <button class="btn primary" type="submit" data-imp-go disabled><?= icon('download', 'sm') ?><span data-imp-go-t>Import products</span></button>
    </div>
  </form>
  <?php endif ?>
  <?php endif ?>
</div>
<script src="<?= esc($asset('outlet-import.js'), 'attr') ?>" defer></script>
