<?php
/**
 * @var string $table
 * @var int    $days
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <h2>Batch expiry</h2>
    <?php foreach ([30 => 'Next 30 days', 60 => '60 days', 90 => '90 days', 180 => '6 months', 0 => 'All batches'] as $d => $l): ?>
      <a class="chip <?= $days === $d ? 'on' : '' ?>" href="<?= esc(site_url('inventory/expiry?days=' . $d), 'attr') ?>"><?= esc($l) ?></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <?= view('App\Modules\Inventory\Views\_nav', ['on' => 'expiry']) ?>
  </div>
  <p class="muted bo-note">Expired batches are listed too (they are never sold) — write them off from the product's stock page.</p>
  <div class="card"><?= $table ?></div>
</div>
