<?php
/**
 * @var string                    $table
 * @var array<string, int>        $stats
 * @var list<array<string,mixed>> $cats
 * @var array<string, mixed>      $filters
 * @var bool                      $manage
 * @var bool                      $csv
 * @var array<string, array>      $stockTypes
 */
helper('ui');
$b = service('posSession')->business();
$link = static fn (array $set): string => site_url('products') . (($q = http_build_query(array_filter(array_merge($filters, $set)))) !== '' ? '?' . $q : '');
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= esc(term('catalog')) ?></h2><p class="page-sub">Add, edit and organise everything you sell at <?= esc($b['name'] ?? '') ?>.</p></div>
    <div class="spacer"></div>
    <?php if ($csv): ?>
      <a class="btn" href="<?= esc(site_url('products/export'), 'attr') ?>"><?= icon('download', 'sm') ?>Export CSV</a>
      <?php if ($manage): ?><a class="btn" href="<?= esc(site_url('products/import'), 'attr') ?>"><?= icon('plus', 'sm') ?>Import CSV</a><?php endif ?>
    <?php endif ?>
    <a class="btn" href="<?= esc(site_url('products/categories'), 'attr') ?>"><?= icon('grid', 'sm') ?>Categories</a>
    <?php /* opens the label page with the ticked products (or with none, to pick there) */ ?>
    <a class="btn" href="<?= esc(site_url('products/labels'), 'attr') ?>" onclick="var ids=Array.prototype.map.call(document.querySelectorAll('input[name=&quot;ids[]&quot;]:checked'),function(i){return 'ids[]='+encodeURIComponent(i.value)});if(ids.length){location.href=this.href+'?'+ids.join('&');return false}"><?= icon('barcode', 'sm') ?>Print labels</a>
    <?php if ($manage): ?><a class="btn primary" href="<?= esc(site_url('products/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>Add product</a><?php endif ?>
  </div>

  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'tag', 'label' => 'Products', 'value' => number_format($stats['products'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'grid', 'label' => 'Categories', 'value' => number_format($stats['categories'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Out of stock', 'value' => (string) $stats['out'], 'color' => $stats['out'] ? 'var(--danger)' : null, 'sub' => $stats['low'] ? $stats['low'] . ' running low' : null]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'chart', 'label' => 'Average margin', 'value' => $stats['margin'] . '%']) ?>
  </div>

  <div class="card">
    <div class="prod-bar">
      <form method="get" action="<?= esc(site_url('products'), 'attr') ?>" class="inline-form">
        <?php if ($filters['cat']): ?><input type="hidden" name="cat" value="<?= (int) $filters['cat'] ?>"><?php endif ?>
        <select class="select" name="type" aria-label="Stock type" data-auto-submit>
          <option value="">All types</option>
          <?php foreach ($stockTypes as $k => [$l]): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($filters['type'], $k) ?>><?= esc($l) ?></option><?php endforeach ?>
          <option value="hidden" <?= selected($filters['type'], 'hidden') ?>>Hidden from POS</option>
        </select>
        <select class="select" name="stock" aria-label="Stock status" data-auto-submit>
          <option value="">Any stock</option>
          <option value="low" <?= selected($filters['stock'], 'low') ?>>Low stock</option>
          <option value="out" <?= selected($filters['stock'], 'out') ?>>Out of stock</option>
        </select>
      </form>
    </div>
    <div class="prod-cats">
      <a class="chip <?= $filters['cat'] ? '' : 'on' ?>" href="<?= esc($link(['cat' => 0]), 'attr') ?>"><?= icon('grid', 'sm') ?>All <span class="n"><?= (int) $stats['products'] ?></span></a>
      <?php foreach ($cats as $c): ?>
        <a class="chip <?= (int) $filters['cat'] === $c['id'] ? 'on' : '' ?>" href="<?= esc($link(['cat' => $c['id']]), 'attr') ?>"><?= sym_icon($c['icon']) ?><?= esc($c['name']) ?> <span class="n"><?= (int) $c['n'] ?></span></a>
      <?php endforeach ?>
    </div>
    <?php if ($manage): ?>
      <form id="bulk-form" class="bulk-bar" method="post" action="<?= esc(site_url('products/bulk'), 'attr') ?>" data-confirm="Apply this to the selected products?">
        <?= csrf_field() ?>
        <b>With selected</b>
        <select class="select" name="action" aria-label="Bulk action">
          <option value="feature">Feature</option><option value="unfeature">Unfeature</option>
          <option value="hide">Hide from POS</option><option value="show">Show in POS</option>
          <option value="online">Show online</option><option value="offline">Hide online</option>
          <option value="category">Move to category</option><option value="delete">Delete</option>
        </select>
        <select class="select" name="category_id" aria-label="Category">
          <option value="0">— category —</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></option><?php endforeach ?>
        </select>
        <button class="btn sm" type="submit">Apply</button>
        <small class="muted" id="bulk-n"></small>
      </form>
      <style>
        .bulk-bar .select{width:auto;min-width:150px;max-width:220px;flex:0 0 auto;height:36px}
        .bulk-bar [name=category_id][hidden]{display:none}
      </style>
      <script>
        document.addEventListener('DOMContentLoaded', function () {
          var f = document.getElementById('bulk-form'); if (!f) return;
          var act = f.elements.action, cat = f.elements.category_id, n = document.getElementById('bulk-n');
          var boxes = function () { return [].slice.call(document.querySelectorAll('input[name="ids[]"][form="bulk-form"]')); };
          function sync() { cat.hidden = act.value !== 'category'; var c = boxes().filter(function (b) { return b.checked; }).length; n.textContent = c ? c + ' selected' : 'Tick products in the list first'; if (all) all.checked = c > 0 && c === boxes().length; }
          var th = document.querySelector('table.tbl thead th'), all = null;
          if (th && !th.textContent.trim()) { th.innerHTML = '<input type="checkbox" aria-label="Select all products">'; all = th.firstChild; all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); sync(); }); }
          document.addEventListener('change', function (e) { if (e.target === act || e.target.matches('input[name="ids[]"]')) sync(); });
          f.addEventListener('submit', function (e) { if (!boxes().some(function (b) { return b.checked; })) { e.preventDefault(); e.stopImmediatePropagation(); window.alert('Tick at least one product first.'); } else if (act.value === 'category' && cat.value === '0') { e.preventDefault(); e.stopImmediatePropagation(); window.alert('Choose the category to move to.'); } }, true);
          sync();
        });
      </script>
    <?php endif ?>
    <?= $table ?>
  </div>
</div>
