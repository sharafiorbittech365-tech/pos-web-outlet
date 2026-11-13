<?php
/**
 * New purchase: supplier, bill, items (catalogue product or free text), payment, receive into stock.
 *
 * @var list<array>           $suppliers
 * @var list<array>           $products
 * @var int                   $supplierId
 * @var array<string, string> $methods
 * @var array                 $cur
 */
helper('ui');
$items = old('items') ?? [[], [], []];
$productSelect = static function (int $i, int $sel) use ($products): string {
    $h = '<select class="select" name="items[' . $i . '][product_id]" aria-label="Product"><option value="0">— not in catalogue —</option>';
    foreach ($products as $p) {
        $h .= '<option value="' . $p['id'] . '" ' . selected($sel, $p['id']) . ' data-cost="' . esc((string) $p['cost'], 'attr') . '">' . esc($p['name']) . ($p['stock_type'] === 'serial' ? ' (IMEI)' : ($p['stock_type'] === 'batch' ? ' (batch)' : '')) . '</option>';
    }

    return $h . '</select>';
};
$row = static function (int|string $i, array $it) use ($productSelect): string {
    $n = is_int($i) ? $i : 0;
    $idx = (string) $i;

    return '<div class="bo-row" data-row style="--cols:7">'
        . '<label class="field">Product' . ($idx === '__i__' ? str_replace('[0]', '[__i__]', $productSelect(0, 0)) : $productSelect($n, (int) ($it['product_id'] ?? 0))) . '</label>'
        . '<label class="field">Or item name<input class="input" name="items[' . $idx . '][name]" value="' . esc((string) ($it['name'] ?? ''), 'attr') . '" maxlength="200"></label>'
        . '<label class="field">Qty<input class="input" type="number" min="0" step="any" name="items[' . $idx . '][qty]" value="' . esc((string) ($it['qty'] ?? ''), 'attr') . '"></label>'
        . '<label class="field">Unit cost<input class="input" type="number" min="0" step="any" name="items[' . $idx . '][unit_cost]" value="' . esc((string) ($it['unit_cost'] ?? ''), 'attr') . '"></label>'
        . '<label class="field">Batch no.<input class="input" name="items[' . $idx . '][batch_no]" value="' . esc((string) ($it['batch_no'] ?? ''), 'attr') . '" placeholder="batch items"></label>'
        . '<label class="field">Expiry<input class="input" type="date" name="items[' . $idx . '][expires_on]" value="' . esc((string) ($it['expires_on'] ?? ''), 'attr') . '"></label>'
        . '<label class="field">IMEI / serials<input class="input" name="items[' . $idx . '][serials]" value="' . esc((string) ($it['serials'] ?? ''), 'attr') . '" placeholder="comma separated"></label>'
        . '<button class="x-btn" type="button" data-row-del aria-label="Remove item">' . icon('trash', 'sm') . '</button></div>';
};
?>
<div class="page">
  <div class="page-h">
    <div><h2>New purchase</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('purchases'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Purchases</a></p></div>
    <div class="spacer"></div>
    <button class="btn" type="button" data-modal-open="dlg-supplier"><?= icon('userplus', 'sm') ?>Add supplier</button>
  </div>
  <?php if (! $suppliers): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('truck') ?></span><b>Add a supplier first</b><span>Purchases are recorded against a supplier.</span><button class="btn primary" type="button" data-modal-open="dlg-supplier"><?= icon('userplus', 'sm') ?>Add supplier</button></div></div>
  <?php else: ?>
  <form class="card" method="post" action="<?= esc(site_url('purchases'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="card-b">
      <div class="form-grid">
        <label class="field">Supplier *<select class="select" name="supplier_id" required><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= selected(old('supplier_id') ?? $supplierId, $s['id']) ?>><?= esc($s['name']) ?></option><?php endforeach ?></select></label>
        <label class="field">Supplier bill / invoice no.<input class="input" name="bill_no" value="<?= old_or('bill_no') ?>" maxlength="48"></label>
        <label class="field">Purchase date<input class="input" type="date" name="purchased_at" value="<?= old_or('purchased_at', date('Y-m-d')) ?>" max="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
        <label class="field">Note<input class="input" name="note" value="<?= old_or('note') ?>" maxlength="255"></label>
      </div>
      <div class="field">Items <?= field_error('items') ?></div>
      <div class="bo-rows" data-rows="items" data-next="<?= count($items) ?>">
        <?php foreach ($items as $i => $it): ?><?= $row((int) $i, (array) $it) ?><?php endforeach ?>
      </div>
      <template data-row-tpl="items"><?= $row('__i__', []) ?></template>
      <div><button class="btn soft" type="button" data-row-add="items"><?= icon('plus', 'sm') ?>Add item</button></div>
      <small class="muted">Batch no. and expiry are used for batch-tracked products; IMEI / serials for serial-tracked ones (their count is the quantity).</small>
      <div class="form-grid">
        <label class="field">Paid now (<?= esc($cur['symbol']) ?>)<input class="input" type="number" min="0" step="any" name="paid" value="<?= old_or('paid', '0') ?>"><?= field_error('paid') ?></label>
        <label class="field">Paid by<select class="select" name="method"><?php foreach ($methods as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected(old('method') ?? 'cash', $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
        <label class="field">Payment reference<input class="input" name="reference" value="<?= old_or('reference') ?>" maxlength="64"></label>
        <span></span>
        <?php if ($stockLinked): ?><label class="check"><input type="hidden" name="receive" value="0"><input type="checkbox" name="receive" value="1" <?= checked(old('receive') ?? 1) ?>>Add received items to stock</label>
        <?php else: ?><span class="muted" style="font-size:12.5px;align-self:center">Stock is not updated by purchases (Inventory or "Automatic stock update" is off in Setup).</span><?php endif ?>
        <label class="check"><input type="hidden" name="update_cost" value="0"><input type="checkbox" name="update_cost" value="1" <?= checked(old('update_cost') ?? 1) ?>>Update product cost from this purchase</label>
      </div>
      <div class="bo-actions"><a class="btn" href="<?= esc(site_url('purchases'), 'attr') ?>">Cancel</a><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save purchase</button></div>
    </div>
  </form>
  <?php endif ?>
</div>
<?= view('App\Modules\Purchases\Views\_dialogs', ['suppliers' => $suppliers, 'methods' => $methods]) ?>
