<?php
/**
 * Super Admin > Locations > a city / district: its areas / thanas (enable / disable).
 *
 * @var array<string, mixed> $c     city row + country_name, area_label
 * @var string               $table
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><div><h2><?= esc($c['name']) ?> <?= tag_html(ucfirst((string) $c['kind'])) ?> <?= (int) $c['is_active'] ? tag_html('Enabled', 'ok') : tag_html('Disabled', 'warn') ?></h2>
    <p class="page-sub"><?= esc($c['country_name']) ?><?= $c['region'] !== '' ? ' · ' . esc($c['region']) : '' ?><?= $c['code'] ? ' · code ' . esc($c['code']) : '' ?></p></div><div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('locations/' . $c['country_code']), 'attr') ?>"><?= icon('back', 'sm') ?><?= esc($c['country_name']) ?></a></div>
  <section class="card"><?= $table ?></section>
</div>
