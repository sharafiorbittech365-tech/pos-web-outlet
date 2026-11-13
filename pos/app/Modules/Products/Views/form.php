<?php
/**
 * Product form (create / edit / view-only) — the design build's product modal as a page with tabs.
 *
 * @var array<string, mixed>|null $p
 * @var bool                      $readOnly
 * @var list<array>               $cats
 * @var list<string>              $brands
 * @var list<float>               $rates
 * @var array<string, mixed>      $biz
 * @var array<string, mixed>      $features
 * @var list<string>              $priceGroups
 * @var list<string>              $icons
 * @var array<string, array>      $stockTypes
 * @var array<string, mixed>      $cur
 * @var bool                      $stockModule
 * @var bool                      $pharmacy    Pharmacy module on (medicine database link)
 * @var array<string, mixed>|null $medicine    linked medicines row
 * @var \App\Modules\Products\Config\SizeScales $scales size x colour generator config
 */
helper('ui');
$isNew = $p === null;
$p ??= ['name' => '', 'category_id' => $cats[0]['id'] ?? null, 'price' => '', 'cost' => '', 'icon' => 'package', 'tax_mode' => 'default', 'tax_rate' => null,
    'stock_type' => ! empty($biz['stock_enabled']) ? (! empty($features['matrix']) ? 'variant' : 'simple') : 'none', 'is_online' => 1, 'is_active' => 1, 'variants' => [], 'serials' => [], 'batches' => [], 'warranties' => [], 'extra' => []];
if ($isNew && ! empty($medicine)) {
    // new product from the medicine database: names from the shared record, batch stock
    $p = array_merge($p, ['name' => \App\Modules\Pharmacy\Services\MedicineCatalog::productName($medicine), 'generic_name' => $medicine['generic'], 'brand' => $medicine['manufacturer'],
        'medicine_id' => $medicine['id'], 'icon' => 'pill', 'stock_type' => ! empty($biz['stock_enabled']) ? 'batch' : 'none']);
}
$v = static fn (string $k, mixed $def = '') => old($k) ?? ($p[$k] ?? $def);
$ro = $readOnly ? 'disabled' : '';
// a stored picture address (absolute URL, or a path under this app) as an <img src>
$picSrc = static fn (string $a): string => preg_match('#^https?://#i', $a) === 1 ? $a : base_url(ltrim($a, '/'));
$label = (string) ($biz['tax_label'] ?? 'VAT');
$defRate = (float) ($biz['tax_rate'] ?? 0);
$inclusive = ! empty($biz['tax_inclusive']);
$stype = (string) $v('stock_type', 'simple');
$variants = old('variants') ?? array_map(static fn (array $x): array => ['id' => $x['id'], 'name' => $x['name'], 'sku' => $x['sku'], 'price_delta' => $x['price_delta'],
    'mult' => $x['attributes']['mult'] ?? 1, 'stock' => $x['stock_qty'], 'attrs' => implode(', ', array_map(static fn ($k, $val): string => $k . ': ' . $val, array_keys(array_diff_key($x['attributes'], ['mult' => 1])), array_diff_key($x['attributes'], ['mult' => 1])))], $p['variants']);
$warranties = old('warranties') ?? $p['warranties'];
$showWarranty = ! empty($features['warranty']) || ! empty($features['serials']) || $warranties !== [] || ! empty($p['warranty_months']);
$prices = (array) ($p['extra']['prices'] ?? []);
$err = static fn (string $f): string => field_error($f);
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= esc($isNew ? 'Add product' : (string) $p['name']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('products'), 'attr') ?>"><?= icon('chevl', 'sm') ?>All products</a></p></div>
    <div class="spacer"></div>
    <?php if (! $isNew && ! $readOnly): ?>
      <form method="post" action="<?= esc(site_url('products/' . $p['id'] . '/delete'), 'attr') ?>" class="inline" data-confirm="Delete <?= esc((string) $p['name'], 'attr') ?>? Past invoices keep their lines.">
        <?= csrf_field() ?><button class="btn danger" type="submit"><?= icon('trash', 'sm') ?>Delete</button>
      </form>
    <?php endif ?>
    <?php if (! $isNew && $stockModule && $p['stock_type'] !== 'none'): ?>
      <a class="btn" href="<?= esc(site_url('inventory/' . $p['id']), 'attr') ?>"><?= icon('box', 'sm') ?>Stock & movements</a>
    <?php endif ?>
  </div>

  <form class="card bo-form pf-flow" method="post" enctype="multipart/form-data" action="<?= esc(site_url($isNew ? 'products' : 'products/' . $p['id']), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="card-b">
      <fieldset <?= $ro ?> style="border:0;padding:0;margin:0;min-width:0">

<?php $web = (bool) ($web ?? false); // Website module on: the online-store fields come first ?>
<?php ob_start(); ?>
      <details class="pf-card" data-sec="media" <?= $web || $pics ? 'open' : '' ?>>
        <summary class="pf-h">Photos<small><?= $web ? 'Shown on your website and in the till' : 'Optional — shown on the till tile' ?></small></summary>
        <div class="pf-body">
      <!-- images & media -->
      <div class="pane" data-pane="media">
        <div class="field pf-photos"><span>Pictures <small class="muted">— up to <?= \App\Modules\Products\Services\ProductPhotos::MAX ?>. The first is the main picture; the small copy used in lists is made for you. Big pictures are <b>compressed automatically</b> (scaled to 1400 px, WebP).</small></span>
          <?php if ($pics !== []): ?>
            <div class="pf-pics" data-pics style="display:flex;flex-wrap:wrap;gap:12px;margin:10px 0">
              <?php foreach ($pics as $i => $pic): ?>
                <div class="pf-pic" data-pic style="width:140px;font-size:12px;border:1px solid var(--line,#ddd);border-radius:10px;padding:6px">
                  <input type="hidden" name="photo_id[]" value="<?= (int) $pic['id'] ?>">
                  <img src="<?= esc($picSrc((string) ($pic['thumb_path'] ?: $pic['path'])), 'attr') ?>" alt="" style="width:100%;height:110px;object-fit:cover;border-radius:6px;display:block">
                  <input class="input" style="margin-top:6px" name="photo_alt[<?= (int) $pic['id'] ?>]" value="<?= esc((string) ($pic['alt'] ?? ''), 'attr') ?>" maxlength="160" placeholder="Alt text" aria-label="Alt text">
                  <label class="check" style="margin-top:4px"><input type="radio" name="photo_main" value="<?= (int) $pic['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> <?= $ro ?>>Main</label>
                  <label class="check"><input type="checkbox" name="photo_remove[]" value="<?= (int) $pic['id'] ?>" <?= $ro ?>>Remove</label>
                  <?php if (! $readOnly): ?><div style="display:flex;gap:6px;margin-top:4px"><button class="btn sm" type="button" data-pic-mv="-1" aria-label="Move earlier">&larr;</button><button class="btn sm" type="button" data-pic-mv="1" aria-label="Move later">&rarr;</button></div><?php endif ?>
                </div>
              <?php endforeach ?>
            </div>
          <?php endif ?>
          <?php if (! $readOnly): ?>
            <input class="input" type="file" name="photos[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple><?= $err('photos') ?>
            <textarea class="input" style="margin-top:8px" rows="2" name="photo_new_alt_text" placeholder="Alt text for the new pictures — one line each, in the same order (optional)"></textarea>
          <?php endif ?>
        </div>
        <div class="form-grid">
          <label class="field span2">Video link (optional)<input class="input" type="url" name="x_video" value="<?= old_or('x_video', $xd['video_url'] ?? '') ?>" maxlength="300" placeholder="https://www.youtube.com/watch?v=…"><?= $err('x_video') ?></label>
        </div>
      </div>
        </div>
      </details>
<?php $cMedia = ob_get_clean(); ?>
      <section class="pf-card" data-sec="basic">
        <h3 class="pf-h">Basic information<small>What is it, and how do you find it?</small></h3>
        <div class="pf-body">
        <?php if ($pharmacy): ?>
          <div class="med-pick" data-med-pick data-url="<?= esc(site_url('pharmacy/medicines/search'), 'attr') ?>">
            <input type="hidden" name="medicine_id" value="<?= (int) (old('medicine_id') ?? ($p['medicine_id'] ?? 0)) ?>" data-med-id>
            <label class="field">Add from medicine database <small class="muted">— brand or generic name; fills name, generic and manufacturer</small>
              <input class="input" type="search" data-med-q placeholder="Search the medicine database…" autocomplete="off" aria-controls="med-results"></label>
            <div class="med-results" id="med-results" data-med-results role="listbox" hidden></div>
            <div class="med-linked" data-med-linked <?= $medicine ? '' : 'hidden' ?>><?= icon('pill', 'sm') ?><span data-med-label><?= $medicine ? esc(trim($medicine['brand_name'] . ' ' . ($medicine['strength'] ?? '') . ' · ' . ($medicine['dosage_form'] ?? '') . ' · ' . ($medicine['generic'] ?? ''), ' ·')) : '' ?></span>
              <button class="btn sm" type="button" data-med-clear><?= icon('x', 'sm') ?>Unlink</button></div>
            <?= $err('medicine_id') ?>
          </div>
        <?php endif ?>
        <div class="pane" data-pane="basic">
        <div class="form-grid">
          <label class="field span2">Product name *<input class="input" name="name" value="<?= old_or('name', $p['name']) ?>" required maxlength="200" placeholder="e.g. Cotton Panjabi" autofocus><?= $err('name') ?></label>
          <label class="field">Category<select class="select" name="category_id"><option value="0">— No category —</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>" <?= selected($v('category_id'), $c['id']) ?>><?= esc($c['name']) ?></option><?php endforeach ?></select><?= $err('category_id') ?></label>
          <label class="field">Unit label (pcs, kg, bag, ft, hour…)<input class="input" name="unit" value="<?= old_or('unit', $p['unit'] ?? '') ?>" maxlength="16" placeholder="pcs"></label>
          <label class="field">SKU / product code<input class="input" name="sku" value="<?= old_or('sku', $p['sku'] ?? '') ?>" maxlength="64"><?= $err('sku') ?></label>
          <label class="field">Barcode<input class="input" name="barcode" value="<?= old_or('barcode', $p['barcode'] ?? '') ?>" maxlength="64" inputmode="numeric"><?= $err('barcode') ?></label>
          <?php if (! empty($features['batches']) || ! empty($features['rx']) || ! empty($p['generic_name'])): ?>
            <label class="field">Generic name<input class="input" name="generic_name" value="<?= old_or('generic_name', $p['generic_name'] ?? '') ?>" maxlength="160"></label>
          <?php endif ?>
          <?php if (! empty($features['staff']) || ! empty($p['duration_min']) || (string) ($biz['store_kind'] ?? '') === 'booking'): ?>
            <label class="field">Service duration (min)<input class="input" type="number" min="0" max="1440" name="duration_min" value="<?= old_or('duration_min', $p['duration_min'] ?? '') ?>"><?= $err('duration_min') ?></label>
          <?php endif ?>
          <?php if (! empty($features['rx']) || ! empty($p['is_rx'])): ?>
            <label class="check"><input type="hidden" name="is_rx" value="0"><input type="checkbox" name="is_rx" value="1" <?= checked($v('is_rx', 0)) ?>>Prescription (Rx) required</label>
          <?php endif ?>
        </div>
        </div>
        </div>
      </section>

<?= $web ? $cMedia : '' ?>

      <section class="pf-card" data-sec="pricing">
        <h3 class="pf-h">Pricing<small>Selling price, cost and tax</small></h3>
        <div class="pf-body">
      <!-- pricing & VAT -->
      <div class="pane" data-pane="pricing">
        <div class="form-grid">
          <label class="field">Sale price (selling) * (<?= esc($cur['symbol']) ?>)<input class="input" type="number" min="0" step="any" name="price" value="<?= old_or('price', $p['price']) ?>" required><?= $err('price') ?></label>
          <label class="field">Purchase cost<input class="input" type="number" min="0" step="any" name="cost" value="<?= old_or('cost', $p['cost'] ?? '') ?>"><?= $err('cost') ?></label>
          <label class="field">Regular price / MRP (shown struck out)<input class="input" type="number" min="0" step="any" name="compare_at" value="<?= old_or('compare_at', $p['compare_at'] ?? '') ?>"></label>
          <div class="pf-margin" data-margin><small>Margin</small><b>0%</b><span class="muted">—</span></div>
          <div class="field span2 pf-tax"><span><?= icon('receipt', 'sm') ?> <?= esc($label) ?> for this product</span>
            <div class="pf-tax-row">
              <label class="field">How it is charged<select class="select" name="tax_mode">
                <?php foreach (['default' => 'Business default — ' . ($inclusive ? 'included in price' : 'added on top'), 'inclusive' => 'Included in the price', 'exclusive' => 'Added on top of the price', 'exempt' => 'Exempt (no ' . $label . ')'] as $k => $l): ?>
                  <option value="<?= esc($k, 'attr') ?>" <?= selected($v('tax_mode', 'default'), $k) ?>><?= esc($l) ?></option>
                <?php endforeach ?>
              </select><?= $err('tax_mode') ?></label>
              <label class="field">Rate<select class="select" name="tax_rate">
                <option value="" <?= $v('tax_rate') === null || $v('tax_rate') === '' ? 'selected' : '' ?>>Default (<?= esc(qty($defRate)) ?>%)</option>
                <?php foreach ($rates as $r): ?><option value="<?= esc((string) $r, 'attr') ?>" <?= $v('tax_rate') !== null && $v('tax_rate') !== '' && (float) $v('tax_rate') === (float) $r ? 'selected' : '' ?>><?= esc(qty($r)) ?>%</option><?php endforeach ?>
              </select><?= $err('tax_rate') ?></label>
            </div>
            <div class="pf-taxbox" data-vat data-rate="<?= esc((string) $defRate, 'attr') ?>" data-inclusive="<?= $inclusive ? '1' : '0' ?>" data-sym="<?= esc($cur['symbol'], 'attr') ?>" data-dec="<?= (int) $cur['decimals'] ?>" data-label="<?= esc($label, 'attr') ?>">
              <span><small>Price before <?= esc($label) ?></small><b data-o="net">—</b></span>
              <span><small><?= esc($label) ?> <span data-o="rate"></span></small><b data-o="vat">—</b></span>
              <span class="pay"><small>Customer pays</small><b data-o="gross">—</b></span>
            </div>
          </div>
        </div>
        <?php if (! empty($features['priceGroups']) && count($priceGroups) > 1): ?>
          <div class="field">Customer price groups <span class="muted" style="font-weight:500">— leave empty to use the selling price</span></div>
          <div class="form-grid">
            <?php foreach (array_slice($priceGroups, 1) as $g): $gk = mb_strtolower((string) $g); ?>
              <label class="field"><?= esc($g) ?> price<input class="input" type="number" min="0" step="any" name="prices[<?= esc($gk, 'attr') ?>]" value="<?= esc((string) (old('prices')[$gk] ?? $prices[$gk] ?? ''), 'attr') ?>"></label>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
        </div>
      </section>

      <details class="pf-card" data-sec="stock" <?= ! empty($biz['stock_enabled']) || $isNew ? 'open' : '' ?>>
        <summary class="pf-h">Stock<small>How many do you have?</small></summary>
        <div class="pf-body">
      <!-- stock -->
      <div class="pane" data-pane="stock">
        <?php if (empty($biz['stock_enabled'])): ?>
          <div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Stock management is <b>OFF</b> for this outlet (Setup). The saved stock is used again when it is turned ON.</span></div>
        <?php endif ?>
        <div class="field">How is stock tracked?<?= $err('stock_type') ?></div>
        <div class="stypes">
          <?php foreach ($stockTypes as $k => [$l, $sub]): ?>
            <label class="opt <?= $stype === $k ? 'on' : '' ?>"><input type="radio" name="stock_type" value="<?= esc($k, 'attr') ?>" <?= checked($stype === $k) ?>><b><?= esc($l) ?></b><span><?= esc($sub) ?></span></label>
          <?php endforeach ?>
        </div>
        <?php if (! $isNew && (float) $p['stock_qty'] > 0): ?><small class="muted">Tracking can change once stock is zero.</small><?php endif ?>

        <div class="form-grid" data-show-for="simple,variant,serial,batch" data-show-name="stock_type">
          <?php if ($isNew): ?>
            <label class="field" data-show-for="simple" data-show-name="stock_type">Opening stock (<?= esc((string) ($p['unit'] ?? '') ?: 'pcs') ?>)<input class="input" type="number" min="0" step="any" name="opening_stock" value="<?= old_or('opening_stock', '0') ?>"></label>
          <?php else: ?>
            <div class="due-box"><small>In stock now</small><b><?= esc(qty($p['stock_qty'], $p['unit'] ?: 'pcs')) ?></b></div>
          <?php endif ?>
          <label class="field">Low-stock alert at <small class="muted">(empty = outlet default <?= esc(qty($biz['low_stock_alert'] ?? 0)) ?>)</small><input class="input" type="number" min="0" step="any" name="low_stock_at" value="<?= old_or('low_stock_at', $p['low_stock_at'] ?? '') ?>"></label>
        </div>
        <?php if (! $isNew): ?><p class="muted bo-note" data-show-for="simple,variant" data-show-name="stock_type">Day-to-day receiving and corrections are logged in <b>Inventory › Adjust</b>.</p><?php endif ?>

        <div data-show-for="serial" data-show-name="stock_type">
          <?php if ($p['serials']): ?>
            <div class="field">In stock (<?= count($p['serials']) ?>)</div>
            <div class="sn-list"><?php foreach (array_slice($p['serials'], 0, 60) as $sn): ?><code><?= esc($sn['serial']) ?></code><?php endforeach ?><?= count($p['serials']) > 60 ? '<span class="muted">…</span>' : '' ?></div>
          <?php endif ?>
          <label class="field"><?= $isNew ? 'IMEI / serial numbers in stock' : 'Add new IMEI / serial numbers' ?> — one per line<textarea class="input mono" name="serials" rows="6"><?= esc((string) (old('serials') ?? '')) ?></textarea></label>
        </div>

        <div data-show-for="batch" data-show-name="stock_type">
          <?php if ($p['batches']): ?>
            <table class="tbl"><thead><tr><th>Batch no.</th><th>Expiry</th><th class="num">Qty</th></tr></thead><tbody>
              <?php foreach ($p['batches'] as $bt): ?>
                <tr><td><?= esc($bt['batch_no']) ?></td><td><?= esc(date_str($bt['expires_on'])) ?><?= $bt['expires_on'] && $bt['expires_on'] < date('Y-m-d') ? ' ' . tag_html('Expired', 'danger') : '' ?></td><td class="num"><?= esc(qty($bt['qty'])) ?></td></tr>
              <?php endforeach ?>
            </tbody></table>
          <?php endif ?>
          <div class="field"><?= $isNew ? 'Batches in stock' : 'Receive new batches' ?></div>
          <div class="bo-rows" data-rows="batches" style="--cols:3">
            <?php foreach ((array) (old('batches') ?? []) as $i => $bt): ?>
              <div class="bo-row" data-row style="--cols:3"><label class="field">Batch no.<input class="input" name="batches[<?= (int) $i ?>][no]" value="<?= esc((string) ($bt['no'] ?? ''), 'attr') ?>"></label><label class="field">Expiry<input class="input" type="date" name="batches[<?= (int) $i ?>][exp]" value="<?= esc((string) ($bt['exp'] ?? ''), 'attr') ?>"></label><label class="field">Qty<input class="input" type="number" min="0" step="any" name="batches[<?= (int) $i ?>][qty]" value="<?= esc((string) ($bt['qty'] ?? ''), 'attr') ?>"></label><button class="x-btn" type="button" data-row-del aria-label="Remove"><?= icon('trash', 'sm') ?></button></div>
            <?php endforeach ?>
          </div>
          <template data-row-tpl="batches"><div class="bo-row" data-row style="--cols:3"><label class="field">Batch no.<input class="input" name="batches[__i__][no]"></label><label class="field">Expiry<input class="input" type="date" name="batches[__i__][exp]"></label><label class="field">Qty<input class="input" type="number" min="0" step="any" name="batches[__i__][qty]"></label><button class="x-btn" type="button" data-row-del aria-label="Remove"><?= icon('trash', 'sm') ?></button></div></template>
          <button class="btn soft" type="button" data-row-add="batches"><?= icon('plus', 'sm') ?>Add batch</button>
          <small class="muted">Sales always pick the batch that expires first (FEFO). Expired batches are never sold.</small>
        </div>

        <p class="muted bo-note" data-show-for="variant" data-show-name="stock_type">Option stock: add each option (e.g. “M / Black”) with its quantity in <b>Sizes, colours &amp; packs</b> below.</p>
      </div>
        </div>
      </details>

      <details class="pf-card" data-sec="options" <?= $variants || ! empty($features['matrix']) ? 'open' : '' ?>>
        <summary class="pf-h">Sizes, colours &amp; packs<small>Optional — variants with their own price and stock<?= $variants ? ' (' . count($variants) . ')' : '' ?></small></summary>
        <div class="pf-body">
      <!-- options & packs -->
      <div class="pane" data-pane="options">
        <p class="muted" style="margin:0;font-size:13px">Options let cashiers pick a size or pack (Half / Full, 1 lb / 2 lb, Piece / Strip of 10). <b>Price ±</b> is added to the selling price; <b>Units used</b> is how many base units each option takes from stock. With <b>Options (size / colour)</b> stock, each option keeps its own stock.<?= $err('variants') ?></p>
        <?php $scaleKey = (string) ($features['sizeScale'] ?? ''); $mx = (array) (old('matrix') ?? []); ?>
        <details class="mx-gen" data-mx <?= ! empty($features['matrix']) && $variants === [] ? 'open' : '' ?>>
          <summary><?= icon('grid', 'sm') ?><b>Size × colour generator</b> <span class="muted">— one option per size and colour, each with its own SKU (scannable on its label) and stock</span></summary>
          <div class="form-grid">
            <label class="field">Size scale<select class="select" data-mx-scale name="matrix[scale]">
              <option value="">— Type sizes below —</option>
              <?php foreach ($scales->scales as $k => $sc): ?><option value="<?= esc($k, 'attr') ?>" data-sizes="<?= esc($sc['sizes'], 'attr') ?>" <?= selected((string) ($mx['scale'] ?? ''), $k) ?>><?= esc($sc['label']) ?></option><?php endforeach ?>
            </select></label>
            <label class="field">Sizes <small class="muted">(e.g. 36-45 or 39, 40, 41)</small><input class="input" name="matrix[sizes]" data-mx-sizes value="<?= esc((string) ($mx['sizes'] ?? ''), 'attr') ?>" data-default="<?= esc((string) ($scales->scales[$scaleKey]['sizes'] ?? ''), 'attr') ?>"></label>
            <label class="field span2">Colours <small class="muted">(comma separated; empty = sizes only)</small><input class="input" name="matrix[colours]" data-mx-colours value="<?= esc((string) ($mx['colours'] ?? ''), 'attr') ?>" placeholder="Black, Brown"></label>
            <div class="field span2 mx-chips"><?php foreach (\OmniPOS\Core\Services\VariantMatrix::parseList($scales->colours) as $col): ?><button class="chip sm" type="button" data-mx-colour="<?= esc($col, 'attr') ?>">+ <?= esc($col) ?></button><?php endforeach ?></div>
            <label class="field">SKU prefix <small class="muted">(empty = product SKU)</small><input class="input" name="matrix[sku]" data-mx-sku value="<?= esc((string) ($mx['sku'] ?? ''), 'attr') ?>" maxlength="40"></label>
            <label class="field">Opening stock per option<input class="input" type="number" min="0" step="1" name="matrix[stock]" data-mx-stock value="<?= esc((string) ($mx['stock'] ?? '0'), 'attr') ?>"></label>
          </div>
          <div class="mx-acts"><button class="btn soft" type="button" data-mx-add><?= icon('plus', 'sm') ?>Add these options</button><small class="muted" data-mx-note>Rows are added below — adjust stock or price per option before saving. Without JavaScript the grid is created when you save.</small></div>
        </details>
        <div class="bo-rows" data-rows="variants" data-next="<?= count($variants) ?>">
          <?php foreach ($variants as $i => $x): ?>
            <div class="bo-row" data-row style="--cols:6">
              <input type="hidden" name="variants[<?= (int) $i ?>][id]" value="<?= (int) ($x['id'] ?? 0) ?>">
              <label class="field">Option name<input class="input" name="variants[<?= (int) $i ?>][name]" value="<?= esc((string) ($x['name'] ?? ''), 'attr') ?>" placeholder="e.g. Large"></label>
              <label class="field">SKU<input class="input" name="variants[<?= (int) $i ?>][sku]" value="<?= esc((string) ($x['sku'] ?? ''), 'attr') ?>"></label>
              <label class="field">Price ±<input class="input" type="number" step="any" name="variants[<?= (int) $i ?>][price_delta]" value="<?= esc((string) ($x['price_delta'] ?? 0), 'attr') ?>"></label>
              <label class="field">Units used<input class="input" type="number" min="1" step="any" name="variants[<?= (int) $i ?>][mult]" value="<?= esc((string) ($x['mult'] ?? 1), 'attr') ?>"></label>
              <label class="field">Attributes<input class="input" name="variants[<?= (int) $i ?>][attrs]" value="<?= esc((string) ($x['attrs'] ?? ''), 'attr') ?>" placeholder="Size: M, Colour: Black"></label>
              <?php if (! empty($x['id']) && ! $isNew): ?>
                <div class="field">Stock<b><?= $x['stock'] === null ? '<span class="muted">—</span>' : esc(qty($x['stock'])) ?></b></div>
              <?php else: ?>
                <label class="field">Opening stock<input class="input" type="number" min="0" step="any" name="variants[<?= (int) $i ?>][stock]" value="<?= esc((string) ($x['stock'] ?? 0), 'attr') ?>"></label>
              <?php endif ?>
              <button class="x-btn" type="button" data-row-del aria-label="Remove option"><?= icon('trash', 'sm') ?></button>
            </div>
          <?php endforeach ?>
        </div>
        <template data-row-tpl="variants"><div class="bo-row" data-row style="--cols:6"><input type="hidden" name="variants[__i__][id]" value="0"><label class="field">Option name<input class="input" name="variants[__i__][name]" placeholder="e.g. Large"></label><label class="field">SKU<input class="input" name="variants[__i__][sku]"></label><label class="field">Price ±<input class="input" type="number" step="any" name="variants[__i__][price_delta]" value="0"></label><label class="field">Units used<input class="input" type="number" min="1" step="any" name="variants[__i__][mult]" value="1"></label><label class="field">Attributes<input class="input" name="variants[__i__][attrs]" placeholder="Size: M, Colour: Black"></label><label class="field">Opening stock<input class="input" type="number" min="0" step="any" name="variants[__i__][stock]" value="0"></label><button class="x-btn" type="button" data-row-del aria-label="Remove option"><?= icon('trash', 'sm') ?></button></div></template>
        <div><button class="btn soft" type="button" data-row-add="variants"><?= icon('plus', 'sm') ?>Add option</button></div>
      </div>

      <?php
      $xd = (array) ($extra['details'] ?? []); $xs = (array) ($extra['supplier'] ?? []); $xk = (array) ($extra['packaging'] ?? []); $xc = (array) ($extra['customizations'] ?? []); $xa = (array) ($extra['attributes'] ?? []);
      $xv = static fn (string $k, mixed $d = '') => old($k) ?? $d;
      $nz = static fn (mixed $n): string => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
      ?>
        </div>
      </details>

<?= $web ? '' : $cMedia ?>

<?php if ($web): ?>
      <section class="pf-card" data-sec="online">
        <h3 class="pf-h">Online store<small>How the product looks on your website</small></h3>
        <div class="pf-body">
        <div class="form-grid">
          <label class="field span2">Short description <small class="muted">— one or two lines under the title</small><input class="input" name="x_short" value="<?= old_or('x_short', ($extra['details']['short_description'] ?? '')) ?>" maxlength="500"></label>
          <label class="field span2">Description <small class="muted">— shown on the website</small><textarea class="input" name="description" rows="3" maxlength="5000"><?= esc((string) $v('description', '')) ?></textarea></label>
          <label class="field">Slug <small class="muted">— web address, made from the name if empty</small><input class="input" name="x_slug" value="<?= old_or('x_slug', ($extra['details']['slug'] ?? '')) ?>" maxlength="160" placeholder="niimbot-b1-label-printer"></label>
          <label class="field">Product status<select class="select" name="x_status"><?php foreach (\App\Modules\Products\Services\ProductExtras::STATUSES as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) (old('x_status') ?? ($extra['details']['status'] ?? 'active')), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="check"><input type="hidden" name="is_online" value="0"><input type="checkbox" name="is_online" value="1" <?= checked($v('is_online', 1)) ?>>Show online (website &amp; marketplace)</label>
          <label class="check"><input type="hidden" name="is_featured" value="0"><input type="checkbox" name="is_featured" value="1" <?= checked($v('is_featured', 0)) ?>>Featured product</label>
        </div>
        <details class="pf-sub"><summary>Search engines (SEO)</summary><div class="pf-body">
      <!-- seo -->
      <div class="pane" data-pane="seo">
        <div class="form-grid">
          <label class="field span2">Page title<input class="input" name="x_seo_title" value="<?= old_or('x_seo_title', $xd['seo_title'] ?? '') ?>" maxlength="160" placeholder="Defaults to the product name"></label>
          <label class="field span2">Meta description<textarea class="input" rows="2" name="x_seo_desc" maxlength="300" placeholder="Shown in Google results"><?= esc((string) $xv('x_seo_desc', $xd['seo_description'] ?? '')) ?></textarea></label>
          <label class="field span2">Keywords<input class="input" name="x_seo_kw" value="<?= old_or('x_seo_kw', $xd['seo_keywords'] ?? '') ?>" maxlength="300" placeholder="thermal printer, label printer"></label>
        </div>
      </div>
        </div></details>
        </div>
      </section>
<?php endif ?>

      <details class="pf-card pf-more" data-sec="more" <?= \old('name') !== null ? 'open' : '' ?>>
        <summary class="pf-h">More details<small>Brand, model, specifications, supplier, packaging and more — all optional</small></summary>
        <div class="pf-body">
        <div class="form-grid">
          <label class="field">Brand<input class="input" name="brand" list="pf-brands" value="<?= old_or('brand', $p['brand'] ?? '') ?>" maxlength="80"><datalist id="pf-brands"><?php foreach ($brands as $br): ?><option value="<?= esc($br, 'attr') ?>"><?php endforeach ?></datalist></label>
          <?php if (true): ?>
            <label class="field">Model / style no.<input class="input" name="model" value="<?= old_or('model', $p['model'] ?? '') ?>" maxlength="80" placeholder="e.g. RN-220"></label>
          <?php endif ?>
          <label class="field">Icon<select class="select" name="icon"><?php foreach ($icons as $ic): ?><option value="<?= esc($ic, 'attr') ?>" <?= selected($v('icon', 'package'), $ic) ?>><?= esc(str_replace('-', ' ', $ic)) ?></option><?php endforeach ?></select></label>
          <label class="field">Badge on tile<input class="input" name="badge" value="<?= old_or('badge', $p['badge'] ?? '') ?>" maxlength="24" placeholder="e.g. New, Hot, Eid Special"></label>
          <label class="field">Country of origin<input class="input" name="x_origin" value="<?= old_or('x_origin', ($extra['details']['country_origin'] ?? '')) ?>" maxlength="60" placeholder="China"></label>
          <label class="check"><input type="hidden" name="hidden" value="0"><input type="checkbox" name="hidden" value="1" <?= checked(old('hidden') ?? (empty($p['is_active']) ? 1 : 0)) ?>>Hide from POS (keep for history)</label>
        </div>
        <details class="pf-sub" ><summary>Price range, sample price &amp; minimum order</summary><div class="pf-body">
        <div class="form-grid">
          <label class="field">Currency<select class="select" name="x_currency"><option value="">Outlet currency (<?= esc($cur['symbol']) ?>)</option><?php foreach (\App\Modules\Products\Services\ProductExtras::CURRENCIES as $cc): ?><option <?= selected((string) (old('x_currency') ?? ($extra['details']['currency'] ?? '')), $cc) ?>><?= $cc ?></option><?php endforeach ?></select></label>
          <label class="field">Sample price<input class="input" type="number" min="0" step="any" name="x_sample_price" value="<?= old_or('x_sample_price', rtrim(rtrim((string) ($extra['details']['sample_price'] ?? ''), '0'), '.')) ?>"><?= $err('x_sample_price') ?></label>
          <label class="field">Minimum price <small class="muted">— supplier range</small><input class="input" type="number" min="0" step="any" name="x_price_min" value="<?= old_or('x_price_min', rtrim(rtrim((string) ($extra['details']['price_min'] ?? ''), '0'), '.')) ?>"><?= $err('x_price_min') ?></label>
          <label class="field">Maximum price <small class="muted">— supplier range</small><input class="input" type="number" min="0" step="any" name="x_price_max" value="<?= old_or('x_price_max', rtrim(rtrim((string) ($extra['details']['price_max'] ?? ''), '0'), '.')) ?>"><?= $err('x_price_max') ?></label>
          <label class="field">Minimum order quantity (MOQ)<input class="input" type="number" min="0" step="1" name="x_moq" value="<?= old_or('x_moq', (string) ($extra['details']['moq'] ?? '')) ?>"><?= $err('x_moq') ?></label>
        </div>
        </div></details>
        <details class="pf-sub" <?= $extra['attributes'] ? 'open' : '' ?>><summary>Specifications<?= $extra['attributes'] ? ' (' . count($extra['attributes']) . ')' : '' ?></summary><div class="pf-body">
      <!-- attributes & specifications -->
      <div class="pane" data-pane="specs">
        <p class="muted" style="margin:0 0 8px;font-size:13px">Specifications shown on the product page (Interface type, Resolution, Paper width, Weight …). Type a new name to create an attribute; no setup needed. For <b>Dropdown</b> and <b>Multi-select</b> list the choices in <i>Choices</i>; for <b>Measurement</b> give the unit (a range like 20–50 is fine).</p>
        <datalist id="xdefs-list"><?php foreach ($xdefs as $d): ?><option value="<?= esc((string) $d['name'], 'attr') ?>"><?php endforeach ?></datalist>
        <?php
        $rows = [];
        foreach ($xa as $a) {
            $val = (string) $a['value_text'];
            if ($a['type'] === 'multiselect' && is_array($l = json_decode($val, true))) { $val = implode(', ', $l); }
            $rows[] = ['name' => $a['name'], 'type' => $a['type'], 'unit' => (string) ($a['value_unit'] ?? $a['unit'] ?? ''), 'options' => implode(', ', (array) $a['options']), 'value' => $val];
        }
        if (old('x_attr') !== null) { $rows = array_values((array) old('x_attr')); }
        for ($pad = 0; $pad < 3; $pad++) { $rows[] = ['name' => '', 'type' => 'text', 'unit' => '', 'options' => '', 'value' => '']; }
        $attrRow = static function (int|string $i, array $r) use ($err): string {
            $o = '';
            foreach (\App\Modules\Products\Services\ProductExtras::TYPES as $k => $l) { $o .= '<option value="' . esc($k, 'attr') . '"' . (($r['type'] ?? 'text') === $k ? ' selected' : '') . '>' . esc($l) . '</option>'; }

            return '<div class="form-grid xa-row" data-xa-row style="grid-template-columns:1.3fr 1fr .7fr 1.2fr 1.4fr;align-items:end;margin-bottom:6px">'
                . '<label class="field">Name<input class="input" list="xdefs-list" name="x_attr[' . $i . '][name]" value="' . esc((string) ($r['name'] ?? ''), 'attr') . '" maxlength="80"></label>'
                . '<label class="field">Type<select class="select" name="x_attr[' . $i . '][type]">' . $o . '</select></label>'
                . '<label class="field">Unit<input class="input" name="x_attr[' . $i . '][unit]" value="' . esc((string) ($r['unit'] ?? ''), 'attr') . '" maxlength="16" placeholder="mm"></label>'
                . '<label class="field">Choices<input class="input" name="x_attr[' . $i . '][options]" value="' . esc((string) ($r['options'] ?? ''), 'attr') . '" placeholder="Red, Blue"></label>'
                . '<label class="field">Value<input class="input" name="x_attr[' . $i . '][value]" value="' . esc((string) ($r['value'] ?? ''), 'attr') . '" maxlength="500"></label>'
                . $err('x_attr_' . $i) . '</div>';
        };
        ?>
        <div data-xa-rows data-next="<?= count($rows) ?>"><?php foreach ($rows as $i => $r): ?><?= $attrRow($i, (array) $r) ?><?php endforeach ?></div>
        <?php if (! $readOnly): ?><button class="btn sm" type="button" data-xa-add><?= icon('plus', 'sm') ?>Add another attribute</button>
        <template data-xa-tpl><?= $attrRow('__N__', []) ?></template><?php endif ?>
      </div>
        </div></details>
        <?php if ($showWarranty): ?>
        <details class="pf-sub" ><summary>Warranty<?= $warranties ? ' (' . count($warranties) . ')' : '' ?></summary><div class="pf-body">
      <!-- warranty -->
      <div class="pane" data-pane="warranty">
        <div class="wbox">
          <p class="muted" style="margin:0">Set the main warranty, then add parts with their own period — e.g. a laptop: full product 2 years, SSD 3 years, battery 1 year. Every part prints on the invoice with its own end date.</p>
          <div class="wrow wmain"><span class="wpart"><?= icon('shield', 'sm') ?><b>Full product</b><small>months</small></span><input class="input" type="number" min="0" max="1200" name="warranty_months" value="<?= old_or('warranty_months', $p['warranty_months'] ?? '') ?>" placeholder="e.g. 12"><span></span></div>
          <div class="bo-rows" data-rows="warranties" data-next="<?= count($warranties) ?>">
            <?php foreach ($warranties as $i => $w): ?>
              <div class="wrow" data-row><input class="input" name="warranties[<?= (int) $i ?>][part]" value="<?= esc((string) ($w['part'] ?? ''), 'attr') ?>" placeholder="Part name, e.g. SSD"><input class="input" type="number" min="1" max="1200" name="warranties[<?= (int) $i ?>][months]" value="<?= esc((string) ($w['months'] ?? 12), 'attr') ?>"><button class="icon-btn" type="button" data-row-del aria-label="Remove part"><?= icon('trash', 'sm') ?></button></div>
            <?php endforeach ?>
          </div>
          <template data-row-tpl="warranties"><div class="wrow" data-row><input class="input" name="warranties[__i__][part]" value="__v__" placeholder="Part name, e.g. SSD"><input class="input" type="number" min="1" max="1200" name="warranties[__i__][months]" value="12"><button class="icon-btn" type="button" data-row-del aria-label="Remove part"><?= icon('trash', 'sm') ?></button></div></template>
          <div class="row-add">
            <button class="btn sm" type="button" data-row-add="warranties"><?= icon('plus', 'sm') ?>Add part warranty</button>
            <?php foreach (['Battery', 'Display', 'Motherboard', 'SSD', 'RAM', 'Charger', 'Compressor', 'Motor'] as $preset): ?>
              <button class="chip sm" type="button" data-row-add="warranties" data-value="<?= esc($preset, 'attr') ?>">+ <?= esc($preset) ?></button>
            <?php endforeach ?>
          </div>
        </div>
      </div>
        </div></details>
        <?php endif ?>
        <details class="pf-sub" ><summary>Supplier</summary><div class="pf-body">
      <!-- supplier -->
      <div class="pane" data-pane="supplier">
        <div class="form-grid">
          <label class="field">Supplier name<input class="input" name="x_sup[name]" value="<?= esc((string) ($xs['name'] ?? ''), 'attr') ?>" maxlength="120"></label>
          <label class="field">Company name<input class="input" name="x_sup[company]" value="<?= esc((string) ($xs['company'] ?? ''), 'attr') ?>" maxlength="160"></label>
          <label class="field">Country<input class="input" name="x_sup[country]" value="<?= esc((string) ($xs['country'] ?? ''), 'attr') ?>" maxlength="60"></label>
          <label class="field">Location (city / province)<input class="input" name="x_sup[location]" value="<?= esc((string) ($xs['location'] ?? ''), 'attr') ?>" maxlength="120"></label>
          <label class="field">Supplier type<select class="select" name="x_sup[type]"><option value="">—</option><?php foreach (\App\Modules\Products\Services\ProductExtras::SUPPLIER_TYPES as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) ($xs['type'] ?? ''), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="check" style="align-self:end"><input type="checkbox" name="x_sup[verified]" value="1" <?= checked(! empty($xs['verified'])) ?>>Verified supplier</label>
          <label class="field">Years of experience<input class="input" type="number" min="0" step="1" name="x_sup[years]" value="<?= esc($nz($xs['years'] ?? null), 'attr') ?>"><?= $err('x_sup_years') ?></label>
          <label class="field">Response time<input class="input" name="x_sup[response]" value="<?= esc((string) ($xs['response'] ?? ''), 'attr') ?>" maxlength="40" placeholder="≤4h"></label>
          <label class="field">On-time dispatch rate (%)<input class="input" type="number" min="0" max="100" step="any" name="x_sup[dispatch]" value="<?= esc($nz($xs['dispatch'] ?? null), 'attr') ?>"><?= $err('x_sup_dispatch') ?></label>
          <label class="field">Store rating (0–5)<input class="input" type="number" min="0" max="5" step="0.1" name="x_sup[rating]" value="<?= esc($nz($xs['rating'] ?? null), 'attr') ?>"><?= $err('x_sup_rating') ?></label>
          <label class="field">Review count<input class="input" type="number" min="0" step="1" name="x_sup[reviews]" value="<?= esc($nz($xs['reviews'] ?? null), 'attr') ?>"></label>
        </div>
      </div>
        </div></details>
        <details class="pf-sub" ><summary>Packaging &amp; delivery</summary><div class="pf-body">
      <!-- packaging & delivery -->
      <div class="pane" data-pane="packaging">
        <div class="form-grid">
          <label class="field">Selling unit<input class="input" name="x_pkg[unit]" value="<?= esc((string) ($xk['unit'] ?? ''), 'attr') ?>" maxlength="40" placeholder="Single item / Set / Carton"></label>
          <label class="field">Package type<input class="input" name="x_pkg[type]" value="<?= esc((string) ($xk['type'] ?? ''), 'attr') ?>" maxlength="60" placeholder="Retail box"></label>
          <label class="field">Package length<input class="input" type="number" min="0" step="any" name="x_pkg[length]" value="<?= esc($nz($xk['length'] ?? null), 'attr') ?>"><?= $err('x_pkg_length') ?></label>
          <label class="field">Package width<input class="input" type="number" min="0" step="any" name="x_pkg[width]" value="<?= esc($nz($xk['width'] ?? null), 'attr') ?>"></label>
          <label class="field">Package height<input class="input" type="number" min="0" step="any" name="x_pkg[height]" value="<?= esc($nz($xk['height'] ?? null), 'attr') ?>"></label>
          <label class="field">Size unit<select class="select" name="x_pkg[dim_unit]"><?php foreach (['mm', 'cm', 'in'] as $u): ?><option <?= selected((string) ($xk['dim_unit'] ?? 'cm'), $u) ?>><?= $u ?></option><?php endforeach ?></select></label>
          <label class="field">Gross weight<input class="input" type="number" min="0" step="any" name="x_pkg[gross]" value="<?= esc($nz($xk['gross'] ?? null), 'attr') ?>"><?= $err('x_pkg_gross') ?></label>
          <label class="field">Net weight<input class="input" type="number" min="0" step="any" name="x_pkg[net]" value="<?= esc($nz($xk['net'] ?? null), 'attr') ?>"></label>
          <label class="field">Weight unit<select class="select" name="x_pkg[weight_unit]"><?php foreach (['g', 'kg', 'lb'] as $u): ?><option <?= selected((string) ($xk['weight_unit'] ?? 'kg'), $u) ?>><?= $u ?></option><?php endforeach ?></select></label>
          <label class="field span2">Shipping information<textarea class="input" rows="3" name="x_pkg[shipping]" maxlength="600" placeholder="Dispatch time, couriers, delivery notes…"><?= esc((string) ($xk['shipping'] ?? '')) ?></textarea></label>
        </div>
      </div>
        </div></details>
        <details class="pf-sub" ><summary>Customization services</summary><div class="pf-body">
      <!-- customization -->
      <div class="pane" data-pane="custom">
        <p class="muted" style="margin:0 0 8px;font-size:13px">Optional services a buyer can ask for. Each has its own minimum order and extra cost.</p>
        <?php foreach (\App\Modules\Products\Services\ProductExtras::CUSTOMIZATIONS as $ck => $cl): $c = (array) ($xc[$ck] ?? []); ?>
          <div class="form-grid" style="border:1px solid var(--line,#ddd);border-radius:10px;padding:10px;margin-bottom:8px">
            <label class="check span2"><input type="checkbox" name="x_cust[<?= $ck ?>][enabled]" value="1" <?= checked(! empty($c['enabled'])) ?>><b><?= esc($cl) ?></b></label>
            <label class="field">Minimum order quantity<input class="input" type="number" min="0" step="1" name="x_cust[<?= $ck ?>][moq]" value="<?= esc($nz($c['moq'] ?? null), 'attr') ?>"><?= $err('x_cust_' . $ck . '_moq') ?></label>
            <label class="field">Additional cost (<?= esc($cur['symbol']) ?> per unit)<input class="input" type="number" min="0" step="any" name="x_cust[<?= $ck ?>][cost]" value="<?= esc($nz($c['cost'] ?? null), 'attr') ?>"><?= $err('x_cust_' . $ck . '_cost') ?></label>
            <label class="field span2">Note<input class="input" name="x_cust[<?= $ck ?>][note]" value="<?= esc((string) ($c['note'] ?? ''), 'attr') ?>" maxlength="200"></label>
          </div>
        <?php endforeach ?>
      </div>
        </div></details>
        <details class="pf-sub" ><summary>Reviews<?= $reviewCount ? ' (' . $reviewCount . ')' : '' ?></summary><div class="pf-body">
      <!-- reviews -->
      <div class="pane" data-pane="reviews">
        <?php if ($isNew): ?><p class="muted">Save the product first — then you can add and moderate its reviews here.</p>
        <?php else: ?>
          <p><b><?= (int) $reviewCount ?></b> review<?= $reviewCount === 1 ? '' : 's' ?> for this product. Customers send reviews from the website (they wait for your approval); you can also add, approve, reject or delete reviews, mark a purchase as verified and attach pictures.</p>
          <a class="btn primary" href="<?= esc(site_url('products/' . $p['id'] . '/reviews'), 'attr') ?>"><?= icon('star', 'sm') ?>Manage reviews</a>
        <?php endif ?>
      </div>
        </div></details>
<?php if (! $web): ?>
        <details class="pf-sub" ><summary>Online listing (website)</summary><div class="pf-body">
        <div class="form-grid">
          <label class="field span2">Short description <small class="muted">— one or two lines under the title</small><input class="input" name="x_short" value="<?= old_or('x_short', ($extra['details']['short_description'] ?? '')) ?>" maxlength="500"></label>
          <label class="field span2">Description <small class="muted">— shown on the website</small><textarea class="input" name="description" rows="3" maxlength="5000"><?= esc((string) $v('description', '')) ?></textarea></label>
          <label class="field">Slug <small class="muted">— web address, made from the name if empty</small><input class="input" name="x_slug" value="<?= old_or('x_slug', ($extra['details']['slug'] ?? '')) ?>" maxlength="160" placeholder="niimbot-b1-label-printer"></label>
          <label class="field">Product status<select class="select" name="x_status"><?php foreach (\App\Modules\Products\Services\ProductExtras::STATUSES as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) (old('x_status') ?? ($extra['details']['status'] ?? 'active')), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="check"><input type="hidden" name="is_online" value="0"><input type="checkbox" name="is_online" value="1" <?= checked($v('is_online', 1)) ?>>Show online (website &amp; marketplace)</label>
          <label class="check"><input type="hidden" name="is_featured" value="0"><input type="checkbox" name="is_featured" value="1" <?= checked($v('is_featured', 0)) ?>>Featured product</label>
        </div>
        <details class="pf-sub"><summary>Search engines (SEO)</summary><div class="pf-body">
      <!-- seo -->
      <div class="pane" data-pane="seo">
        <div class="form-grid">
          <label class="field span2">Page title<input class="input" name="x_seo_title" value="<?= old_or('x_seo_title', $xd['seo_title'] ?? '') ?>" maxlength="160" placeholder="Defaults to the product name"></label>
          <label class="field span2">Meta description<textarea class="input" rows="2" name="x_seo_desc" maxlength="300" placeholder="Shown in Google results"><?= esc((string) $xv('x_seo_desc', $xd['seo_description'] ?? '')) ?></textarea></label>
          <label class="field span2">Keywords<input class="input" name="x_seo_kw" value="<?= old_or('x_seo_kw', $xd['seo_keywords'] ?? '') ?>" maxlength="300" placeholder="thermal printer, label printer"></label>
        </div>
      </div>
        </div></details>
        </div></details>
<?php endif ?>
        </div>
      </details>

      <script>
      (function () {
        var f = document.currentScript.closest('form'); if (!f) return;
        f.addEventListener('click', function (e) {
          var mv = e.target.closest('[data-pic-mv]');
          if (mv) { var t = mv.closest('[data-pic]'), d = +mv.getAttribute('data-pic-mv'); if (d < 0 && t.previousElementSibling) t.parentNode.insertBefore(t, t.previousElementSibling); if (d > 0 && t.nextElementSibling) t.parentNode.insertBefore(t.nextElementSibling, t); return; }
          if (e.target.closest('[data-xa-add]')) {
            var box = f.querySelector('[data-xa-rows]'), tpl = f.querySelector('[data-xa-tpl]'), n = +box.getAttribute('data-next');
            box.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__N__/g, n)); box.setAttribute('data-next', n + 1);
          }
        });
      })();
      </script>

      </fieldset>

      <div class="bo-actions">
        <?php if ($readOnly): ?>
          <span class="muted" style="margin-right:auto">View only — you need “Add, edit &amp; delete products”.</span>
          <a class="btn primary" href="<?= esc(site_url('products'), 'attr') ?>">Close</a>
        <?php else: ?>
          <a class="btn" href="<?= esc(site_url('products'), 'attr') ?>">Cancel</a>
          <?php if ($isNew): ?><button class="btn" type="submit" name="again" value="1">Create &amp; add another</button><?php endif ?>
          <button class="btn primary" type="submit"><?= icon('check', 'sm') ?><?= $isNew ? 'Create product' : 'Save product' ?></button>
        <?php endif ?>
      </div>
    </div>
  </form>
</div>
<?php $asset = static fn (string $f): string => base_url('assets/pos/' . $f) . '?v=' . (is_file(FCPATH . 'assets/pos/' . $f) ? filemtime(FCPATH . 'assets/pos/' . $f) : 0); ?>
<link rel="stylesheet" href="<?= esc($asset('product-form.css'), 'attr') ?>">
<?php if (! $readOnly): ?><script src="<?= esc($asset('product-form.js'), 'attr') ?>" defer></script><?php endif ?>
