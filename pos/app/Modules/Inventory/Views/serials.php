<?php
/**
 * @var string               $table
 * @var string               $status
 * @var array<string, array> $labels
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <h2>IMEI / serial lookup</h2>
    <a class="chip <?= $status === '' ? 'on' : '' ?>" href="<?= esc(site_url('inventory/serials'), 'attr') ?>">All</a>
    <?php foreach ($labels as $k => [$l]): ?>
      <a class="chip <?= $status === $k ? 'on' : '' ?>" href="<?= esc(site_url('inventory/serials?status=' . $k), 'attr') ?>"><?= esc($l) ?></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <?= view('App\Modules\Inventory\Views\_nav', ['on' => 'serials']) ?>
  </div>
  <div class="card"><?= $table ?></div>
</div>
