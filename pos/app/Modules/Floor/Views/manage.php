<?php
/**
 * Tables › Manage: add, edit and remove tables / rooms, and their QR codes (Table QR ordering).
 *
 * @var array<string, string>      $labels
 * @var list<array<string, mixed>> $tables
 * @var bool                       $qrOn     Table QR ordering is on for this account
 * @var ?array{base: string, query: string} $siteBase where the QR codes lead (null: no published storefront)
 * @var bool                       $hourly
 */
helper('ui');
$seat = (string) ($labels['seat'] ?? 'Table');
$floor = (string) ($labels['floor'] ?? 'Tables');
$field = static fn (string $name, string $label, string $value, string $extra = ''): string => '<label class="field">' . esc($label) . '<input class="input" name="' . $name . '" value="' . esc($value, 'attr') . '" ' . $extra . '></label>';
?>
<div class="page">
  <div class="page-h">
    <div><h2>Manage <?= esc(strtolower($floor)) ?></h2><p class="page-sub">Add, rename and remove <?= esc(strtolower($floor)) ?>. Orders refer to a <?= esc(strtolower($seat)) ?> by its name, so every name is different.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('floor'), 'attr') ?>"><?= icon('back', 'sm') ?><?= esc($floor) ?></a>
    <?php if ($qrOn && $siteBase !== null && $tables): ?><a class="btn primary" href="<?= esc(site_url('floor/qr'), 'attr') ?>"><?= icon('qr', 'sm') ?>Print all QR codes</a><?php endif ?>
  </div>

  <?php if ($qrOn && $siteBase === null): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>Table QR codes open your online menu — publish your storefront first (Website), then come back to print them.</span><a class="btn sm" href="<?= esc(site_url('website'), 'attr') ?>">Website</a></div>
  <?php elseif (! $qrOn): ?>
    <div class="sbanner"><?= icon('qr', 'sm') ?><span>Add <b>Table QR ordering</b> to let guests scan a code on the table and order from their phone — straight to the kitchen.</span><a class="btn sm" href="<?= esc(site_url('subscription'), 'attr') ?>">Subscription</a></div>
  <?php endif ?>

  <section class="card">
    <div class="card-h"><h3>Add a <?= esc(strtolower($seat)) ?></h3></div>
    <form class="card-b tbl-add" method="post" action="<?= esc(site_url('floor/tables'), 'attr') ?>"><?= csrf_field() ?>
      <?= $field('name', 'Name *', (string) (old('name') ?? ''), 'required maxlength="40" placeholder="e.g. Table 12"') ?>
      <?= $field('area', 'Area', (string) (old('area') ?? ''), 'maxlength="40" placeholder="e.g. Rooftop"') ?>
      <?= $field('seats', 'Seats', (string) (old('seats') ?? '4'), 'type="number" min="1" max="100" required') ?>
      <?php if ($hourly): ?><?= $field('hourly_rate', 'Rate per hour', (string) (old('hourly_rate') ?? ''), 'type="number" min="0" step="any"') ?><?php endif ?>
      <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add</button>
    </form>
  </section>

  <section class="card">
    <div class="card-h"><h3><?= esc($floor) ?></h3><span class="muted"><?= count($tables) ?></span></div>
    <?php if (! $tables): ?><div class="empty"><span class="empty-ic"><?= icon('floor') ?></span><b>No <?= esc(strtolower($floor)) ?> yet</b><span>Add the first one above.</span></div><?php endif ?>
    <div class="tbl-rows">
      <?php foreach ($tables as $t): $id = (int) $t['id']; ?>
        <div class="tbl-row" id="t<?= $id ?>">
          <form class="tbl-edit" method="post" action="<?= esc(site_url('floor/tables/' . $id), 'attr') ?>"><?= csrf_field() ?>
            <?= $field('name', 'Name', (string) $t['name'], 'required maxlength="40"') ?>
            <?= $field('area', 'Area', (string) ($t['area'] ?? ''), 'maxlength="40"') ?>
            <?= $field('seats', 'Seats', (string) $t['seats'], 'type="number" min="1" max="100" required') ?>
            <?php if ($hourly): ?><?= $field('hourly_rate', 'Rate / h', $t['hourly_rate'] !== null ? (string) (float) $t['hourly_rate'] : '', 'type="number" min="0" step="any"') ?><?php endif ?>
            <button class="btn sm" type="submit"><?= icon('check', 'sm') ?>Save</button>
          </form>
          <div class="tbl-acts">
            <?php if ($qrOn && $siteBase !== null): ?>
              <a class="btn sm" href="<?= esc(site_url('floor/qr') . '?ids[]=' . $id, 'attr') ?>"><?= icon('qr', 'sm') ?>Print QR</a>
              <form class="inline" method="post" action="<?= esc(site_url('floor/tables/' . $id . '/qr'), 'attr') ?>" data-confirm="Make a new QR code for <?= esc($t['name'], 'attr') ?>? The printed QR on the table stops working."><?= csrf_field() ?><button class="btn sm" type="submit" title="Retire the printed QR code"><?= icon('refresh', 'sm') ?>New QR</button></form>
            <?php endif ?>
            <form class="inline" method="post" action="<?= esc(site_url('floor/tables/' . $id . '/delete'), 'attr') ?>" data-confirm="Remove <?= esc($t['name'], 'attr') ?>?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Remove</button></form>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  </section>
</div>
