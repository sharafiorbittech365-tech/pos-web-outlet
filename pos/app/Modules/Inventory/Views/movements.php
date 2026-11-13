<?php
/**
 * @var string                                    $table
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var string                                    $reason
 * @var array<string, string>                     $reasons
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <h2>Stock movements</h2>
    <div class="spacer"></div>
    <?= view('App\Modules\Inventory\Views\_nav', ['on' => 'movements']) ?>
  </div>
  <div class="page-h">
    <?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => site_url('inventory/movements'), 'keep' => array_filter(['reason' => $reason])]) ?>
    <div class="spacer"></div>
    <form method="get" action="<?= esc(site_url('inventory/movements'), 'attr') ?>" class="inline-form">
      <?php foreach ($range->params() as $k => $val): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($val, 'attr') ?>"><?php endforeach ?>
      <select class="select" name="reason" data-auto-submit aria-label="Type"><option value="">All types</option><?php foreach ($reasons as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($reason, $k) ?>><?= esc($l) ?></option><?php endforeach ?></select>
    </form>
  </div>
  <div class="card"><?= $table ?></div>
</div>
