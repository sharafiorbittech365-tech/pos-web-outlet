<?php
/**
 * PIN lock / switch user — the design build's loginHTML (profiles + PIN pad).
 *
 * @var string                     $title
 * @var array<string, mixed>       $business
 * @var list<array<string, mixed>> $staff
 * @var int                        $current
 * @var int                        $today
 * @var bool                       $demo
 */
helper(['ui', 'pos']);
$b = $business;
$pick = (int) (service('request')->getGet('u') ?? 0) ?: $current;
if (! in_array($pick, array_map(static fn ($u) => (int) $u['id'], $staff), true)) {
    $pick = (int) ($staff[0]['id'] ?? 0);
}
$primary = (string) ($b['settings']['theme']['primary'] ?? '#4F46E5');
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <?= csrf_meta() ?>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <title><?= esc($title) ?> · <?= esc($b['name'] ?? \OmniPOS\Ui\Brand::name()) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(asset_url('css/omnipos-server.css'), 'attr') ?>">
  <?php if (is_file(FCPATH . 'assets/pos/pos.css')): ?><link rel="stylesheet" href="<?= esc(base_url('assets/pos/pos.css') . '?v=' . filemtime(FCPATH . 'assets/pos/pos.css'), 'attr') ?>"><?php endif ?>
  <style>:root{--primary:<?= css_color($primary) ?>;--primary-ink:#fff}</style>
</head>
<body>
<div id="app" class="app login-shell">
  <div class="login">
    <aside class="login-hero">
      <div class="lh-top"><div class="brand"><div class="brand-mark">O</div><div class="brand-name">Omni<span>POS</span></div></div>
        <form method="post" action="<?= esc(site_url('logout'), 'attr') ?>" class="inline"><?= csrf_field() ?><button class="lh-switch" type="submit"><?= icon('logout', 'sm') ?>Sign out of workspace</button></form></div>
      <div class="lh-mid">
        <span class="lh-logo emo"><?= biz_logo($b, 'lg') ?></span>
        <h1><?= esc($b['name'] ?? '') ?></h1>
        <p><?= esc($b['type_name'] ?? '') ?><?= ! empty($b['settings']['branch']) ? ' · ' . esc($b['settings']['branch']) : '' ?></p>
      </div>
      <div class="lh-foot">
        <div class="lh-clock"><b id="clock-t"><?= esc(date('h:i A')) ?></b><span><?= esc(date('l, j F')) ?></span></div>
        <div class="lh-stats"><span><b><?= (int) $today ?></b> sales today</span><span><b><?= count($staff) ?></b> team members</span></div>
      </div>
    </aside>
    <section class="login-panel">
      <form class="lp-in" method="post" action="<?= esc(site_url('switch-user'), 'attr') ?>" id="pin-form" autocomplete="off">
        <?= csrf_field() ?>
        <h2>Who's working today?</h2>
        <p class="muted">Choose your profile, then enter your 4-digit PIN.</p>
        <?= view('OmniPOS\Ui\Views\components\flash') ?>
        <div class="login-users" role="radiogroup" aria-label="Profiles">
          <?php foreach ($staff as $u): $on = (int) $u['id'] === $pick; ?>
            <label class="lu <?= $on ? 'on' : '' ?>" data-pin-user>
              <input type="radio" name="user_id" value="<?= (int) $u['id'] ?>" <?= $on ? 'checked' : '' ?> class="sr-only">
              <span class="av lg" style="--ac:<?= css_color($u['color'] ?? '#64748B') ?>"><?= esc(initials($u['name'])) ?></span>
              <b><?= esc(strtok((string) $u['name'], ' ')) ?></b>
              <small><?= sym_icon($u['icon'] ?? null) ?> <?= esc($u['role']) ?><?= (int) $u['has_pin'] ? '' : '<br>No PIN set — sign in with email' ?></small>
            </label>
          <?php endforeach ?>
        </div>
        <div class="login-pin">
          <div class="lp-who" id="pin-who">Enter your PIN</div>
          <div class="pin-dots <?= session()->getFlashdata('error') ? 'shake' : '' ?>" id="pin-dots"><i></i><i></i><i></i><i></i></div>
          <input class="input pin-input" type="password" name="pin" id="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" required aria-label="PIN" autofocus>
          <div class="keypad pin-pad">
            <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', 'C', '0', 'del'] as $k): ?>
              <button type="button" data-pin-key="<?= esc($k, 'attr') ?>" aria-label="<?= $k === 'del' ? 'Delete' : ($k === 'C' ? 'Clear' : $k) ?>"><?= $k === 'del' ? icon('back', 'sm') : esc($k) ?></button>
            <?php endforeach ?>
          </div>
          <button class="btn primary full" type="submit"><?= icon('check', 'sm') ?>Unlock</button>
        </div>
        <?php if ($demo): ?>
          <details class="login-hint"><summary>Demo PINs</summary><div><?php foreach ($staff as $i => $u): ?><span><?= esc($u['role']) ?>: <b><?= str_repeat((string) ($i + 1), 4) ?></b></span><?php endforeach ?></div></details>
        <?php endif ?>
      </form>
    </section>
  </div>
</div>
<div id="toasts" class="toasts" aria-live="polite"></div>
<script src="<?= esc(asset_url('js/omnipos-ui.js'), 'attr') ?>" defer></script>
<script>
(function () {
  var f = document.getElementById('pin-form'), pin = document.getElementById('pin'), dots = document.querySelectorAll('#pin-dots i');
  function paint() { for (var i = 0; i < dots.length; i++) dots[i].className = i < pin.value.length ? 'on' : ''; }
  function who() { var r = f.querySelector('input[name=user_id]:checked'); var l = r && r.closest('label'); document.getElementById('pin-who').innerHTML = l ? 'Hi <b>' + l.querySelector('b').textContent + '</b>, enter your PIN' : 'Select a profile'; }
  f.addEventListener('change', function (e) { if (e.target.name === 'user_id') { f.querySelectorAll('[data-pin-user]').forEach(function (l) { l.classList.toggle('on', l.contains(e.target)); }); pin.value = ''; paint(); who(); pin.focus(); } });
  f.addEventListener('click', function (e) {
    var k = e.target.closest('[data-pin-key]'); if (!k) return;
    var v = k.dataset.pinKey;
    if (v === 'C') pin.value = ''; else if (v === 'del') pin.value = pin.value.slice(0, -1); else if (pin.value.length < 6) pin.value += v;
    paint();
    if (pin.value.length === 4) f.requestSubmit();
  });
  pin.addEventListener('input', function () { pin.value = pin.value.replace(/\D/g, ''); paint(); if (pin.value.length === 4) f.requestSubmit(); });
  setInterval(function () { var d = new Date(), h = d.getHours(), m = d.getMinutes(); document.getElementById('clock-t').textContent = ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + (h < 12 ? ' AM' : ' PM'); }, 15000);
  who(); paint();
})();
</script>
</body>
</html>
