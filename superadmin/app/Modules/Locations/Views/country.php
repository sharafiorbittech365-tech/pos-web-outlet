<?php
/**
 * Super Admin > Locations > a country: its cities / districts (enable / disable; open for areas).
 *
 * @var array<string, mixed> $k     country row
 * @var string               $table
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><div><h2><?= esc($k['name']) ?> <span class="tag"><?= esc($k['code']) ?></span> <?= (int) $k['is_active'] ? tag_html('Enabled', 'ok') : tag_html('Disabled', 'warn') ?></h2>
    <p class="page-sub"><?= esc(($k['city_label'] ?: 'City') . ' › ' . ($k['area_label'] ?: 'Area')) ?><?= $k['phone_code'] ? ' · ' . esc($k['phone_code']) : '' ?></p></div><div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('locations'), 'attr') ?>"><?= icon('back', 'sm') ?>All countries</a></div>
  <section class="card"><?= $table ?></section>
</div>
