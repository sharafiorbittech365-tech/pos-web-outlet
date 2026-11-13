<?php
/**
 * Register session tables (the design build's registerTables).
 *
 * @var array<string, mixed> $R RegisterService::summary()
 */
helper(['ui', 'pos']);
$s = service('posSession');
$cur = $s->currency();
$F = static fn ($n): string => esc(money($n, $cur));
$code = (string) $s->business()['currency_code'];
$tax = (string) $s->business()['tax_label'];
$row = static fn (string $l, string $v, string $cls = ''): string => '<tr class="' . $cls . '"><td>' . $l . '</td><td class="num">' . $v . '</td></tr>';
?>
<div class="card"><div class="card-h"><h3>Cash drawer summary</h3></div><table class="tbl"><tbody>
  <?= $row('Opening balance', $F($R['opening'])) ?>
  <?= $row('(+) Cash received (sales &amp; dues)', $F($R['cash_in'])) ?>
  <?= $row('(−) Cash refunds', $F($R['refund_cash'])) ?>
  <?= $row('(−) Cash expenses', $F($R['expenses'])) ?>
  <?= $row('<b>Expected cash in drawer</b>', '<b>' . $F($R['expected']) . '</b>', 'hl') ?>
</tbody></table></div>
<div class="card"><div class="card-h"><h3>Sales summary</h3></div><table class="tbl"><tbody>
  <?= $row('Gross sales (total invoiced)', $F($R['gross'])) ?>
  <?= $row('(−) Total refund', $F($R['refund_total'])) ?>
  <?= $row(esc($tax) . ' invoiced', $F($R['tax'])) ?>
  <?= $row('Invoices', esc((string) $R['invoices'])) ?>
  <?= $row('<b>Net sales</b>', '<b>' . $F($R['net']) . '</b>', 'hl') ?>
</tbody></table></div>
<div class="card"><div class="card-h"><h3>Payments collected</h3></div><table class="tbl"><thead><tr><th>Payment method</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($R['by_method'] as $m => $v): ?><?= $row(esc(pay_label($m, $code)) . ' <small class="muted">(' . (int) $v['n'] . ')</small>', $F($v['v'])) ?><?php endforeach ?>
  <?php if (! $R['by_method']): ?><tr><td colspan="2" class="muted">No payments yet</td></tr><?php endif ?>
  <?= $row('<b>Grand total collected</b>', '<b>' . $F($R['collected']) . '</b>', 'hl') ?>
</tbody></table></div>
<div class="card"><div class="card-h"><h3>Outstanding receivables</h3></div><table class="tbl"><tbody>
  <?= $row('Open &amp; due invoices (all time)', esc((string) $R['outstanding_n'])) ?>
  <?= $row('<b>Pending from customers</b>', '<b>' . $F($R['outstanding']) . '</b>', 'hl-warn') ?>
</tbody></table></div>
<div class="card" style="grid-column:1/-1"><div class="card-h"><h3>Details of products sold</h3><span class="muted"><?= count($R['products']) ?> products</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>#</th><th>SKU</th><th>Product</th><th class="num">Quantity</th><th class="num">Total amount</th></tr></thead><tbody>
  <?php foreach ($R['products'] as $i => $p): ?><tr><td><?= $i + 1 ?></td><td class="muted"><?= esc($p['sku'] ?: '—') ?></td><td><?= esc($p['name']) ?></td><td class="num"><?= esc(qty($p['qty'], $p['unit'] ?: null)) ?></td><td class="num"><?= $F($p['amount']) ?></td></tr><?php endforeach ?>
  <?php if (! $R['products']): ?><tr><td colspan="5" class="muted">Nothing sold since the register opened</td></tr><?php endif ?>
  <tr class="hl"><td colspan="4">Grand total</td><td class="num"><?= $F(array_sum(array_map(static fn ($p) => (float) $p['amount'], $R['products']))) ?></td></tr>
</tbody></table></div></div>
<div class="card"><div class="card-h"><h3>Types of service</h3></div><table class="tbl"><thead><tr><th>Type</th><th class="num">Orders</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($R['types'] as $t): ?><tr><td><?= esc($t['t']) ?></td><td class="num"><?= (int) $t['n'] ?></td><td class="num"><?= $F($t['v']) ?></td></tr><?php endforeach ?>
  <?php if (! $R['types']): ?><tr><td colspan="3" class="muted">—</td></tr><?php endif ?>
</tbody></table></div>
