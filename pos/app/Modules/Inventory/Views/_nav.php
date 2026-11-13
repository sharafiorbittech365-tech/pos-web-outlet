<?php
/** @var string $on levels | movements | expiry | serials */
helper('ui');
$s = service('posSession');
$tabs = [['levels', 'inventory', 'Stock levels', 'box'], ['movements', 'inventory/movements', 'Movements', 'refresh']];
if (! empty($s->feature('batches'))) {
    $tabs[] = ['expiry', 'inventory/expiry', 'Batch expiry', 'alert'];
}
if (! empty($s->feature('serials'))) {
    $tabs[] = ['serials', 'inventory/serials', 'IMEI / serial lookup', 'barcode'];
}
?>
<div class="seg">
  <?php foreach ($tabs as [$k, $url, $label, $ic]): ?>
    <a class="<?= $on === $k ? 'on' : '' ?>" href="<?= esc(site_url($url), 'attr') ?>"><?= icon($ic, 'sm') ?><?= esc($label) ?></a>
  <?php endforeach ?>
</div>
