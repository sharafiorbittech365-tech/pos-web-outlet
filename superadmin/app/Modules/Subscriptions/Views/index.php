<?php
/**
 * @var string               $table
 * @var array<string,string> $filters
 * @var list<array>          $plans
 * @var array<string, mixed> $stats
 */
helper('ui');
$stat = static fn (string $ic, string $l, string $v, string $sub = ''): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'sub' => $sub]);
$statuses = ['' => 'All statuses', 'active' => 'Active', 'trial' => 'Trial', 'past_due' => 'Past due', 'suspended' => 'Suspended', 'expired' => 'Trial ended', 'cancelled' => 'Cancelled'];
?>
<div class="page">
  <div class="stats">
    <?= $stat('chart', 'MRR', money($stats['mrr'], ['decimals' => 0]), 'ARR ' . money($stats['mrr'] * 12, ['decimals' => 0])) ?>
    <?= $stat('refresh', 'Renewals in 7 days', (string) $stats['renewals7'], $stats['autopay'] . ' on auto-pay') ?>
    <?= $stat('clock', 'Trials ending in 7 days', (string) $stats['trials7']) ?>
    <?= $stat('x', 'Cancelling at period end', (string) $stats['cancelling']) ?>
  </div>
  <div class="page-h">
    <form class="row-add" method="get" action="<?= esc(site_url('subscriptions'), 'attr') ?>">
      <select class="select" name="status" aria-label="Status" onchange="this.form.submit()" style="width:auto">
        <?php foreach ($statuses as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($filters['status'], $k) ?>><?= esc($l) ?></option><?php endforeach ?>
      </select>
      <select class="select" name="plan" aria-label="Plan" onchange="this.form.submit()" style="width:auto">
        <option value="">All plans</option>
        <?php foreach ($plans as $p): ?><option value="<?= esc($p['code'], 'attr') ?>" <?= selected($filters['plan'], $p['code']) ?>><?= esc($p['name']) ?></option><?php endforeach ?>
      </select>
      <select class="select" name="cycle" aria-label="Cycle" onchange="this.form.submit()" style="width:auto">
        <option value="">Monthly &amp; yearly</option><option value="monthly" <?= selected($filters['cycle'], 'monthly') ?>>Monthly</option><option value="yearly" <?= selected($filters['cycle'], 'yearly') ?>>Yearly</option>
      </select>
      <select class="select" name="due" aria-label="Renewals due" onchange="this.form.submit()" style="width:auto">
        <option value="">Any renewal date</option><option value="7" <?= selected($filters['due'], '7') ?>>Renews / ends in 7 days</option><option value="30" <?= selected($filters['due'], '30') ?>>Renews / ends in 30 days</option>
      </select>
      <noscript><button class="btn sm" type="submit">Filter</button></noscript>
    </form>
  </div>
  <section class="card"><?= $table ?></section>
  <p class="muted adm-note"><?= icon('info', 'sm') ?> MRR counts active and past-due subscriptions: plan price for the business type plus billed add-ons; yearly plans count one twelfth of the yearly price.</p>
</div>
