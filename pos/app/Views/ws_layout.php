<?php
/**
 * Workspace sign-in / sign-up page (the design build's workspaceHTML: hero on the left, card on the right).
 *
 * @var string $title
 * @var string $content  card body (already escaped)
 * @var string $tab      signin | signup | ''
 */
helper('ui');
$catalog = service('catalog');
$plans = array_values($catalog->plans(true));
$prices = [];
foreach ($plans as $p) {
    $list = array_filter(array_map(static fn ($t) => $catalog->planPrice((int) $p['id'], (string) $t), array_keys($catalog->businessTypes())), static fn ($v) => $v !== null);
    $prices[(int) $p['id']] = $list ? min($list) : null;
}
$trialDays = (int) $catalog->setting('trialDays', 14);
$signupOn = (bool) $catalog->setting('signup', true);
$tab ??= '';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#4F46E5">
  <?= csrf_meta() ?>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <title><?= esc($title) ?> · <?= esc(brand_name()) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(asset_url('css/omnipos-server.css'), 'attr') ?>">
  <?php if (is_file(FCPATH . 'assets/pos/pos.css')): ?><link rel="stylesheet" href="<?= esc(base_url('assets/pos/pos.css') . '?v=' . filemtime(FCPATH . 'assets/pos/pos.css'), 'attr') ?>"><?php endif ?>
</head>
<body>
<div id="app" class="app ws-shell">
  <div class="ws">
    <aside class="ws-hero">
      <div class="brand"><div class="brand-mark">O</div><div class="brand-name">Omni<span>POS</span></div></div>
      <h1>One POS for every kind of business in Bangladesh.</h1>
      <p>Restaurants, super shops, pharmacies, mobile shops, wholesalers and more. Each account keeps its own outlets, staff, stock and sales.</p>
      <ul class="ws-points">
        <li><?= icon('shield', 'sm') ?>Your data stays in your workspace. Platform staff can't open it.</li>
        <li><?= icon('swap', 'sm') ?>Run several outlets from one account</li>
        <li><?= icon('mobile', 'sm') ?>Works on desktop, tablet and phone</li>
      </ul>
      <div class="ws-plans">
        <?php foreach ($plans as $p): ?>
          <div class="ws-plan" style="--pc:<?= css_color($p['color'] ?? '#7C3AED') ?>"><b><?= esc($p['name']) ?></b><?php if ($prices[(int) $p['id']] !== null): ?><span><small>from </small><?= esc(money($prices[(int) $p['id']], ['decimals' => 0])) ?><small>/mo</small></span><?php endif ?></div>
        <?php endforeach ?>
      </div>
    </aside>
    <main class="ws-main">
      <div class="ws-card">
        <?php if ($tab !== ''): ?>
          <div class="seg full">
            <a class="<?= $tab === 'signin' ? 'on' : '' ?>" href="<?= esc(site_url('login'), 'attr') ?>">Sign in</a>
            <?php if ($signupOn): ?><a class="<?= $tab === 'signup' ? 'on' : '' ?>" href="<?= esc(site_url('signup'), 'attr') ?>">Free trial</a><?php endif ?>
          </div>
        <?php endif ?>
        <?= view('OmniPOS\Ui\Views\components\flash') ?>
        <?= $content ?>
      </div>
      <p class="ws-foot">Need help? <?= esc((string) $catalog->setting('supportPhone', '')) ?> · <?= esc((string) $catalog->setting('supportEmail', '')) ?> · <?= esc((string) $trialDays) ?>-day free trial</p>
    </main>
  </div>
</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="<?= esc(asset_url('js/omnipos-ui.js'), 'attr') ?>" defer></script>
</body>
</html>
