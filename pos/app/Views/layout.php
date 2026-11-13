<?php
/**
 * POS back-office layout — the design build's shell (.app > .sidebar + .main > .topbar + .view).
 * The till screen (Sales module) uses its own full-screen layout: App\Views\pos_layout.
 *
 * @var string      $title
 * @var string      $content
 * @var string|null $actions extra topbar HTML (already escaped)
 */
helper(['ui', 'pos']);
$s = service('posSession');
$b = $s->business();
$u = $s->user();
$access = $s->access();
$items = pos_nav_items();
$theme = pos_theme();
$primary = $theme['primary'];
$lastGroup = '';
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
  <?php if (is_file(FCPATH . 'assets/pos/pos.css')): ?><link rel="stylesheet" href="<?= esc(base_url('assets/pos/pos.css') . '?v=' . filemtime(FCPATH . 'assets/pos/pos.css'), 'attr') ?>"><?php endif ?>
  <link rel="stylesheet" href="<?= esc(base_url('assets/pos/compact.css') . '?v=' . filemtime(FCPATH . 'assets/pos/compact.css'), 'attr') ?>">
  <style>:root{<?= $theme['css'] ?>}</style>
  <?= view('App\Views\_app_mode') ?>
</head>
<body>
<div id="app" class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><div class="brand-mark">O</div><div class="brand-name">Omni<span>POS</span></div></div>
    <a class="biz-card" href="<?= esc(site_url('outlets'), 'attr') ?>" title="Switch outlet">
      <span class="biz-logo emo"><?= biz_logo($b) ?></span>
      <span class="grow"><b><?= esc($b['name'] ?? '') ?></b><small><?= esc($b['type_name'] ?? '') ?><?= ! empty($b['settings']['branch']) ? ' · ' . esc($b['settings']['branch']) : '' ?></small></span>
      <?= icon('swap', 'sm') ?>
    </a>
    <?php foreach ($items as $i): ?>
      <?php if ($i['group'] !== $lastGroup): $lastGroup = $i['group']; ?><div class="sb-label"><?= esc($i['group']) ?></div><?php endif ?>
      <a class="sb-link <?= nav_on($i['path']) ?>" href="<?= esc(site_url($i['path']), 'attr') ?>" title="<?= esc($i['label'], 'attr') ?>"><?= icon($i['icon']) ?><span><?= esc($i['label']) ?></span><?= nav_badge($i['path']) ?></a>
    <?php endforeach ?>
    <div class="sb-foot">
      <a class="sb-user" href="<?= esc(site_url('switch-user'), 'attr') ?>" title="Lock / switch user">
        <span class="av" style="--ac:<?= css_color($u['color'] ?? '#64748B') ?>"><?= esc(initials($u['name'] ?? '?')) ?></span>
        <span class="grow"><b><?= esc($u['name'] ?? '') ?></b><small><?= esc($u['role'] ?? '') ?></small></span>
        <span class="sb-lock"><?= icon('shield', 'sm') ?></span>
      </a>
    </div>
  </aside>
  <div class="main">
    <header class="m-appbar">
      <a class="m-biz" href="<?= esc(site_url('outlets'), 'attr') ?>" aria-label="Switch outlet">
        <span class="biz-logo emo"><?= biz_logo($b) ?></span>
        <span class="m-t"><b><?= esc($title) ?></b><small><?= esc($b['name'] ?? '') ?> <?= icon('chevron', 'sm') ?></small></span>
      </a>
      <button class="m-ibtn" type="button" data-ui="dark" aria-label="Dark mode"><?= icon('moon') ?></button>
      <a class="m-ibtn m-av" href="<?= esc(site_url('switch-user'), 'attr') ?>" aria-label="Lock / switch user"><span class="av sm" style="--ac:<?= css_color($u['color'] ?? '#64748B') ?>"><?= esc(initials($u['name'] ?? '?')) ?></span></a>
    </header>
    <header class="topbar">
      <button class="icon-btn menu-btn" type="button" data-ui="open-side" aria-label="Menu"><?= icon('menu') ?></button>
      <h1 class="tb-title"><?= esc($title) ?></h1>
      <div class="spacer"></div>
      <?php $navPaths = array_column($items, 'path'); ?>
      <?php if (in_array('sell', $navPaths, true) && ! str_starts_with(uri_string(), 'sell')): ?><a class="btn sm primary tb-quick" href="<?= esc(site_url('sell'), 'attr') ?>"><?= icon('plus', 'sm') ?><span>New sale</span></a><?php endif ?>
      <?php if (in_array('products', $navPaths, true) && $s->can('products.manage') && uri_string() !== 'products/new'): ?><a class="btn sm tb-quick" href="<?= esc(site_url('products/new'), 'attr') ?>"><?= icon('tag', 'sm') ?><span>Add product</span></a><?php endif ?>
      <?= $actions ?? '' ?>
      <div class="clock"><b><?= esc(date('h:i A')) ?></b><span><?= esc(date('d M Y')) ?></span></div>
      <button class="icon-btn" type="button" data-print-setup title="Printer setup" aria-label="Printer setup"><?= icon('print') ?></button>
      <button class="icon-btn" type="button" data-ui="dark" title="Toggle dark mode" aria-label="Dark mode"><?= icon('moon') ?></button>
      <form method="post" action="<?= esc(site_url('logout'), 'attr') ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" title="Sign out" aria-label="Sign out"><?= icon('logout') ?></button></form>
    </header>
    <?php if ($access->status === 'trial' || $access->status === 'past_due'): ?>
      <div class="sbanners"><div class="sbanner <?= $access->status === 'past_due' ? 'warn' : '' ?>"><?= icon($access->status === 'past_due' ? 'alert' : 'clock', 'sm') ?><span><?= $access->status === 'past_due' ? 'Your subscription payment is overdue.' : 'You are on a free trial.' ?></span><a class="btn sm" href="<?= esc(site_url('subscription'), 'attr') ?>">Subscription</a></div></div>
    <?php endif ?>
    <div class="view" id="view">
      <?= view('OmniPOS\Ui\Views\components\flash') ?>
      <?= $content ?>
    </div>
  </div>
  <div class="scrim" id="scrim" data-ui="close-side"></div>
  <?= view('App\Views\_mobile_nav') ?>
</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="<?= esc(asset_url('js/omnipos-ui.js'), 'attr') ?>" defer></script>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/image-editor.css') . '?v=' . filemtime(FCPATH . 'assets/pos/image-editor.css'), 'attr') ?>">
<script src="<?= esc(base_url('assets/pos/image-editor.js') . '?v=' . filemtime(FCPATH . 'assets/pos/image-editor.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/mobile.js') . '?v=' . filemtime(FCPATH . 'assets/pos/mobile.js'), 'attr') ?>" defer></script>
<script>window.OMNI_PRINTER_DEFAULT=<?= json_encode(\App\Modules\Orders\Services\PrintSettings::printer(service('posSession')->business()), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= esc(base_url('assets/pos/print.js') . '?v=' . filemtime(FCPATH . 'assets/pos/print.js'), 'attr') ?>" defer></script>
<script src="<?= esc(base_url('assets/pos/badges.js') . '?v=' . filemtime(FCPATH . 'assets/pos/badges.js'), 'attr') ?>" defer></script>
<?= $scripts ?? '' ?>
</body>
</html>
