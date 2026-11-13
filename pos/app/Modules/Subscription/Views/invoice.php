<?php
/**
 * @var array<string, mixed>  $i
 * @var array<string, mixed>  $acc
 * @var array<string, string> $platform
 * @var list<array>           $lines     platform_invoice_lines (plan, modules, features, prorated changes)
 */
helper('ui');
$tk = static fn (float|int|string|null $n): string => esc(money($n, ['symbol' => '৳', 'decimals' => 0]));
$pay = $i['payments'][0] ?? null;
?>
<div class="page">
  <div class="page-h"><div><h2>Invoice <?= esc($i['number']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('subscription'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Subscription</a></p></div>
    <div class="spacer"></div>
    <?php if ($i['status'] === 'open'): ?><a class="btn primary" href="<?= esc(site_url('subscription/pay?ids=' . $i['id']), 'attr') ?>">Pay</a><?php endif ?>
    <button class="btn" type="button" data-print><?= icon('print', 'sm') ?>Print</button></div>
  <div class="card report"><div class="a4 sub-a4"><div class="a4-in" style="padding:40px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;gap:16px;flex-wrap:wrap"><div><h1 style="margin:0;font-size:26px"><?= esc($platform['name']) ?></h1><small><?= esc($platform['email']) ?> · <?= esc($platform['phone']) ?></small></div>
      <div style="text-align:right"><h2 style="margin:0">INVOICE</h2><b><?= esc($i['number']) ?></b><br><small>Issued <?= esc(date_str($i['issued_at'])) ?> · Due <?= esc(date_str($i['due_at'])) ?></small></div></div>
    <p><b>Bill to</b><br><?= esc($acc['name']) ?><br><?= esc($acc['owner_name']) ?></p>
    <table class="tbl"><thead><tr><th>Description</th><th class="num">Amount</th></tr></thead><tbody>
      <?php if ($lines ?? []): ?>
        <tr><td colspan="2"><small class="muted"><?= esc($i['plan']) ?> plan · <?= esc((string) $i['billing_cycle']) ?> · <?= esc(date_str($i['period_from'])) ?> – <?= esc(date_str($i['period_to'])) ?><?= $i['note'] ? ' · ' . esc($i['note']) : '' ?></small></td></tr>
        <?php foreach ($lines as $l): ?>
          <tr><td><?= esc($l['label']) ?><?php if ($l['monthly'] !== null && $l['kind'] !== 'plan'): ?><br><small class="muted"><?= $tk(abs((float) $l['monthly'])) ?>/month<?= $l['period_from'] ? ' · ' . esc(date_str($l['period_from'])) . ' – ' . esc(date_str($l['period_to'])) : '' ?></small><?php endif ?></td><td class="num"><?= $tk($l['amount']) ?></td></tr>
        <?php endforeach ?>
        <tr><td>Subtotal</td><td class="num"><?= $tk($i['amount']) ?></td></tr>
      <?php else: ?>
      <tr><td><?= esc($i['plan']) ?> plan · <?= esc((string) $i['billing_cycle']) ?><br><small class="muted"><?= esc(date_str($i['period_from'])) ?> – <?= esc(date_str($i['period_to'])) ?><?= $i['note'] ? ' · ' . esc($i['note']) : '' ?></small></td><td class="num"><?= $tk($i['amount']) ?></td></tr>
      <?php endif ?>
      <?php if ((float) $i['vat'] > 0): ?><tr><td>VAT</td><td class="num"><?= $tk($i['vat']) ?></td></tr><?php endif ?>
      <tr class="hl"><td><b>Total</b></td><td class="num"><b><?= $tk($i['total']) ?></b></td></tr>
    </tbody></table>
    <p><b>Status:</b> <?= $i['status'] === 'paid' ? 'Paid' . ($pay ? ' via ' . esc($pay['method']) . ' on ' . esc(date_str($pay['paid_at'])) . ($pay['reference'] ? ' (ref ' . esc($pay['reference']) . ')' : '') : '') : ($i['status'] === 'void' ? 'Void' : 'Unpaid') ?></p>
  </div></div></div>
</div>
