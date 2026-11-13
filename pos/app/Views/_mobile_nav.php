<?php
/**
 * Phone navigation (html.app-mode — see App\Views\_app_mode): bottom tabs
 * with the first four pages the user can open, and a "More" sheet with the rest, the outlet
 * switcher, lock, dark mode and sign out. Hidden on larger screens (pos.css).
 */
helper(['ui', 'pos']);
$s = service('posSession');
$b = $s->business();
$u = $s->user();
$items = pos_nav_items();
$byPath = array_column($items, null, 'path');
$tabs = array_slice(array_values(array_filter(['sell', 'dashboard', 'orders', 'kitchen', 'tasks', 'customers', 'inventory', 'reports'], static fn (string $p): bool => isset($byPath[$p]))), 0, 4);
$short = ['sell' => 'Sell', 'dashboard' => 'Home', 'inventory' => 'Stock'];
$more = array_values(array_filter($items, static fn (array $i): bool => ! in_array($i['path'], $tabs, true)));
$moreOn = array_filter($more, static fn (array $i): bool => nav_on($i['path']) !== '') !== [];
// the More tab's badge adds up the unread counts of the pages inside it (assets/pos/badges.js keeps it current)
$counts = pos_nav_counts();
$morePaths = array_values(array_intersect(array_column($more, 'path'), array_keys($counts)));
$moreN = array_sum(array_intersect_key($counts, array_flip($morePaths)));
?>
<nav class="m-tabs" aria-label="Main">
  <?php foreach ($tabs as $p): $i = $byPath[$p]; ?>
    <a class="<?= nav_on($p) ?>" href="<?= esc(site_url($p), 'attr') ?>"><span class="m-tab-ic"><?= icon($i['icon']) ?></span><span><?= esc($short[$p] ?? $i['label']) ?></span><?= nav_badge($p, 'badge-n') ?></a>
  <?php endforeach ?>
  <button type="button" class="<?= $moreOn ? 'on' : '' ?>" data-modal-open="m-more"><span class="m-tab-ic"><?= icon('menu') ?></span><span>More</span><?php if ($morePaths): ?><i class="badge-n nav-n" data-nav-sum="<?= esc(implode(',', $morePaths), 'attr') ?>"<?= $moreN > 0 ? '' : ' hidden' ?>><?= $moreN ?></i><?php endif ?></button>
</nav>
<dialog class="modal-dlg m-dlg" id="m-more" aria-label="More">
  <div class="modal-h"><h3>More</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b">
    <a class="biz-card m-biz-card" href="<?= esc(site_url('outlets'), 'attr') ?>">
      <span class="biz-logo emo"><?= biz_logo($b) ?></span>
      <span class="grow"><b><?= esc($b['name'] ?? '') ?></b><small><?= esc($b['type_name'] ?? '') ?><?= ! empty($b['settings']['branch']) ? ' · ' . esc($b['settings']['branch']) : '' ?></small></span>
      <?= icon('swap', 'sm') ?>
    </a>
    <div class="m-grid-menu">
      <?php foreach ($more as $i): ?>
        <a class="m-menu-item <?= nav_on($i['path']) ?>" href="<?= esc(site_url($i['path']), 'attr') ?>" style="--c:var(--primary)"><?= icon($i['icon']) ?><span><?= esc($i['label']) ?></span><?= nav_badge($i['path'], 'badge-n') ?></a>
      <?php endforeach ?>
      <button class="m-menu-item" type="button" data-print-setup style="--c:#0F766E"><?= icon('print') ?><span>Printer setup</span></button>
      <button class="m-menu-item" type="button" data-ui="dark" style="--c:#64748B"><?= icon('moon') ?><span>Dark mode</span></button>
      <a class="m-menu-item" href="<?= esc(site_url('switch-user'), 'attr') ?>" style="--c:#16A34A"><?= icon('shield') ?><span>Lock / switch user</span></a>
      <form method="post" action="<?= esc(site_url('logout'), 'attr') ?>" class="m-menu-form"><?= csrf_field() ?><button class="m-menu-item" type="submit" style="--c:var(--danger)"><?= icon('logout') ?><span>Sign out</span></button></form>
    </div>
    <div class="m-who"><span class="av sm" style="--ac:<?= css_color($u['color'] ?? '#64748B') ?>"><?= esc(initials($u['name'] ?? '?')) ?></span><span><b><?= esc($u['name'] ?? '') ?></b> · <?= esc($u['role'] ?? '') ?></span></div>
  </div>
</dialog>
