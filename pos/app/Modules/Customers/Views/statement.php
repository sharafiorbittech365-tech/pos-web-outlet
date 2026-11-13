<?php
/**
 * Printable customer ledger statement (the design build's ledgerHTML).
 *
 * @var array<string, mixed> $c
 * @var array<string, mixed> $b
 * @var array<string, mixed> $st  CustomerService::statement()
 * @var array<string, mixed> $cur
 * @var string               $period
 */
helper(['ui', 'pos']);
$F = static fn ($n): string => esc(money($n, $cur));
$code = (string) ($b['currency_code'] ?? 'BDT');
$label = static fn (array $r): string => match ($r['entry_type']) {
    'sale' => 'Credit sale' . ($r['doc_no'] ? ' · ' . $r['doc_no'] : ''),
    'payment' => 'Payment' . ($r['method'] ? ' · ' . pay_label($r['method'], $code) : '') . ($r['doc_no'] ? ' · ' . $r['doc_no'] : ''),
    'opening' => 'Previous due',
    'return' => 'Return' . ($r['doc_no'] ? ' · ' . $r['doc_no'] : ''),
    default => 'Adjustment',
};
?>
<div class="a4">
  <div class="a4-head"><div class="a4-brand"><div class="a4-logo emo"><?= biz_logo($b, 'lg') ?></div><div><h1><?= esc($b['name']) ?></h1><p><?= esc($b['address'] ?: ($b['settings']['branch'] ?? '')) ?><?php if (! empty($b['phone'])): ?><br>Phone: <?= esc($b['phone']) ?><?php endif ?></p></div></div>
    <div class="a4-title"><h2>CUSTOMER LEDGER</h2><table>
      <tr><td>Customer</td><td><b><?= esc($c['name']) ?></b></td></tr>
      <?php if ($c['phone']): ?><tr><td>Phone</td><td><?= esc($c['phone']) ?></td></tr><?php endif ?>
      <tr><td>Period</td><td><?= $st['from'] ? esc(date_str($st['from']) . ' – ' . date_str(date('Y-m-d'))) : 'All time' ?></td></tr>
      <tr><td>As of</td><td><?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></td></tr>
      <?php if ((float) $c['credit_limit'] > 0): ?><tr><td>Credit limit</td><td><?= $F($c['credit_limit']) ?></td></tr><?php endif ?>
    </table></div></div>
  <table class="a4-items"><thead><tr><th>Date</th><th>Particulars</th><th class="num">Debit (due)</th><th class="num">Credit (paid)</th><th class="num">Balance</th></tr></thead><tbody>
    <?php if ($st['from']): ?><tr class="lg-bf"><td><?= esc(date_str($st['from'])) ?></td><td><b>Balance brought forward</b> <small>(previous due)</small></td><td></td><td></td><td class="num"><b><?= $F($st['opening']) ?></b></td></tr><?php endif ?>
    <?php foreach ($st['rows'] as $r): ?>
      <tr><td><?= esc(date_str($r['created_at'])) ?></td><td><?= esc($label($r)) ?><?= $r['note'] ? '<br><small>' . esc($r['note']) . '</small>' : '' ?></td><td class="num"><?= (float) $r['amount'] > 0 ? $F($r['amount']) : '' ?></td><td class="num"><?= (float) $r['amount'] < 0 ? $F(-(float) $r['amount']) : '' ?></td><td class="num"><b><?= $F($r['balance_after']) ?></b></td></tr>
    <?php endforeach ?>
    <?php if (! $st['rows']): ?><tr><td colspan="5">No transactions in this period</td></tr><?php endif ?>
    <tr class="a4-gt"><td></td><td><b>Total for the period</b></td><td class="num"><b><?= $F($st['debit']) ?></b></td><td class="num"><b><?= $F($st['credit']) ?></b></td><td class="num"><b><?= $F($st['closing']) ?></b></td></tr>
  </tbody></table>
  <p class="a4-terms">Closing balance due: <b><?= $F(max(0, $st['closing'])) ?></b> (<?= esc(amount_words(max(0, (float) $st['closing']), $code)) ?>)</p>
  <div class="a4-sign one"><span>Authorized signature</span></div>
</div>
