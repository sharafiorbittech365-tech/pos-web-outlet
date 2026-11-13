<?php
/**
 * Floor view (design build: floorHTML).
 *
 * @var list<array<string, mixed>> $tables
 * @var array<string, string>      $labels
 * @var bool                       $hourly
 * @var array<string, mixed>       $cur
 */
helper('ui');
$seat = (string) ($labels['seat'] ?? 'Table');
$used = count(array_filter($tables, static fn ($t) => $t['state'] !== 'free'));
$since = static fn (string $at): string => '<b data-since="' . (int) strtotime($at) . '"></b>';
?>
<div class="page">
  <div class="page-h"><h2><?= esc($labels['floor'] ?? 'Tables') ?></h2><span class="tag primary"><?= $used ?> / <?= count($tables) ?> in use</span><div class="spacer"></div>
    <div class="legend"><span><i></i>Free</span><span><i class="busy"></i>In use</span><span><i class="held"></i>Suspended</span></div>
    <?php if (service('posSession')->can('settings.manage')): ?><a class="btn sm" href="<?= esc(site_url('floor/manage'), 'attr') ?>"><?= icon('setup', 'sm') ?>Manage<?= service('posSession')->access()->has('tableqr') ? ' &amp; QR codes' : '' ?></a><?php endif ?></div>
  <?php if (! $tables): ?>
    <div class="card"><div class="empty"><span class="empty-ic"><?= icon('floor') ?></span><b>No <?= esc(strtolower($labels['floor'] ?? 'tables')) ?> yet</b><span><?php if (service('posSession')->can('settings.manage')): ?><a class="link" href="<?= esc(site_url('floor/manage'), 'attr') ?>">Add them here</a><?php else: ?>Ask a manager to add them.<?php endif ?></span></div></div>
  <?php endif ?>
  <div class="floor">
    <?php foreach ($tables as $t):
        $rate = $t['hourly_rate'] !== null ? (float) $t['hourly_rate'] : null;
        if ($t['state'] === 'busy') {
            $o = $t['order'];
            $href = site_url('orders/' . $o['id']);
            $body = '<span class="muted">' . esc($o['doc_no']) . ' · ' . esc($o['customer_name']) . '</span><span class="amount">' . esc(money($o['status'] === 'open' ? $o['due_total'] : $o['total'], $cur)) . '</span>'
                . '<span class="muted">' . icon('clock', 'sm') . ' ' . $since($o['created_at']) . ($o['kitchen_status'] !== 'none' ? ' · ' . esc($o['kitchen_status']) : '') . ($o['status'] === 'open' ? ' · not paid' : '') . '</span>';
        } elseif ($t['state'] === 'held') {
            $h = $t['held'];
            $href = site_url('sell?resume=' . $h['id']);
            $body = '<span class="muted">' . esc($h['customer_name'] ?: 'Walk-in') . ' · suspended</span><span class="amount">' . esc(money($h['total'], $cur)) . '</span>'
                . '<span class="muted">' . icon('clock', 'sm') . ' ' . $since($h['checkin'] > 0 ? date('Y-m-d H:i:s', $h['checkin']) : $h['created_at']) . '</span>';
        } else {
            $href = site_url('sell?table=' . rawurlencode($t['name']));
            $body = '<span class="muted">Free · ' . ($rate !== null && $hourly ? esc(money($rate, $cur)) . '/h' : (int) $t['seats'] . ' seats') . '</span><span class="link">Start ' . esc(strtolower($seat)) . ' →</span>';
        }
        ?>
      <a class="seat <?= $t['state'] === 'free' ? '' : esc($t['state'], 'attr') ?>" href="<?= esc($href, 'attr') ?>"><div class="s-name"><span><?= esc($t['name']) ?></span><span class="tag"><?= icon('users', 'sm') ?><?= (int) $t['seats'] ?></span></div><?= $body ?></a>
    <?php endforeach ?>
  </div>
</div>
<script>
  setInterval(function () {
    document.querySelectorAll('[data-since]').forEach(function (x) { var s = Math.max(0, Math.floor(Date.now() / 1000 - x.dataset.since)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60); x.textContent = (h ? h + 'h ' : '') + m + 'm'; });
  }, 1000);
  setTimeout(function () { location.reload(); }, 60000);
</script>
