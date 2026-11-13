<?php
/**
 * A4 invoice / delivery challan — the design build's a4HTML (logo template, modern style):
 * VAT column per line, VAT rows per rate (incl. / added) and the "Taxable value | VAT" table.
 *
 * @var array<string, mixed> $d     OrderDocs::build()
 * @var string               $mode  invoice | challan
 */
helper(['ui', 'pos']);
$o = $d['order'];
$b = $d['business'];
$F = static fn ($n): string => esc(money($n, $d['cur']));
$challan = $mode === 'challan';
$svc = $d['service'];
$vatOn = ! $challan && $d['vatOn'];
$labels = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));
$extra = round((float) $o['total'] - (float) $o['subtotal'] + (float) $o['discount_total'] - (float) $o['service_charge'] - (float) $o['packing'] - (float) $o['tax_added'] - (float) $o['shipping'] - (float) $o['round_off'], 2);
$title = $challan ? 'DELIVERY CHALLAN' : ($svc ? 'SERVICE INVOICE' : 'INVOICE');
?>
<div class="a4 a4-modern" style="--ia:<?= css_color($d['primary']) ?>">
  <div class="a4-head">
    <div class="a4-brand"><?php $lg = doc_logo($d); ?><?php if ($lg !== ''): ?><div class="a4-logo emo"><?= $lg ?></div><?php endif ?><div><h1><?= esc($b['name']) ?></h1><p><?= esc($b['address'] ?: ($b['settings']['branch'] ?? '')) ?><?php if (! empty($b['phone'])): ?><br>Phone: <?= esc($b['phone']) ?><?php endif ?><?php if (! empty($b['settings']['bin'])): ?><br>BIN / VAT Reg: <?= esc($b['settings']['bin']) ?><?php endif ?></p></div></div>
    <div class="a4-title"><h2><?= esc($title) ?></h2>
      <table>
        <tr><td><?= $challan ? 'Challan No' : 'Invoice No' ?></td><td><b><?= esc($o['doc_no']) ?></b></td></tr>
        <tr><td>Date</td><td><?= esc(datetime_str($o['created_at'])) ?></td></tr>
        <?php if (! empty($o['due_date'])): ?><tr><td><?= esc($labels['dueDate'] ?? 'Due date') ?></td><td><?= esc(date_str($o['due_date'])) ?></td></tr><?php endif ?>
        <?php if (! $challan): ?><tr><td>Status</td><td><b><?= esc($d['statusText']) ?></b></td></tr><?php endif ?>
      </table>
    </div>
  </div>
  <div class="a4-parties">
    <div><small><?= $challan ? 'Deliver to' : 'Bill to' ?></small><b><?= esc($o['customer_name'] ?: 'Walk-in Customer') ?></b><?php if (! empty($d['phone'])): ?><span>Phone: <?= esc($d['phone']) ?></span><?php endif ?><?php if (! empty($o['ship_address'])): ?><span><?= esc($o['ship_address']) ?></span><?php elseif (! empty($d['customer']['address'])): ?><span><?= esc($d['customer']['address']) ?></span><?php endif ?></div>
    <div><small>Details</small><?php if (! empty($o['order_type'])): ?><span>Type: <?= esc($o['order_type']) ?></span><?php endif ?><?php if (! empty($o['table_ref'])): ?><span><?= esc($labels['seat'] ?? 'Table') ?>: <?= esc($o['table_ref']) ?></span><?php endif ?><?php if (! empty($o['staff_name']) || ! empty($o['user_name'])): ?><span>Served by: <?= esc($o['staff_name'] ?: $o['user_name']) ?></span><?php endif ?><?php if ($o['channel'] !== 'pos'): ?><span>Channel: <?= esc(ucfirst($o['channel'])) ?><?= ! empty($o['web_ref']) ? ' · ' . esc($o['web_ref']) : '' ?></span><?php endif ?><?php if (! empty($o['ship_status'])): ?><span>Delivery: <?= esc(ship_label($o['ship_status'])) ?></span><?php endif ?></div>
  </div>
  <table class="a4-items">
    <thead><tr>
      <?php if ($challan): ?><th>#</th><th>Description</th><th class="num">Quantity</th><th>Remarks</th>
      <?php else: ?><th>#</th><th><?= $svc ? 'Service' : 'Description' ?></th><th class="num"><?= $svc ? 'Qty / Hrs' : 'Qty' ?></th><th class="num"><?= $svc ? 'Rate' : 'Unit price' ?></th><th class="num">Discount</th><?php if ($vatOn): ?><th class="num"><?= esc($d['taxLabel']) ?></th><?php endif ?><th class="num">Amount</th><?php endif ?>
    </tr></thead>
    <tbody>
      <?php foreach ($d['lines'] as $i => $l): ?>
        <?php if ($challan): ?>
          <tr><td><?= $i + 1 ?></td><td><b><?= esc($l['name']) ?></b><?php if ($l['details']): ?><div class="a4-det"><?= implode(' · ', $l['details']) ?></div><?php endif ?></td><td class="num"><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></td><td></td></tr>
        <?php else: ?>
          <tr><td><?= $i + 1 ?></td><td><b><?= esc($l['name']) ?></b><?php if (! empty($l['sku']) && ! $svc): ?> <span class="a4-sku"><?= esc($l['sku']) ?></span><?php endif ?><?php if ($l['details']): ?><div class="a4-det"><?= implode(' · ', $l['details']) ?></div><?php endif ?></td>
            <td class="num"><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></td><td class="num"><?= $F($l['gross_unit']) ?></td><td class="num"><?= $l['disc_total'] > 0 ? $F($l['disc_total']) : '—' ?></td>
            <?php if ($vatOn): ?><td class="num a4-vatc"><?= esc($l['vat_cell']) ?></td><?php endif ?><td class="num"><?= $F($l['line_total']) ?></td></tr>
        <?php endif ?>
      <?php endforeach ?>
    </tbody>
  </table>
  <?php if ($challan): ?>
    <p class="a4-terms">Received the above goods in good condition.</p>
    <?= view('App\Modules\Invoices\Views\docs\_sign', ['d' => $d, 'receiver' => true]) ?>
  <?php else: ?>
    <div class="a4-bottom">
      <div class="a4-words">
        <?php if ($d['stamp']): ?><div class="a4-stamp <?= esc($d['stamp'][1], 'attr') ?>"><?= esc($d['stamp'][0]) ?></div><?php endif ?>
        <small>Amount in words</small><b><?= esc(amount_words((float) $o['total'], $d['code'])) ?></b>
        <?php if ($o['payments']): ?><div class="a4-pay"><small>Payments</small><?php foreach ($o['payments'] as $p): ?><div><?= esc(date_str($p['created_at'])) ?> · <?= esc(pay_label($p['method'], $d['code'])) ?><?= ! empty($p['reference']) ? ' (' . esc($p['reference']) . ')' : '' ?> — <b><?= $F($p['amount']) ?></b></div><?php endforeach ?></div><?php endif ?>
        <?php if (! empty($o['note'])): ?><div class="a4-pay"><small>Note</small><div><?= esc($o['note']) ?></div></div><?php endif ?>
        <?php if ($d['vatTable']): ?>
          <table class="a4-vat"><thead><tr><th><?= esc($d['taxLabel']) ?></th><th class="num">Taxable value</th><th class="num"><?= esc($d['taxLabel']) ?></th></tr></thead><tbody>
            <?php foreach ($d['vatTable'] as $x): ?><tr><td><?= esc($x['label']) ?></td><td class="num"><?= $F($x['net']) ?></td><td class="num"><?= $F($x['tax']) ?></td></tr><?php endforeach ?>
          </tbody></table>
        <?php endif ?>
        <?php if ($d['bank'] !== ''): ?><div class="a4-pay a4-bank"><small>Payment details</small><div><?= nl2br(esc($d['bank'])) ?></div></div><?php endif ?>
      </div>
      <table class="a4-tot">
        <tr><td>Subtotal</td><td class="num"><?= $F($o['subtotal']) ?></td></tr>
        <?php if (abs($extra) >= 0.01): ?><tr><td><?= ! empty($o['table_ref']) ? esc(($labels['seat'] ?? 'Room') . ' time') : 'Express' ?></td><td class="num"><?= $F($extra) ?></td></tr><?php endif ?>
        <?php if ((float) $o['discount_total'] > 0): ?><tr><td>Discount</td><td class="num">-<?= $F($o['discount_total']) ?></td></tr><?php endif ?>
        <?php if ((float) $o['packing'] > 0): ?><tr><td><?= esc($o['order_type'] ?: 'Order') ?> charge</td><td class="num"><?= $F($o['packing']) ?></td></tr><?php endif ?>
        <?php if ((float) $o['service_charge'] > 0): ?><tr><td>Service charge</td><td class="num"><?= $F($o['service_charge']) ?></td></tr><?php endif ?>
        <?php foreach ($d['taxRows'] as [$label, $v]): ?><tr><td><?= esc($label) ?></td><td class="num"><?= $F($v) ?></td></tr><?php endforeach ?>
        <?php if ((float) $o['shipping'] > 0): ?><tr><td>Delivery charge</td><td class="num"><?= $F($o['shipping']) ?></td></tr><?php endif ?>
        <?php if ((float) $o['round_off'] != 0): ?><tr><td>Round off</td><td class="num"><?= $F($o['round_off']) ?></td></tr><?php endif ?>
        <tr class="gt"><td><b>Grand total</b></td><td class="num"><b><?= $F($o['total']) ?></b></td></tr>
        <tr><td>Paid</td><td class="num"><?= $F($o['paid_total']) ?></td></tr>
        <?php if ($d['returned'] > 0): ?><tr><td>Returned</td><td class="num">-<?= $F($d['returned']) ?></td></tr><?php endif ?>
        <?php if ((float) $o['due_total'] > 0): ?><tr class="baldue"><td><b>Balance due</b></td><td class="num"><b><?= $F($o['due_total']) ?></b></td></tr><?php endif ?>
      </table>
    </div>
    <?php if (! empty($b['settings']['terms'])): ?><p class="a4-terms"><b>Terms:</b> <?= esc($b['settings']['terms']) ?></p><?php endif ?>
    <?= view('App\Modules\Invoices\Views\docs\_sign', ['d' => $d]) ?>
  <?php endif ?>
  <?php if ($d['footer'] !== ''): ?><p class="a4-thanks"><?= esc($d['footer']) ?></p><?php endif ?>
  <div class="a4-foot">Printed <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?> · Powered by <?= esc(brand_name()) ?></div>
</div>
