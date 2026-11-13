<?php
/**
 * Full-screen POS till shell (the design build's `.app.pos-mode` screen). The till itself is
 * public/assets/pos/pos.js; this layout only loads it with the outlet configuration.
 *
 * @var string               $title
 * @var string               $content
 * @var array<string, mixed> $config  passed to the till script as JSON
 */
helper(['ui', 'pos']);
$b = service('posSession')->business();
$theme = pos_theme();
$primary = $theme['primary'];
$v = static fn (string $f): string => is_file(FCPATH . $f) ? (string) filemtime(FCPATH . $f) : '1';
?><!doctype html>
<html lang="en" data-theme="light"<?= $theme['attrs'] ?> data-brand="<?= esc(brand_name(), 'attr') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="<?= esc($primary, 'attr') ?>">
  <meta name="robots" content="noindex">
  <?= csrf_meta() ?>
  <meta name="omni-base" content="<?= esc(site_url('/'), 'attr') ?>">
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <title><?= esc($title) ?> · <?= esc($b['name'] ?? \OmniPOS\Ui\Brand::name()) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(asset_url('css/omnipos-server.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(base_url('assets/pos/pos.css') . '?v=' . $v('assets/pos/pos.css'), 'attr') ?>">
  <style>:root{<?= $theme['css'] ?>}</style>
  <?= view('App\Views\_app_mode') ?>
</head>
<body>
<div id="app" class="app pos-mode"><?= $content ?></div>
<div id="modal" class="modal-root"></div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script type="application/json" id="pos-config"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= esc(asset_url('js/icons.js'), 'attr') ?>"></script>
<script src="<?= esc(asset_url('js/omnipos-ui.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/mobile.js') . '?v=' . $v('assets/pos/mobile.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/print.js') . '?v=' . $v('assets/pos/print.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/badges.js') . '?v=' . $v('assets/pos/badges.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/sound.js') . '?v=' . $v('assets/pos/sound.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/pos.js') . '?v=' . $v('assets/pos/pos.js'), 'attr') ?>" defer></script>
</body>
</html>
