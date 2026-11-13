<?php
/**
 * Kitchen ticket (KOT) for the kitchen / bar / production printer.
 *
 * @var array<string, mixed> $d OrderDocs::build()
 */
helper(['ui', 'pos']);
$o = $d['order'];
$b = $d['business'];
$labels = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));
?>
<div class="receipt w80 kot">
  <div class="c"><div class="big"><?= esc(strtoupper((string) ($labels['kitchen'] ?? 'Kitchen'))) ?> TICKET</div><div><?= esc($b['name']) ?></div></div>
  <hr>
  <div class="r tot"><span>Token</span><span><?= (int) $o['number'] % 99 + 1 ?></span></div>
  <div class="r"><span>Order</span><span><?= esc($o['doc_no']) ?></span></div>
  <div class="r"><span>Time</span><span><?= esc(datetime_str($o['created_at'])) ?></span></div>
  <?php if (! empty($o['order_type'])): ?><div class="r"><span>Type</span><span><?= esc($o['order_type']) ?></span></div><?php endif ?>
  <?php if (! empty($o['table_ref'])): ?><div class="r"><span><?= esc($labels['seat'] ?? 'Table') ?></span><span><?= esc($o['table_ref']) ?></span></div><?php endif ?>
  <div class="r"><span>Customer</span><span><?= esc($o['customer_name']) ?></span></div>
  <?php if (! empty($o['staff_name'])): ?><div class="r"><span>Staff</span><span><?= esc($o['staff_name']) ?></span></div><?php endif ?>
  <hr>
  <?php foreach ($d['lines'] as $l): ?>
    <div class="r" style="font-size:15px"><span><b><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?> × <?= esc($l['name']) ?></b><?php if (! empty($l['variant_name'])): ?><br><small><?= esc($l['variant_name']) ?></small><?php endif ?><?php if (! empty($l['note'])): ?><br><small>Note: <?= esc($l['note']) ?></small><?php endif ?></span><span><?= (int) $l['kds_done'] ? 'done' : '' ?></span></div>
  <?php endforeach ?>
  <?php if (! empty($o['note'])): ?><hr><div><b>Note:</b> <?= esc($o['note']) ?></div><?php endif ?>
  <hr><div class="c"><small>Printed <?= esc(date('h:i A')) ?></small></div>
</div>
