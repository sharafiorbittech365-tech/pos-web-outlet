<?php
/**
 * <head> of the public table pages: design system + table-order styles, shop colour.
 *
 * @var string $title
 * @var string $primary
 */
helper('ui');
$v = static fn (string $f): string => is_file(FCPATH . $f) ? (string) filemtime(FCPATH . $f) : '1';
?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= esc(css_color($primary, '#4F46E5'), 'attr') ?>">
  <meta name="robots" content="noindex, nofollow">
  <?= csrf_meta() ?>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <title><?= esc($title) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(base_url('assets/pos/table-order.css') . '?v=' . $v('assets/pos/table-order.css'), 'attr') ?>">
  <script>try{if(matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark')}catch(e){}</script>
  <style>:root{--primary:<?= css_color($primary, '#4F46E5') ?>;--primary-ink:#fff}</style>
