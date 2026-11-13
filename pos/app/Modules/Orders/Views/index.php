<?php
/**
 * @var string               $table
 * @var array<string,string> $filters
 * @var array<string,int>    $counts
 */
use App\Modules\Orders\Services\OrderQueries;

helper('ui');
$link = static fn (array $set): string => site_url('orders') . '?' . http_build_query(array_filter(array_merge($filters, $set)));
?>
<div class="page">
  <div class="page-h">
    <h2>Orders &amp; invoices</h2>
    <a class="chip <?= $filters['status'] === '' ? 'on' : '' ?>" href="<?= esc($link(['status' => '']), 'attr') ?>">All <span class="n"><?= (int) ($counts['all'] ?? 0) ?></span></a>
    <?php foreach (OrderQueries::STATUSES as $k => $l): ?>
      <a class="chip <?= $filters['status'] === $k ? 'on' : '' ?>" href="<?= esc($link(['status' => $k]), 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <?php if (service('posSession')->can('pos.sell')): ?><a class="btn primary" href="<?= esc(site_url('sell'), 'attr') ?>"><?= icon('pos', 'sm') ?>New sale</a><?php endif ?>
  </div>
  <form class="filter-bar" method="get" action="<?= esc(site_url('orders'), 'attr') ?>">
    <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= esc($filters['status'], 'attr') ?>"><?php endif ?>
    <label class="muted">Channel <select class="select" name="channel" onchange="this.form.submit()"><option value="">All channels</option>
      <?php foreach (OrderQueries::CHANNELS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($filters['channel'], $k) ?>><?= esc($l) ?></option><?php endforeach ?>
    </select></label>
    <?php if (! empty($tables)): ?><label class="muted">Table <select class="select" name="table" onchange="this.form.submit()"><option value="">All tables</option>
      <?php foreach ($tables as $t): ?><option value="<?= esc($t, 'attr') ?>" <?= selected($filters['table'], $t) ?>><?= esc($t) ?></option><?php endforeach ?>
    </select></label><?php endif ?>
    <label class="muted">From <input class="input" type="date" name="from" value="<?= esc($filters['from'], 'attr') ?>"></label>
    <label class="muted">To <input class="input" type="date" name="to" value="<?= esc($filters['to'], 'attr') ?>"></label>
    <button class="btn sm" type="submit"><?= icon('search', 'sm') ?>Apply</button>
    <?php if (array_filter($filters)): ?><a class="link" href="<?= esc(site_url('orders'), 'attr') ?>">Clear filters</a><?php endif ?>
  </form>
  <section class="card"><?= $table ?></section>
</div>
