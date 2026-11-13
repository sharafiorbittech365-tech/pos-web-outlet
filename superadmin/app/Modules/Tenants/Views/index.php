<?php
/**
 * @var string               $table
 * @var array<string, int>   $counts
 * @var array<string,string> $filters
 * @var list<array>          $plans
 * @var list<array>          $servers
 */
helper('ui');
$labels = ['all' => 'All', 'active' => 'Active', 'trial' => 'Trial', 'past_due' => 'Past due', 'suspended' => 'Suspended', 'expired' => 'Trial ended', 'cancelled' => 'Cancelled'];
$link = static fn (array $set): string => site_url('tenants') . '?' . http_build_query(array_filter(array_merge($filters, $set)));
?>
<div class="page">
  <div class="page-h">
    <form class="row-add" method="get" action="<?= esc(site_url('tenants'), 'attr') ?>">
      <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= esc($filters['status'], 'attr') ?>"><?php endif ?>
      <select class="select" name="plan" aria-label="Plan" onchange="this.form.submit()" style="width:auto">
        <option value="">All plans</option>
        <?php foreach ($plans as $p): ?><option value="<?= esc($p['code'], 'attr') ?>" <?= selected($filters['plan'], $p['code']) ?>><?= esc($p['name']) ?></option><?php endforeach ?>
      </select>
      <select class="select" name="server" aria-label="POS server" onchange="this.form.submit()" style="width:auto">
        <option value="">All POS servers</option>
        <?php foreach ($servers as $s): ?><option value="<?= esc($s['code'], 'attr') ?>" <?= selected($filters['server'], $s['code']) ?>><?= esc($s['name']) ?></option><?php endforeach ?>
      </select>
      <noscript><button class="btn sm" type="submit">Filter</button></noscript>
    </form>
    <div class="spacer"></div>
    <?php if (service('adminSession')->can('tenants.create')): ?>
      <a class="btn primary" href="<?= esc(site_url('tenants/new'), 'attr') ?>"><?= icon('userplus', 'sm') ?>New tenant</a>
    <?php endif ?>
  </div>
  <div class="cat-chips">
    <?php foreach ($labels as $k => $l): ?>
      <a class="chip sm <?= ($filters['status'] ?: 'all') === $k ? 'on' : '' ?>" href="<?= esc($link(['status' => $k === 'all' ? '' : $k]), 'attr') ?>"><?= esc($l) ?><span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach ?>
  </div>
  <section class="card">
    <?= $table ?>
  </section>
  <p class="muted adm-note"><?= icon('shield', 'sm') ?> Usage figures are counts reported by each workspace. Staff cannot see the records behind them.</p>
</div>
