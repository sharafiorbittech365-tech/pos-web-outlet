<?php
/**
 * POS receipt (58 / 80 mm) — the design build's receiptHTML.
 *
 * @var array<string, mixed> $d     OrderDocs::build()
 * @var string               $mode  invoice | challan
 * @var int                  $width 58 | 80
 */
helper(['ui', 'pos']);
$o = $d['order'];
$b = $d['business'];
$F = static fn ($n): string => esc(money($n, $d['cur']));
$challan = $mode === 'challan';
$labels = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));
$extra = round((float) $o['total'] - (float) $o['subtotal'] + (float) $o['discount_total'] - (float) $o['service_charge'] - (float) $o['packing'] - (float) $o['tax_added'] - (float) $o['shipping'] - (float) $o['round_off'], 2);
$title = $challan ? 'DELIVERY CHALLAN' : ($d['service'] ? 'SERVICE INVOICE' : 'SALES RECEIPT');
?>
<div class="receipt w<?= (int) $width ?>">
  <div class="c">
    <?php if (($d['showLogo'] ?? true) && ! empty($d['logoUrl'])): ?><img class="rc-logo" src="<?= esc((string) $d['logoUrl'], 'attr') ?>" alt="">
    <?php elseif ($d['showLogo'] ?? true): ?><div style="font-size:26px"><?= sym_icon($d['logo'], 'lg') ?></div><?php endif ?>
    <div class="big"><?= esc($b['name']) ?></div>
    <?php if (! empty($b['address']) || ! empty($b['settings']['branch'])): ?><div><?= esc($b['address'] ?: $b['settings']['branch']) ?></div><?php endif ?>
    <?php if (! empty($b['phone'])): ?><div>Phone: <?= esc($b['phone']) ?></div><?php endif ?>
    <?php if (! empty($b['settings']['bin'])): ?><div>BIN: <?= esc($b['settings']['bin']) ?></div><?php endif ?>
  </div>
  <hr>
  <div class="c rc-title"><?= esc($title) ?></div>
  <?php if (! empty($d['invoiceNo'])): ?><div class="r"><span>Invoice</span><span><?= esc($d['invoiceNo']) ?></span></div><?php endif ?>
  <div class="r"><span><?= ! empty($d['invoiceNo']) ? 'Sale no.' : 'No.' ?></span><span><?= esc($o['doc_no']) ?></span></div>
  <div class="r"><span>Date</span><span><?= esc(datetime_str($o['created_at'])) ?></span></div>
  <?php if (! empty($o['order_type'])): ?><div class="r"><span>Type</span><span><?= esc($o['order_type']) ?></span></div><?php endif ?>
  <?php if (! empty($o['table_ref'])): ?><div class="r"><span><?= esc($labels['seat'] ?? 'Table') ?></span><span><?= esc($o['table_ref']) ?></span></div><?php endif ?>
  <div class="r"><span>Customer</span><span><?= esc($o['customer_name']) ?></span></div>
  <?php if (! empty($d['phone'])): ?><div class="r"><span>Phone</span><span><?= esc($d['phone']) ?></span></div><?php endif ?>
  <?php if (! empty($o['staff_name']) || ! empty($o['user_name'])): ?><div class="r"><span>Served by</span><span><?= esc($o['staff_name'] ?: $o['user_name']) ?></span></div><?php endif ?>
  <?php if ($o['kitchen_status'] !== 'none'): ?><div class="r"><span>Token</span><span><?= (int) $o['number'] % 99 + 1 ?></span></div><?php endif ?>
  <?php if (! empty($o['ship_address'])): ?><div class="r"><span>Deliver to</span><span><?= esc($o['ship_address']) ?></span></div><?php endif ?>
  <hr>
  <?php foreach ($d['lines'] as $l): ?>
    <?php if ($challan): ?>
      <div class="r"><span><?= esc($l['name']) ?><?php foreach ($l['details'] as $x): ?><br><small><?= $x ?></small><?php endforeach ?></span><span><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></span></div>
    <?php else: ?>
      <div class="r"><span><?= esc($l['name']) ?><br><small><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?> × <?= $F($l['net_unit']) ?></small><?php foreach ($l['details'] as $x): ?><br><small><?= $x ?></small><?php endforeach ?></span><span><?= $F($l['line_total']) ?></span></div>
    <?php endif ?>
  <?php endforeach ?>
  <?php if ($challan): ?>
    <hr><div class="c"><br>______________________<br><small>Received by</small></div>
  <?php else: ?>
    <hr>
    <div class="r"><span>Subtotal</span><span><?= $F($o['subtotal']) ?></span></div>
    <?php if (abs($extra) >= 0.01): ?><div class="r"><span><?= ! empty($o['table_ref']) ? esc(($labels['seat'] ?? 'Room') . ' time') : 'Express' ?></span><span><?= $F($extra) ?></span></div><?php endif ?>
    <?php if ((float) $o['discount_total'] > 0): ?><div class="r"><span>Discount</span><span>-<?= $F($o['discount_total']) ?></span></div><?php endif ?>
    <?php if ((float) $o['packing'] > 0): ?><div class="r"><span><?= esc($o['order_type'] ?: 'Order') ?> charge</span><span><?= $F($o['packing']) ?></span></div><?php endif ?>
    <?php if ((float) $o['service_charge'] > 0): ?><div class="r"><span>Service charge</span><span><?= $F($o['service_charge']) ?></span></div><?php endif ?>
    <?php foreach ($d['taxRows'] as [$label, $v]): ?><div class="r"><span><?= esc($label) ?></span><span><?= $F($v) ?></span></div><?php endforeach ?>
    <?php if ((float) $o['shipping'] > 0): ?><div class="r"><span>Delivery charge</span><span><?= $F($o['shipping']) ?></span></div><?php endif ?>
    <?php if ((float) $o['round_off'] != 0): ?><div class="r"><span>Round off</span><span><?= $F($o['round_off']) ?></span></div><?php endif ?>
    <div class="r tot"><span>TOTAL</span><span><?= $F($o['total']) ?></span></div>
    <?php if ($o['payments']): ?><hr><?php endif ?>
    <?php foreach ($o['payments'] as $p): ?>
      <div class="r"><span><?= esc(pay_label($p['method'], $d['code'])) ?><?= ! empty($p['reference']) ? ' (' . esc($p['reference']) . ')' : '' ?></span><span><?= $F($p['amount']) ?></span></div>
    <?php endforeach ?>
    <?php if (! empty($tendered) && (float) $tendered > 0): ?>
      <div class="r"><span>Tendered</span><span><?= $F($tendered) ?></span></div>
      <div class="r"><span>Change</span><span><?= $F($change ?? 0) ?></span></div>
    <?php endif ?>
    <?php if ($d['returned'] > 0): ?><div class="r"><span>Returned</span><span>-<?= $F($d['returned']) ?></span></div><?php endif ?>
    <?php if ((float) $o['due_total'] > 0): ?><div class="r tot"><span>BALANCE DUE</span><span><?= $F($o['due_total']) ?></span></div><?php endif ?>
  <?php endif ?>
  <?php if (! empty($o['note'])): ?><hr><div><small>Note: <?= esc($o['note']) ?></small></div><?php endif ?>
  <hr>
  <div class="c"><?php if ($d['stamp'] && ! $challan): ?><span class="stamp <?= esc($d['stamp'][1], 'attr') ?>"><?= esc($d['stamp'][0]) ?></span><br><br><?php endif ?><?php if (! empty($b['settings']['terms'])): ?><small><?= esc($b['settings']['terms']) ?></small><br><br><?php endif ?><?= esc($d['footer']) ?></div>
</div>
