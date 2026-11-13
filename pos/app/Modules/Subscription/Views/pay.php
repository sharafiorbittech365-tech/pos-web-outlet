<?php
/**
 * @var list<array>          $invoices
 * @var array<string, mixed> $acc
 * @var list<string>         $methods
 */
helper('ui');
$tk = static fn (float|int|string|null $n): string => esc(money($n, ['symbol' => '৳', 'decimals' => 0]));
$total = array_sum(array_map(static fn (array $i): float => (float) $i['total'], $invoices));
?>
<div class="page">
  <div class="page-h"><div><h2>Pay subscription</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('subscription'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Subscription</a></p></div></div>
  <?php if (! $invoices): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('check') ?></span><b>Nothing to pay</b><span>All invoices are paid.</span></div></div>
  <?php else: ?>
  <form class="card" method="post" action="<?= esc(site_url('subscription/pay'), 'attr') ?>" style="max-width:620px">
    <?= csrf_field() ?>
    <div class="card-b">
      <div class="lk-inv">
        <?php foreach ($invoices as $i): ?>
          <input type="hidden" name="ids[]" value="<?= (int) $i['id'] ?>">
          <div><span><?= esc($i['number']) ?> · <?= esc((string) ($i['note'] ?: ucfirst((string) $i['billing_cycle']))) ?> · <?= esc(date_str($i['period_from'])) ?> – <?= esc(date_str($i['period_to'])) ?></span><b><?= $tk($i['total']) ?></b></div>
        <?php endforeach ?>
        <div class="tot"><span>Total</span><b><?= $tk($total) ?></b></div>
      </div>
      <div class="pm-list"><?php foreach ($methods as $k => $m): $on = $acc['pay_method'] ? $acc['pay_method'] === $m : $k === 0; ?><label class="pm <?= $on ? 'on' : '' ?>" data-chip-check><input type="radio" name="method" value="<?= esc($m, 'attr') ?>" <?= checked($on) ?>><?= icon($m === 'Card' ? 'card' : 'mobile', 'sm') ?><?= esc($m) ?></label><?php endforeach ?></div>
      <?= field_error('method') ?>
      <label class="field">Transaction ID / last 4 digits<input class="input" name="reference" required minlength="4" maxlength="64" placeholder="e.g. 8N7A6D5C4B" autofocus><?= field_error('reference') ?></label>
      <label class="check"><input type="checkbox" name="save" value="1" checked>Save this method for renewals</label>
      <small class="muted">Demo: the payment is recorded with your reference — a live system confirms it with the bKash / Nagad / card gateway.</small>
      <div class="bo-actions"><a class="btn" href="<?= esc(site_url('subscription'), 'attr') ?>">Cancel</a><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Confirm payment</button></div>
    </div>
  </form>
  <?php endif ?>
</div>
