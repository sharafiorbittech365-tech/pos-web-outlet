<?php
/**
 * @var string               $table
 * @var array<string, mixed> $stats
 * @var string               $filter
 * @var bool                 $batches
 * @var array                $cur
 */
helper('ui');
$chips = [['all', 'All', $stats['tracked']], ['low', 'Low stock', $stats['low']], ['out', 'Out of stock', $stats['out']]];
if ($batches) {
    $chips[] = ['expiring', 'Expiring / expired', $stats['expiring']];
}
?>
<div class="page">
  <div class="page-h">
    <h2>Inventory</h2>
    <?php foreach ($chips as [$k, $l, $n]): ?>
      <a class="chip <?= $filter === $k ? 'on' : '' ?>" href="<?= esc(site_url('inventory') . ($k === 'all' ? '' : '?f=' . $k), 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) $n ?></span></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <?= view('App\Modules\Inventory\Views\_nav', ['on' => 'levels']) ?>
  </div>
  <?php if (empty(service('posSession')->business()['stock_enabled'])): ?>
    <div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Stock management is <b>OFF</b> for this outlet — sales do not reduce stock. Turn it on in <a class="link" href="<?= esc(site_url('settings'), 'attr') ?>">Setup</a>.</span></div>
  <?php endif ?>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'box', 'label' => 'Tracked items', 'value' => number_format($stats['tracked'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'wallet', 'label' => 'Stock value (cost)', 'value' => money($stats['value'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'chart', 'label' => 'Stock value (retail)', 'value' => money($stats['retail'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Need attention', 'value' => (string) ($stats['low'] + $stats['out'] + $stats['expiring']), 'color' => 'var(--danger)']) ?>
  </div>
  <div class="card"><?= $table ?></div>
</div>
