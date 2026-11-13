<?php
/**
 * Invoices list.
 *
 * @var string                $table
 * @var array<string, string> $filters
 * @var array<string, int>    $counts
 * @var array<string, int>    $sources invoices per source
 * @var bool                  $mushakOn
 */
use App\Modules\Invoices\Services\InvoiceService;

helper('ui');
$s = service('posSession');
$link = static fn (array $set): string => site_url('invoices') . '?' . http_build_query(array_filter(array_merge($filters, $set)));
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/invoices.css'), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <h2>Invoices</h2>
    <a class="chip <?= $filters['state'] === '' ? 'on' : '' ?>" href="<?= esc($link(['state' => '']), 'attr') ?>">All <span class="n"><?= (int) ($counts['all'] ?? 0) ?></span></a>
    <?php foreach (InvoiceService::STATES as $k => $l): ?>
      <a class="chip <?= $filters['state'] === $k ? 'on' : '' ?>" href="<?= esc($link(['state' => $k]), 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <div class="seg" role="group" aria-label="Source">
      <a class="<?= $filters['src'] === '' ? 'on' : '' ?>" href="<?= esc($link(['src' => '']), 'attr') ?>">All sources</a>
      <?php foreach (\App\Modules\Invoices\Services\InvoiceSync::SOURCES as $k => [$l]): ?>
        <a class="<?= $filters['src'] === $k ? 'on' : '' ?>" href="<?= esc($link(['src' => $k]), 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) ($sources[$k] ?? 0) ?></span></a>
      <?php endforeach ?>
    </div>
    <div class="inv-acts">
      <?php if ($s->can('mushak.issue') && $mushakOn): ?><a class="btn" href="<?= esc(site_url('mushak'), 'attr') ?>"><?= icon('file-text', 'sm') ?>Mushak-6.3</a><?php endif ?>
      <?php if ($s->can('mushak.settings')): ?><a class="btn" href="<?= esc(site_url('mushak/settings'), 'attr') ?>"><?= icon('shield', 'sm') ?>VAT / Mushak settings</a><?php endif ?>
      <?php if ($s->can('invoices.settings')): ?><a class="btn" href="<?= esc(site_url('invoices/settings'), 'attr') ?>"><?= icon('setup', 'sm') ?>Invoice page</a><?php endif ?>
      <?php if ($s->can('invoices.manage')): ?><a class="btn primary" href="<?= esc(site_url('invoices/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>New invoice</a><?php endif ?>
    </div>
  </div>
  <form class="filter-bar" method="get" action="<?= esc(site_url('invoices'), 'attr') ?>">
    <?php if ($filters['state'] !== ''): ?><input type="hidden" name="state" value="<?= esc($filters['state'], 'attr') ?>"><?php endif ?>
    <?php if ($filters['src'] !== ''): ?><input type="hidden" name="src" value="<?= esc($filters['src'], 'attr') ?>"><?php endif ?>
    <?php if (! empty($tables)): ?><label class="muted">Table <select class="select" name="table" onchange="this.form.submit()"><option value="">All tables</option>
      <?php foreach ($tables as $t): ?><option value="<?= esc($t, 'attr') ?>" <?= selected($filters['table'], $t) ?>><?= esc($t) ?></option><?php endforeach ?>
    </select></label><?php endif ?>
    <label class="muted">From <input class="input" type="date" name="from" value="<?= esc($filters['from'], 'attr') ?>"></label>
    <label class="muted">To <input class="input" type="date" name="to" value="<?= esc($filters['to'], 'attr') ?>"></label>
    <button class="btn sm" type="submit"><?= icon('search', 'sm') ?>Apply</button>
    <?php if (array_filter($filters)): ?><a class="link" href="<?= esc(site_url('invoices'), 'attr') ?>">Clear filters</a><?php endif ?>
  </form>
  <section class="card"><?= $table ?></section>
</div>
