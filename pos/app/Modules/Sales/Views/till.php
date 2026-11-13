<?php
/**
 * The till screen skeleton (design build: posHTML). pos.js fills #up-fields, #cart-lines,
 * #cart-sum, #rp-tabs, #grid and #up-bottom.
 *
 * @var array<string, mixed> $config
 */
helper(['ui', 'pos']);
$b = $config['business'];
$u = $config['user'];
$tb = static fn (string $act, string $ic, string $title, string $color, string $extra = '', string $cls = 'tb-x'): string => '<button class="tbtn ' . esc($cls, 'attr') . '" type="button" data-act="' . esc($act, 'attr') . '" title="' . esc($title, 'attr') . '" aria-label="' . esc($title, 'attr') . '" style="--c:' . css_color($color) . '">' . icon($ic) . $extra . '</button>';
$tl = static fn (string $href, string $ic, string $title, string $color): string => '<a class="tbtn tb-x" href="' . esc($href, 'attr') . '" title="' . esc($title, 'attr') . '" aria-label="' . esc($title, 'attr') . '" style="--c:' . css_color($color) . '">' . icon($ic) . '</a>';
?>
<div class="upos" id="upos">
  <header class="m-appbar">
    <a class="m-biz" href="<?= esc(site_url('outlets'), 'attr') ?>" aria-label="Switch outlet">
      <span class="biz-logo emo"><?= biz_logo() ?></span>
      <span class="m-t"><b>Sell</b><small><?= esc($b['name']) ?> <?= icon('chevron', 'sm') ?></small></span>
    </a>
    <button class="m-ibtn" type="button" data-act="suspended" aria-label="Suspended sales"><?= icon('pause') ?><i id="m-held-n" hidden></i></button>
    <button class="m-ibtn" type="button" data-modal-open="m-tools" aria-label="More tools"><?= icon('menu') ?></button>
  </header>
  <header class="up-top">
    <div class="loc"><span class="loc-l">Location:</span>
      <a class="loc-btn" href="<?= esc(site_url('outlets'), 'attr') ?>" title="Switch outlet"><span class="emo"><?= biz_logo() ?></span><span class="loc-n"><?= esc($b['name']) ?></span><?= icon('chevron', 'sm') ?></a></div>
    <span class="date-pill" title="Sale date"><span id="pill-date"><?= esc(date('d M Y, h:i A')) ?></span></span>
    <div class="tb">
      <a class="tbtn" href="<?= esc(site_url('dashboard'), 'attr') ?>" title="Back to dashboard" aria-label="Back to dashboard" style="--c:var(--primary)"><?= icon('back') ?></a>
      <span class="tb-sep"></span>
      <?php if ($config['can']['return'] && $config['can']['orders']): ?><?= $tl(site_url('orders'), 'undo', 'Sell return (open the order)', '#E5484D') ?><?php endif ?>
      <?= $tb('suspended', 'pause', 'Suspended sales', '#64748B', '<i class="dotn" id="held-n" hidden></i>', '') ?>
      <?php if ($config['can']['orders']): ?><?= $tl(site_url('orders'), 'orders', 'Orders', '#4F46E5') ?><?php endif ?>
      <span class="tb-sep"></span>
      <?php if ($config['can']['register']): ?><?= $tl(site_url('register'), 'briefcase', 'Register details', '#16A34A') ?><?php endif ?>
      <?php if ($config['can']['customers']): ?><?= $tl(site_url('customers'), 'users', term('customers') . ' & dues', '#4F46E5') ?><?php endif ?>
      <span class="tb-sep"></span>
      <button class="tbtn tb-x" type="button" data-print-setup title="Printer setup" aria-label="Printer setup" style="--c:#0F766E"><?= icon('print') ?></button>
      <?= $tb('fullscreen', 'expand', 'Full screen', '#1E6FE8') ?>
      <?= $tb('dark', 'moon', 'Dark mode', '#64748B') ?>
      <button class="tbtn tb-x" type="button" data-sound-toggle data-nosound title="Click sound" aria-label="Click sound" style="--c:#7C3AED"><?= icon('speaker') ?></button>
      <button class="tbtn tb-more" type="button" data-modal-open="m-tools" title="More tools" aria-label="More tools" style="--c:var(--primary)"><?= icon('menu') ?></button>
      <?php if ($config['can']['expense']): ?><a class="btn tb-exp tb-x" href="<?= esc(site_url('expenses'), 'attr') ?>"><?= icon('expense') ?><span>Add Expense</span></a><?php endif ?>
      <form method="post" action="<?= esc(site_url('lock'), 'attr') ?>" class="inline"><?= csrf_field() ?>
        <button class="user-chip" type="submit" title="Lock / switch user (<?= esc($u['name'], 'attr') ?>)"><span class="av" style="--ac:<?= css_color($u['color'] ?? '#64748B') ?>"><?= esc(initials($u['name'])) ?></span></button></form>
    </div>
  </header>
  <?php if ($config['register'] === null && $config['can']['register']): ?>
    <div class="sbanners"><div class="sbanner warn"><?= icon('alert', 'sm') ?><span>The register is not open — cash sales will not be counted in a drawer session.</span><a class="btn sm" href="<?= esc(site_url('register'), 'attr') ?>">Open register</a></div></div>
  <?php endif ?>
  <div class="up-body">
    <section class="up-panel up-left" id="m-sheet" aria-label="Cart">
      <div class="m-sheet-h">
        <button class="m-ibtn" type="button" data-act="m-cart-close" aria-label="Back to products"><?= icon('back') ?></button>
        <h3>Cart <small class="muted" id="m-sheet-count"></small></h3>
        <?php if ($config['can']['orders']): ?><a class="m-ibtn" href="<?= esc(site_url('orders'), 'attr') ?>" aria-label="Recent transactions"><?= icon('clock') ?></a><?php endif ?>
      </div>
      <div class="up-scroll">
        <div class="up-fields" id="up-fields"></div>
        <div class="ctable-wrap" id="cart-lines"></div>
        <button class="m-addmore" type="button" data-act="m-cart-close"><?= icon('plus', 'sm') ?>Add more items</button>
        <div class="cart-sum" id="cart-sum"></div>
        <div class="m-acts" id="m-acts"></div>
      </div>
      <div class="m-sheet-f" id="m-pay"></div>
    </section>
    <section class="up-panel up-right">
      <div class="rp-head">
        <div class="m-search-slot" id="m-search-slot"></div>
        <div class="rp-tabs" id="rp-tabs"></div>
        <div class="rp-filters" id="rp-filters"></div>
      </div>
      <div class="tiles" id="grid" data-cols="<?= \App\Modules\Sales\Services\PosSettings::perRow() ?>"><div class="empty"><span class="empty-ic"><?= icon('search') ?></span><b>Loading products…</b></div></div>
    </section>
  </div>
  <button class="m-cartbar empty" type="button" id="m-cartbar" data-act="m-cart">
    <span class="cb-ic"><?= icon('cart') ?><i id="cb-n">0</i></span>
    <span class="cb-t"><small id="cb-items"></small><b>View cart</b></span>
    <b class="cb-total" id="cb-total"></b>
  </button>
  <footer class="up-bottom" id="up-bottom"></footer>
</div>
<?= view('App\Views\_mobile_nav') ?>
<dialog class="modal-dlg m-dlg" id="m-tools" aria-label="Tools">
  <div class="modal-h"><h3>Tools</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b"><div class="m-grid-menu">
    <button class="m-menu-item" type="button" data-act="suspended" style="--c:#64748B"><?= icon('pause') ?><span>Suspended sales</span></button>
    <?php if ($config['can']['orders']): ?><a class="m-menu-item" href="<?= esc(site_url('orders'), 'attr') ?>" style="--c:#6366F1"><?= icon('clock') ?><span>Recent transactions</span></a><?php endif ?>
    <?php if ($config['can']['return'] && $config['can']['orders']): ?><a class="m-menu-item" href="<?= esc(site_url('orders'), 'attr') ?>" style="--c:#E5484D"><?= icon('undo') ?><span>Sell return</span></a><?php endif ?>
    <?php if ($config['can']['register']): ?><a class="m-menu-item" href="<?= esc(site_url('register'), 'attr') ?>" style="--c:#16A34A"><?= icon('briefcase') ?><span>Register details</span></a><?php endif ?>
    <?php if ($config['can']['customers']): ?><a class="m-menu-item" href="<?= esc(site_url('customers'), 'attr') ?>" style="--c:#4F46E5"><?= icon('users') ?><span><?= esc(term('customers')) ?> &amp; dues</span></a><?php endif ?>
    <?php if ($config['can']['addCustomer']): ?><button class="m-menu-item" type="button" data-act="add-customer" style="--c:#4F46E5"><?= icon('userplus') ?><span>Add <?= esc(strtolower(term('customer'))) ?></span></button><?php endif ?>
    <?php if ($config['can']['expense']): ?><a class="m-menu-item" href="<?= esc(site_url('expenses'), 'attr') ?>" style="--c:#EA580C"><?= icon('expense') ?><span>Add expense</span></a><?php endif ?>
    <button class="m-menu-item" type="button" data-print-setup style="--c:#0F766E"><?= icon('print') ?><span>Printer setup</span></button>
    <button class="m-menu-item" type="button" data-act="fullscreen" style="--c:#1E6FE8"><?= icon('expand') ?><span>Full screen</span></button>
    <button class="m-menu-item" type="button" data-act="dark" style="--c:#64748B"><?= icon('moon') ?><span>Dark mode</span></button>
    <button class="m-menu-item" type="button" data-sound-toggle data-nosound style="--c:#7C3AED"><?= icon('speaker') ?><span>Click sound</span></button>
    <form method="post" action="<?= esc(site_url('lock'), 'attr') ?>" class="m-menu-form"><?= csrf_field() ?><button class="m-menu-item" type="submit" style="--c:#16A34A"><?= icon('shield') ?><span>Lock / switch user</span></button></form>
  </div></div>
</dialog>
<noscript><div class="flash err">The POS till needs JavaScript.</div></noscript>
