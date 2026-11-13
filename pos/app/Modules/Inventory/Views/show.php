<?php
/**
 * Stock of one product: adjust (simple / options), serials, batches, and its movement log.
 *
 * @var array<string, mixed> $p
 * @var bool                 $manage
 * @var string               $log
 * @var array<string, array> $reasons
 */
helper('ui');
$unit = $p['unit'] ?: 'pcs';
$reasonSelect = static function (string $name = 'reason') use ($reasons): string {
    $h = '<select class="select" name="' . esc($name, 'attr') . '">';
    foreach ($reasons as $k => [$l]) {
        $h .= '<option value="' . esc($k, 'attr') . '">' . esc($l) . '</option>';
    }

    return $h . '</select>';
};
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= sym_icon($p['icon'] ?: 'package') ?> <?= esc($p['name']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('inventory'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Inventory</a></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('products/' . $p['id']), 'attr') ?>"><?= icon('edit', 'sm') ?>Edit product</a>
  </div>

  <div class="stats">
    <div class="stat"><?= icon('box') ?><small>In stock</small><b class="<?= (float) $p['stock_qty'] <= 0 ? 'neg' : '' ?>"><?= esc(qty($p['stock_qty'], $unit)) ?></b></div>
    <div class="stat"><?= icon('tag') ?><small>Tracking</small><b><?= esc(['simple' => 'Simple count', 'variant' => 'Per option', 'serial' => 'IMEI / serial', 'batch' => 'Batch & expiry', 'none' => 'Not tracked'][$p['stock_type']] ?? $p['stock_type']) ?></b></div>
    <?php if ($p['stock_type'] === 'serial'): ?><div class="stat"><?= icon('receipt') ?><small>Sold units</small><b><?= (int) $p['sold'] ?></b></div><?php endif ?>
    <div class="stat"><?= icon('alert') ?><small>Low-stock alert at</small><b><?= esc(qty($p['low_stock_at'] ?? service('posSession')->business()['low_stock_alert'] ?? 0)) ?></b></div>
  </div>

  <?php if ($p['stock_type'] === 'none'): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('box') ?></span><b>Not tracked</b><span>Change how stock is tracked in the product form.</span></div></div>
  <?php endif ?>

  <?php if ($manage && in_array($p['stock_type'], ['simple', 'variant'], true)): ?>
    <section class="card"><div class="card-h"><h3>Adjust stock</h3></div><div class="card-b">
      <form method="post" action="<?= esc(site_url('inventory/' . $p['id'] . '/adjust'), 'attr') ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
          <?php if ($p['stock_type'] === 'variant'): ?>
            <label class="field">Option<select class="select" name="variant_id" required>
              <?php foreach ($p['variants'] as $vr): ?><option value="<?= (int) $vr['id'] ?>"><?= esc($vr['name']) ?> — <?= esc(qty($vr['stock_qty'] ?? 0)) ?> in stock</option><?php endforeach ?>
            </select><?= field_error('variant_id') ?></label>
          <?php endif ?>
          <label class="field">Adjust by (+ receive / − remove)<input class="input" name="delta" type="number" step="any" placeholder="e.g. 50 or -2" required autofocus><?= field_error('delta') ?></label>
          <label class="field">Reason<?= $reasonSelect() ?></label>
          <label class="field">Unit cost (when receiving)<input class="input" name="cost" type="number" step="any" min="0" value="<?= (float) $p['cost'] > 0 ? esc((string) $p['cost'], 'attr') : '' ?>"></label>
          <label class="field span2">Note<input class="input" name="note" maxlength="200" placeholder="e.g. Counted on shelf, 2 damaged in transit"></label>
        </div>
        <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save stock</button></div>
      </form>
      <?php if ($p['stock_type'] === 'variant' && $p['variants']): ?>
        <table class="tbl compact"><thead><tr><th>Option</th><th class="num">In stock</th></tr></thead><tbody>
          <?php foreach ($p['variants'] as $vr): ?><tr><td><?= esc($vr['name']) ?></td><td class="num"><b><?= esc(qty($vr['stock_qty'] ?? 0)) ?></b></td></tr><?php endforeach ?>
        </tbody></table>
      <?php endif ?>
    </div></section>
  <?php endif ?>

  <?php if ($p['stock_type'] === 'serial'): ?>
    <div class="reg-grid">
      <section class="card"><div class="card-h"><h3>In stock · <?= count($p['serials']) ?></h3></div>
        <div class="table-wrap"><table class="tbl compact"><thead><tr><th>IMEI / serial</th><th>Received</th><th></th></tr></thead><tbody>
          <?php foreach ($p['serials'] as $sn): ?>
            <tr><td><code><?= esc($sn['serial']) ?></code><?= $sn['status'] === 'returned' ? ' ' . tag_html('Returned', 'warn') : '' ?></td><td><?= esc(date_str($sn['created_at'])) ?></td>
              <td class="num"><?php if ($manage): ?><form class="inline" method="post" action="<?= esc(site_url('inventory/' . $p['id'] . '/serials/' . $sn['id'] . '/remove'), 'attr') ?>" data-confirm="Take <?= esc($sn['serial'], 'attr') ?> out of stock (faulty / lost)?"><?= csrf_field() ?><input type="hidden" name="note" value="Faulty / removed"><button class="x-btn" type="submit" title="Remove from stock"><?= icon('trash', 'sm') ?></button></form><?php endif ?></td></tr>
          <?php endforeach ?>
          <?php if (! $p['serials']): ?><tr><td colspan="3" class="muted">No units in stock.</td></tr><?php endif ?>
        </tbody></table></div>
      </section>
      <?php if ($manage): ?>
        <section class="card"><div class="card-h"><h3>Receive units</h3></div><div class="card-b">
          <form method="post" action="<?= esc(site_url('inventory/' . $p['id'] . '/serials'), 'attr') ?>">
            <?= csrf_field() ?>
            <label class="field">New IMEI / serial numbers — one per line<textarea class="input mono" name="serials" rows="8" required></textarea></label>
            <label class="field">Note<input class="input" name="note" maxlength="120" placeholder="e.g. Supplier invoice 1234"></label>
            <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add to stock</button></div>
          </form>
        </div></section>
      <?php endif ?>
    </div>
  <?php endif ?>

  <?php if ($p['stock_type'] === 'batch'): ?>
    <section class="card"><div class="card-h"><h3>Batches</h3><small class="muted">Sales pick the batch that expires first (FEFO). Expired batches are never sold.</small></div>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Batch no.</th><th>Expiry</th><th class="num">Qty (<?= esc($unit) ?>)</th><?php if ($manage): ?><th>Adjust</th><?php endif ?></tr></thead><tbody>
        <?php foreach ($p['batches'] as $bt): $left = $bt['expires_on'] ? (int) floor((strtotime($bt['expires_on']) - strtotime('today')) / 86400) : null; ?>
          <tr>
            <td><b><?= esc($bt['batch_no']) ?></b></td>
            <td><?= esc(date_str($bt['expires_on'])) ?> <?= $left === null ? '' : ($left < 0 ? tag_html('Expired', 'danger') : ($left <= 30 ? tag_html($left . 'd', 'warn') : '')) ?></td>
            <td class="num"><b><?= esc(qty($bt['qty'])) ?></b></td>
            <?php if ($manage): ?><td>
              <form class="inline-form" method="post" action="<?= esc(site_url('inventory/' . $p['id'] . '/batches/' . $bt['id']), 'attr') ?>">
                <?= csrf_field() ?>
                <input class="input sm" type="number" step="any" name="delta" placeholder="± qty" required style="width:90px" aria-label="Adjust by">
                <?= $reasonSelect() ?>
                <button class="btn sm" type="submit">Save</button>
              </form>
            </td><?php endif ?>
          </tr>
        <?php endforeach ?>
        <?php if (! $p['batches']): ?><tr><td colspan="4" class="muted">No batches yet.</td></tr><?php endif ?>
      </tbody></table></div>
      <?php if ($manage): ?>
        <div class="card-b">
          <form class="row-add" method="post" action="<?= esc(site_url('inventory/' . $p['id'] . '/batches'), 'attr') ?>">
            <?= csrf_field() ?>
            <input class="input" name="batch_no" placeholder="Batch no." required maxlength="48" aria-label="Batch number">
            <input class="input" type="date" name="expires_on" aria-label="Expiry date">
            <input class="input" type="number" step="any" min="0" name="qty" placeholder="Qty" required aria-label="Quantity" style="width:110px">
            <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Receive batch</button>
          </form>
        </div>
      <?php endif ?>
    </section>
  <?php endif ?>

  <section class="card"><div class="card-h"><h3>Stock movements</h3></div><?= $log ?></section>
</div>
