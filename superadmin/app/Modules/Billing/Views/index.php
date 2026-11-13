<?php
/**
 * @var string               $tab     invoices | payments
 * @var string               $status  invoice filter
 * @var string               $table
 * @var array<string, mixed> $totals
 * @var int                  $graceDays
 */
helper('ui');
$tk = static fn (float $n): string => money($n, ['decimals' => 0]);
$stat = static fn (string $ic, string $l, string $v, string $sub = ''): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'sub' => $sub]);
$filters = ['open' => 'Open', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void', 'all' => 'All'];
?>
<div class="page">
  <div class="stats">
    <?= $stat('cash', 'Collected this month', $tk($totals['paid_month'])) ?>
    <?= $stat('receipt', 'Outstanding', $tk($totals['total']), $totals['count'] . ' open invoice(s)') ?>
    <?= $stat('alert', 'Overdue invoices', (string) $totals['overdue']) ?>
    <?= $stat('clock', 'Grace period', $graceDays . ' days', 'then auto-suspend') ?>
  </div>
  <div class="page-h">
    <form class="seg" method="get" action="<?= esc(site_url('billing'), 'attr') ?>">
      <?php foreach ($filters as $k => $l): ?>
        <button class="<?= $tab === 'invoices' && $status === $k ? 'on' : '' ?>" type="submit" name="status" value="<?= esc($k, 'attr') ?>"><?= esc($l) ?></button>
      <?php endforeach ?>
    </form>
    <form class="seg" method="get" action="<?= esc(site_url('billing/payments'), 'attr') ?>"><button class="<?= $tab === 'payments' ? 'on' : '' ?>" type="submit"><?= icon('cash', 'sm') ?>Payments</button></form>
    <div class="spacer"></div>
    <?php if (service('adminSession')->can('billing.manage')): ?>
      <form method="post" action="<?= esc(site_url('billing/run'), 'attr') ?>" data-confirm="Run billing now? Renews due subscriptions, ends expired trials and suspends accounts past the grace period."><?= csrf_field() ?>
        <button class="btn primary" type="submit"><?= icon('refresh', 'sm') ?>Run billing now</button></form>
    <?php endif ?>
  </div>
  <p class="muted adm-note"><?= icon('info', 'sm') ?> Billing job: renews due subscriptions (auto-pay charges the saved method), ends expired trials, then suspends accounts past the grace period. It also runs nightly as <code>php spark omnipos:billing</code>.</p>
  <section class="card"><div class="card-h"><h3><?= $tab === 'payments' ? 'Payments' : 'Invoices' ?></h3></div>
    <?= $table ?>
  </section>
</div>
