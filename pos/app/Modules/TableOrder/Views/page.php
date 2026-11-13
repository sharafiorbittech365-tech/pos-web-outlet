<?php
/**
 * Public table-ordering page: the menu in sections (like the till's tiles), a cart sheet and the confirmation.
 * assets/pos/table-order.js draws it from the JSON below and /menu.
 *
 * @var string                $title
 * @var array<string, mixed>  $info    shop, table, area, currency
 * @var string                $base    this table's page address (…/order/table-5/<code>)
 * @var string                $primary shop colour
 * @var string                $logo    website logo address or ''
 */
helper('ui');
$cfg = ['info' => $info, 'base' => $base, 'demoImg' => asset_url('img/demo.svg')];
$v = static fn (string $f): string => is_file(FCPATH . $f) ? (string) filemtime(FCPATH . $f) : '1';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<?= view('App\Modules\TableOrder\Views\_head', ['title' => $title, 'primary' => $primary]) ?>
</head>
<body class="to">
<header class="to-hero">
  <div class="to-hero-in">
    <span class="to-logo"><?php if ($logo !== ''): ?><img src="<?= esc($logo, 'attr') ?>" alt=""><?php else: ?><?= icon('utensils') ?><?php endif ?></span>
    <div class="to-id"><b><?= esc($info['shop']) ?></b><span>Order from your phone</span></div>
    <span class="to-pill"><?= icon('pin', 'sm') ?><?= esc($info['table']) ?></span>
  </div>
</header>

<div class="to-sticky" id="to-sticky">
  <label class="to-search"><?= icon('search', 'sm') ?><input type="search" id="to-q" placeholder="Search the menu…" autocomplete="off" aria-label="Search the menu"><button type="button" class="to-clear" id="to-clear" aria-label="Clear search" hidden><?= icon('x', 'sm') ?></button></label>
  <nav class="to-cats" id="to-cats" aria-label="Menu sections" hidden></nav>
</div>

<main class="to-main">
  <div class="to-menu" id="to-grid" aria-live="polite">
    <div class="to-skel" aria-hidden="true"><?php for ($i = 0; $i < 9; $i++): ?><i></i><?php endfor ?></div>
  </div>
</main>

<button class="to-bar" type="button" id="to-bar" data-to="open" hidden>
  <span class="to-bar-n" id="to-bar-n">0</span><span class="to-bar-t">View your order</span><b id="to-bar-total"></b><?= icon('chevron', 'sm') ?>
</button>
<button class="to-top-btn" type="button" id="to-top" data-to="top" aria-label="Back to the top" hidden><?= icon('chevron', 'sm') ?></button>

<dialog class="to-sheet" id="to-cart" aria-labelledby="to-cart-h">
  <div class="to-grab" aria-hidden="true"></div>
  <div class="to-sheet-h"><h2 id="to-cart-h">Your order <small><?= esc($info['table']) ?></small></h2><button class="icon-btn" type="button" data-to="close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="to-sheet-b">
    <div id="to-lines" class="to-lines"></div>
    <div class="to-sum" id="to-sum"></div>
    <form id="to-form" autocomplete="off" onsubmit="return false">
      <label class="field"><span>Anything we should know? <small>(optional)</small></span><input class="input" name="note" maxlength="200" placeholder="e.g. no onion, extra spicy"></label>
      <div class="form-grid">
        <label class="field"><span>Your name <small>(optional)</small></span><input class="input" name="name" maxlength="80" autocomplete="name"></label>
        <label class="field"><span>Phone <small>(optional)</small></span><input class="input" name="phone" type="tel" inputmode="tel" maxlength="20" autocomplete="tel"></label>
      </div>
      <div id="to-shield"><?= shield_field('Human check') ?></div>
      <p class="to-err" id="to-err" role="alert" hidden></p>
      <p class="to-pay"><?= icon('shield', 'sm') ?>You pay at the table when you are done.</p>
    </form>
  </div>
  <div class="to-sheet-f"><button class="btn primary lg full" type="button" id="to-place" data-to="place"><?= icon('check', 'sm') ?>Send order to the kitchen</button></div>
</dialog>

<dialog class="to-sheet to-pick" id="to-opt" aria-labelledby="to-opt-h">
  <div class="to-grab" aria-hidden="true"></div>
  <div class="to-sheet-h"><h2 id="to-opt-h">Choose an option</h2><button class="icon-btn" type="button" data-to="close-opt" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="to-sheet-b" id="to-opt-list"></div>
</dialog>

<section class="to-done" id="to-done" hidden>
  <div class="to-done-card">
    <span class="to-ok"><?= icon('check') ?></span>
    <h1>Order sent!</h1>
    <p id="to-done-msg">The kitchen has your order.</p>
    <div class="to-done-ref">Order <b id="to-done-ref"></b> · <span id="to-done-total"></span></div>
    <a class="btn lg full" id="to-done-link" href="#"><?= icon('clock', 'sm') ?>See your order status</a>
    <button class="btn primary lg full" type="button" data-to="more"><?= icon('plus', 'sm') ?>Order something else</button>
  </div>
</section>

<div id="to-toast" class="to-toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="to-config"><?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= esc(base_url('assets/pos/table-order.js') . '?v=' . $v('assets/pos/table-order.js'), 'attr') ?>" defer></script>
</body>
</html>
