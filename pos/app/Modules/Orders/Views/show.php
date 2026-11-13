<?php
/**
 * Order detail: the invoice as printed, payments, VAT breakdown, returns and actions.
 *
 * @var array<string, mixed> $o       OrderService::find()
 * @var array<string, mixed> $d       OrderDocs::build()
 * @var string               $size    document shown on the page (a4 | 80)
 * @var array<string, array{size: string, copies: int}> $print default paper per document (PrintSettings)
 * @var list<string>         $methods payment methods (no credit)
 * @var ?array<string, mixed> $place  customer location (OrderLocation::for): map pin, city / area names
 */
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderQueries;

helper(['ui', 'pos']);
$s = service('posSession');
$cur = $d['cur'];
$F = static fn ($n): string => esc(money($n, $cur));
$due = (float) $o['due_total'];
$canPay = $due > 0 && ! in_array($o['status'], ['cancelled', 'returned'], true) && ($s->can('pos.sell') || $s->can('customers.receive'));
$canReturn = $s->can('pos.return') && in_array($o['status'], ['paid', 'partial', 'due', 'open'], true);
$left = array_filter($o['items'], static fn ($l) => (float) $l['qty'] - (float) $l['returned_qty'] > 0);
$features = (array) ($d['business']['settings']['features'] ?? []);
$ship = $o['ship_status'];
$steps = ['ordered', 'packed', 'out_for_delivery', 'delivered'];
$p = static fn (string $q): string => esc(site_url('orders/' . $o['id'] . '/print?' . $q . '&auto=1'), 'attr');
$paper = static fn (string $doc): string => ['58' => '58 mm', '80' => '80 mm', 'a4' => 'A4', 'a5' => 'A5'][$print[$doc]['size']] . ($print[$doc]['copies'] > 1 ? ' × ' . $print[$doc]['copies'] : '');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Order <?= esc($o['doc_no']) ?> <?= order_status_tag($o) ?></h2><p class="page-sub"><?= esc(datetime_str($o['created_at'])) ?> · <?= esc(OrderQueries::sourceLabel($o)) ?><?= $o['user_name'] ? ' · by ' . esc($o['user_name']) : '' ?></p></div>
    <div class="spacer"></div>
    <div class="ord-acts">
    <a class="btn" href="<?= esc(site_url('orders'), 'attr') ?>"><?= icon('back', 'sm') ?>Orders</a>
    <?php if (! empty($invoice) && service('posSession')->can('invoices.view')): ?><a class="btn" href="<?= esc(site_url('invoices/' . (int) $invoice['id']), 'attr') ?>"><?= icon('file-text', 'sm') ?>Invoice <?= esc((string) $invoice['doc_no']) ?></a><?php endif ?>
    <a class="btn" href="<?= $p('doc=receipt') ?>" target="_blank" rel="noopener" title="Receipt · <?= esc($paper('receipt'), 'attr') ?>"><?= icon('print', 'sm') ?>Receipt</a>
    <a class="btn" href="<?= $p('doc=invoice') ?>" target="_blank" rel="noopener"><?= icon('print', 'sm') ?>Invoice <small class="muted"><?= esc($paper('invoice')) ?></small></a>
    <a class="btn" href="<?= $p('doc=challan') ?>" target="_blank" rel="noopener"><?= icon('truck', 'sm') ?>Challan</a>
    <?php if (in_array($o['status'], ['paid', 'partial', 'due'], true) && $s->can('mushak.issue') && $s->moduleOn('invoicing') && \App\Modules\Invoices\Services\MushakSettings::forCurrent()->enabled()): ?><a class="btn" href="<?= esc(site_url('mushak/order/' . $o['id']), 'attr') ?>"><?= icon('file-text', 'sm') ?>Mushak-6.3</a><?php endif ?>
    <a class="btn" href="<?= esc(site_url('orders/' . $o['id'] . '/print?doc=invoice'), 'attr') ?>" target="_blank" rel="noopener" data-preview title="Preview before printing"><?= icon('eye', 'sm') ?>Preview</a>
    <?php if ($o['kitchen_status'] !== 'none'): ?><a class="btn" href="<?= $p('doc=kot') ?>" target="_blank" rel="noopener"><?= icon('kitchen', 'sm') ?>Kitchen ticket</a><?php endif ?>
    <?php if ($canReturn && $left): ?><button class="btn danger" type="button" data-modal-open="ret-dlg"><?= icon('undo', 'sm') ?>Return</button><?php endif ?>
    <?php if ($canPay): ?><button class="btn primary" type="button" data-modal-open="pay-dlg" id="pay-btn"><?= icon('cash', 'sm') ?>Collect <?= $F($due) ?></button><?php endif ?>
    </div>
  </div>
  <div class="ord-grid">
    <?php /* phones show the receipt layout (an A4 page does not fit); the A4 button still prints A4 */ ?>
    <div class="doc-frame<?= $size === 'a4' ? ' has-rc' : '' ?>">
      <?php if ($size === 'a4'): ?>
        <div class="doc-a4"><?= ! empty($idoc) ? view('App\Modules\Invoices\Views\docs\invoice_a4', ['d' => $idoc]) : view('App\Modules\Orders\Views\docs\a4', ['d' => $d, 'mode' => 'invoice']) ?></div>
        <div class="doc-rc"><?= view('App\Modules\Orders\Views\docs\receipt', ['d' => $d, 'mode' => 'invoice', 'width' => 80]) ?></div>
      <?php else: ?>
        <?= view('App\Modules\Orders\Views\docs\receipt', ['d' => $d, 'mode' => 'invoice', 'width' => 80]) ?>
      <?php endif ?>
    </div>
    <div class="ord-side">
      <section class="card">
        <div class="card-h"><h3>Summary</h3><?= order_status_tag($o) ?></div>
        <dl class="kv">
          <dt>Customer</dt><dd><?php if ($o['customer_id'] && $s->can('customers.view') && $s->moduleOn('customers')): ?><a class="link" href="<?= esc(site_url('customers/' . $o['customer_id']), 'attr') ?>"><?= esc($o['customer_name']) ?></a><?php else: ?><?= esc($o['customer_name']) ?><?php endif ?></dd>
          <?php if ($o['customer_phone']): ?><dt>Phone</dt><dd><?= esc($o['customer_phone']) ?></dd><?php endif ?>
          <?php if ($o['order_type']): ?><dt>Type</dt><dd><?= esc($o['order_type']) ?></dd><?php endif ?>
          <?php if ($o['table_ref']): ?><dt><?= esc(\OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($d['business']['business_type'] ?? ''), (array) ($d['business']['settings']['labels'] ?? []))['seat']) ?></dt><dd><?= esc($o['table_ref']) ?></dd><?php endif ?>
          <dt>Items</dt><dd><?= esc(qty($o['item_count'])) ?></dd>
          <dt>Total</dt><dd><?= $F($o['total']) ?></dd>
          <dt>Paid</dt><dd><?= $F($o['paid_total']) ?></dd>
          <?php if ((float) $o['returned_total'] > 0): ?><dt>Returned</dt><dd>-<?= $F($o['returned_total']) ?></dd><?php endif ?>
          <dt>Due</dt><dd style="<?= $due > 0 ? 'color:var(--danger)' : '' ?>"><?= $F($due) ?></dd>
          <?php if ($o['kitchen_status'] !== 'none'): ?><dt><?= esc(\OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($d['business']['business_type'] ?? ''), (array) ($d['business']['settings']['labels'] ?? []))['kitchen']) ?></dt><dd><span class="tag"><?= esc(ucfirst($o['kitchen_status'])) ?></span></dd><?php endif ?>
        </dl>
      </section>
      <?php if ($ship !== null): ?>
        <section class="card">
          <div class="card-h"><h3><?= icon('truck', 'sm') ?> Delivery</h3><span class="tag primary"><?= esc(ship_label($ship)) ?></span></div>
          <?php if ($o['ship_address']): ?><p style="margin:0;padding:10px 14px 0"><?= icon('map-pin', 'sm') ?> <?= esc($o['ship_address']) ?><?= $o['geo_area'] ? ' · ' . esc($o['geo_area']) : '' ?></p><?php endif ?>
          <div class="ship-steps">
            <?php $reached = true; foreach ($steps as $st): $cls = $reached ? 'done' : ''; if ($st === $ship) { $reached = false; $cls = 'on'; } ?>
              <span class="chip sm <?= $cls ?>"><?= esc(ship_label($st)) ?></span>
            <?php endforeach ?>
          </div>
          <?php if (! in_array($ship, ['delivered', 'cancelled'], true) && $s->can('orders.view')): ?>
            <form method="post" action="<?= esc(site_url('orders/' . $o['id'] . '/ship'), 'attr') ?>" class="form-grid" style="padding:0 14px 14px">
              <?= csrf_field() ?>
              <label class="field">Update status<select class="select" name="ship_status">
                <?php foreach (OrderActions::SHIP as $st): ?><option value="<?= esc($st, 'attr') ?>" <?= selected($st, $steps[min(3, array_search($ship, $steps, true) + 1)] ?? '') ?>><?= esc(ship_label($st)) ?></option><?php endforeach ?>
              </select></label>
              <?php if ($due > 0): ?><label class="check span2"><input type="checkbox" name="collect" value="1" checked>Collect <?= $F($due) ?> cash on delivery when delivered</label><?php endif ?>
              <button class="btn primary span2" type="submit"><?= icon('check', 'sm') ?>Save delivery status</button>
            </form>
          <?php endif ?>
        </section>
      <?php endif ?>
      <?php if (! empty($place)): ?>
        <?= view('App\Modules\Orders\Views\_location', ['place' => $place]) ?>
      <?php endif ?>
      <section class="card">
        <div class="card-h"><h3>Payments</h3><span class="muted"><?= count($o['payments']) ?></span></div>
        <?php if ($o['payments']): ?>
          <table class="tbl"><tbody>
            <?php foreach ($o['payments'] as $pm): ?><tr><td><?= esc(pay_label($pm['method'], $d['code'])) ?><?php if ($pm['reference']): ?><br><small class="muted"><?= esc($pm['reference']) ?></small><?php endif ?></td><td class="nw"><small class="muted"><?= esc(datetime_str($pm['created_at'])) ?></small></td><td class="num"><b><?= $F($pm['amount']) ?></b></td></tr><?php endforeach ?>
          </tbody></table>
        <?php else: ?><p class="muted" style="padding:0 14px">No payment yet.</p><?php endif ?>
      </section>
      <section class="card">
        <div class="card-h"><h3><?= esc($d['taxLabel']) ?> breakdown</h3><span class="muted"><?= $F($o['tax_total']) ?></span></div>
        <table class="tbl"><thead><tr><th><?= esc($d['taxLabel']) ?></th><th class="num">Taxable value</th><th class="num"><?= esc($d['taxLabel']) ?></th></tr></thead><tbody>
          <?php foreach ($o['taxes'] as $t): ?><tr><td><?= $t['tax_mode'] === 'exempt' ? 'Exempt' : esc(rate_str($t['rate'])) . '% ' . ($t['tax_mode'] === 'inclusive' ? 'included' : 'added') ?></td><td class="num"><?= $F($t['taxable']) ?></td><td class="num"><?= $F($t['tax']) ?></td></tr><?php endforeach ?>
          <?php if (! $o['taxes']): ?><tr><td colspan="3" class="muted">No <?= esc($d['taxLabel']) ?> on this order.</td></tr><?php endif ?>
        </tbody></table>
      </section>
      <?php if ($o['returns']): ?>
        <section class="card">
          <div class="card-h"><h3>Returns</h3><span class="muted">-<?= $F($o['returned_total']) ?></span></div>
          <table class="tbl"><tbody>
            <?php foreach ($o['returns'] as $r): ?><tr><td><?= esc(datetime_str($r['created_at'])) ?><br><small class="muted"><?= $r['method'] === 'credit' ? 'Off the due' : esc(pay_label($r['method'], $d['code'])) ?><?= $r['reason'] ? ' · ' . esc($r['reason']) : '' ?></small></td><td class="num"><b><?= $F($r['amount']) ?></b></td></tr><?php endforeach ?>
          </tbody></table>
        </section>
      <?php endif ?>
    </div>
  </div>
</div>

<?php if ($canPay): ?>
<dialog class="modal-dlg" id="pay-dlg">
  <form method="post" action="<?= esc(site_url('orders/' . $o['id'] . '/pay'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Collect payment · <?= esc($o['doc_no']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <div class="due-box"><small>Due</small><b><?= $F($due) ?></b></div>
      <div class="form-grid">
        <label class="field">Amount received<input class="input" type="number" name="amount" step="any" min="0.01" value="<?= esc((string) $due, 'attr') ?>" required autofocus></label>
        <label class="field">Method<select class="select" name="method"><?php foreach ($methods as $m): ?><option value="<?= esc($m, 'attr') ?>"><?= esc(pay_label($m, $d['code'])) ?></option><?php endforeach ?><?php if ($o['channel'] !== 'pos'): ?><option value="cod">Cash on delivery</option><?php endif ?></select></label>
        <label class="field span2">Reference<input class="input" name="reference" maxlength="64" placeholder="Trx ID / card slip / cheque no."></label>
      </div>
      <?php if ($o['status'] === 'open'): ?><div class="muted" style="font-size:12.5px">Cash above the due is returned as change. Leaving part of an open order unpaid puts it on the customer's due book.</div><?php endif ?>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Record payment</button></div>
  </form>
</dialog>
<?php endif ?>

<?php if ($canReturn && $left): ?>
<dialog class="modal-dlg lg" id="ret-dlg">
  <form method="post" action="<?= esc(site_url('orders/' . $o['id'] . '/return'), 'attr') ?>" data-confirm="Return the selected items and restock them?">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Sell return · <?= esc($o['doc_no']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <table class="tbl"><thead><tr><th>Product</th><th class="num">Sold</th><th class="num">Unit</th><th>Return qty</th></tr></thead><tbody>
        <?php foreach ($o['items'] as $l): $rest = (float) $l['qty'] - (float) $l['returned_qty']; ?>
          <tr><td><?= esc($l['name']) ?><?php if ($l['variant_name']): ?><br><small class="muted"><?= esc($l['variant_name']) ?></small><?php endif ?><?php if ($l['serial']): ?><br><small class="muted">IMEI/SN <?= esc($l['serial']) ?></small><?php endif ?></td>
            <td class="num"><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?><?php if ((float) $l['returned_qty'] > 0): ?><br><small class="muted">returned <?= esc(qty($l['returned_qty'])) ?></small><?php endif ?></td>
            <td class="num"><?= $F((float) $l['unit_price'] - (float) $l['discount']) ?></td>
            <td><input class="input ret-qty" type="number" name="qty[<?= (int) $l['id'] ?>]" min="0" max="<?= esc((string) $rest, 'attr') ?>" step="<?= ! empty($features['decimalQty']) || ! empty($features['weighted']) ? 'any' : '1' ?>" value="0" <?= $rest <= 0 ? 'disabled' : '' ?>></td></tr>
        <?php endforeach ?>
      </tbody></table>
      <label class="field">Reason<input class="input" name="reason" maxlength="200" placeholder="e.g. damaged, wrong size"></label>
      <div class="field"><span>Give the value back as</span>
        <label class="check"><input type="radio" name="refund_as" value="refund" checked>Refund (cash / off the due)</label>
        <label class="check"><input type="radio" name="refund_as" value="exchange" <?= ! empty($o['customer_id']) && $o['customer_name'] !== 'Walk-in Customer' ? '' : 'disabled' ?>>Exchange credit — keep it for the customer's next invoice <small class="muted">(needs a named customer)</small></label></div>
      <div class="muted" style="font-size:12.5px">The refund is the item's share of what was paid (discount and added <?= esc($d['taxLabel']) ?> included). For a credit sale the due is reduced first; stock goes back.</div>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn danger solid" type="submit"><?= icon('undo', 'sm') ?>Refund &amp; restock</button></div>
  </form>
</dialog>
<?php endif ?>
<script>
  if (location.hash === '#pay') document.addEventListener('DOMContentLoaded', function () { var b = document.getElementById('pay-btn'); if (b) b.click(); });
</script>
