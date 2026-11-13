<?php
/**
 * @var array<string, mixed>  $p
 * @var array                 $cur
 * @var array<string, string> $methods
 * @var array<string, array>  $states
 */
helper('ui');
$b = service('posSession')->business();
$due = round((float) $p['total'] - (float) $p['paid'], 2);
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= esc($p['reference']) ?> · <?= esc($p['supplier']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('purchases'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Purchases</a></p></div>
    <div class="spacer"></div>
    <?php if (\App\Modules\Purchases\Services\PurchaseSettings::on('supplier')): ?><a class="btn" href="<?= esc(site_url('purchases/suppliers/' . $p['supplier_id']), 'attr') ?>"><?= icon('book', 'sm') ?>Supplier ledger</a><?php endif ?>
    <button class="btn" type="button" data-print><?= icon('print', 'sm') ?>Print</button>
  </div>
  <section class="card report">
    <div class="report-head"><b><?= esc($b['name'] ?? '') ?></b> · Purchase <?= esc($p['reference']) ?> · printed <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></div>
    <div class="card-b">
      <dl class="bo-kv">
        <dt>Supplier</dt><dd><?= esc($p['supplier']) ?><?= $p['supplier_phone'] ? ' · ' . esc($p['supplier_phone']) : '' ?></dd>
        <dt>Date</dt><dd><?= esc(date_str($p['purchased_at'])) ?></dd>
        <?php if ($p['bill_no']): ?><dt>Supplier bill</dt><dd><?= esc($p['bill_no']) ?></dd><?php endif ?>
        <dt>Status</dt><dd><?= tag_html(...$states[$p['status']]) ?></dd>
        <?php if ($p['user_name']): ?><dt>Recorded by</dt><dd><?= esc($p['user_name']) ?></dd><?php endif ?>
        <?php if ($p['note']): ?><dt>Note</dt><dd><?= esc($p['note']) ?></dd><?php endif ?>
      </dl>
    </div>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Total</th></tr></thead><tbody>
      <?php foreach ($p['items'] as $it): ?>
        <tr><td><?= $it['product_id'] ? '<a class="link" href="' . esc(site_url('inventory/' . $it['product_id']), 'attr') . '">' . esc($it['name']) . '</a>' : esc($it['name']) ?></td><td class="num"><?= esc(qty($it['qty'])) ?></td><td class="num"><?= esc(money($it['unit_cost'], $cur)) ?></td><td class="num"><b><?= esc(money($it['line_total'], $cur)) ?></b></td></tr>
      <?php endforeach ?>
      <tr class="hl"><td colspan="3"><b>Total</b></td><td class="num"><b><?= esc(money($p['total'], $cur)) ?></b></td></tr>
      <tr><td colspan="3">Paid</td><td class="num"><?= esc(money($p['paid'], $cur)) ?></td></tr>
      <tr><td colspan="3">Due</td><td class="num"><b class="<?= $due > 0 ? 'neg' : '' ?>"><?= esc(money($due, $cur)) ?></b></td></tr>
    </tbody></table></div>
    <?php if ($p['payments']): ?>
      <div class="card-h"><h3>Payments</h3></div>
      <div class="table-wrap"><table class="tbl compact"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($p['payments'] as $pay): ?><tr><td><?= esc(datetime_str($pay['paid_at'])) ?></td><td><?= esc($methods[$pay['method']] ?? $pay['method']) ?></td><td><?= esc($pay['reference'] ?? '') ?></td><td class="num"><?= esc(money($pay['amount'], $cur)) ?></td></tr><?php endforeach ?>
      </tbody></table></div>
    <?php endif ?>
  </section>
</div>
