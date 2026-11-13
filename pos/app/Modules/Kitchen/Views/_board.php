<?php
/**
 * Board markup (also returned by kitchen/feed): the left sidebar with remaining item quantities
 * and the ticket grid (design build: kdsSideHTML + ticketHTML).
 *
 * @var list<array<string, mixed>>  $tickets shown tickets
 * @var list<array<string, mixed>>  $all     every ticket of the last 24 h
 * @var list<array<string, mixed>>  $backlog
 * @var string                      $filter
 * @var array<string, string>       $labels
 */
helper('ui');
$left = array_sum(array_column($backlog, 'qty'));
$doneQty = 0.0;
foreach ($all as $t) {
    foreach ($t['items'] as $i) {
        if ((int) $i['kds_done'] === 1) {
            $doneQty += (float) $i['qty'];
        }
    }
}
$kitchen = (string) ($labels['kitchen'] ?? 'Kitchen');
$seat = (string) ($labels['seat'] ?? 'Table');
$btn = static fn (int $id, string $status, string $ic, string $label, string $cls = ''): string => '<form method="post" action="' . esc(site_url('kitchen/' . $id . '/status'), 'attr') . '" class="inline" data-kds-form>'
    . csrf_field() . '<input type="hidden" name="status" value="' . esc($status, 'attr') . '"><button class="btn ' . esc($cls, 'attr') . '" type="submit">' . icon($ic, 'sm') . esc($label) . '</button></form>';
?>
<aside class="kds-side" aria-label="Items to prepare">
  <div class="ks-h"><b><?= icon('chef-hat', 'sm') ?> All items</b><small><?= $backlog ? esc(qty($left)) . ' to prepare' : 'Nothing waiting' ?></small></div>
  <div class="ks-list">
    <?php foreach ($backlog as $x): ?>
      <div class="ks-row" title="<?= esc($x['orders'] . ' order' . ($x['orders'] === 1 ? '' : 's'), 'attr') ?>"><span class="ks-q"><?= esc(qty($x['qty'], $x['unit'] ?: null)) ?></span><span class="grow"><b><?= esc($x['name']) ?></b><small><?= $x['variant'] !== '' ? esc($x['variant']) . ' · ' : '' ?><?= (int) $x['orders'] ?> order<?= $x['orders'] === 1 ? '' : 's' ?><?= $x['notes'] ? ' · ' . (int) $x['notes'] . ' with notes' : '' ?></small></span></div>
    <?php endforeach ?>
    <?php if (! $backlog): ?><div class="ks-empty"><?= icon('circle-check-big') ?><span>Every item is done</span></div><?php endif ?>
  </div>
  <div class="ks-f"><span><?= icon('checks', 'sm') ?> <?= esc(qty($doneQty)) ?> item<?= $doneQty == 1 ? '' : 's' ?> done</span></div>
</aside>
<?php if ($tickets): ?>
  <div class="kds">
    <?php foreach ($tickets as $o):
        $st = $o['state'];
        $at = strtotime($o['created_at']);
        $dueTs = $at + $o['target'] * 60;
        $live = in_array($o['kitchen_status'], ['new', 'cooking'], true);
        $served = $o['kitchen_status'] === 'served';
        ?>
      <article class="kt k-<?= esc($st, 'attr') ?>" data-ticket="<?= (int) $o['id'] ?>">
        <header><span class="kt-av"><?= esc(initials($o['customer_name'] ?: 'W')) ?></span><div><b><?= esc($o['customer_name'] ?: 'Walk-in') ?></b><small><?= esc($o['order_type'] ?: 'Counter') ?><?= $o['table_ref'] ? ' · ' . esc($o['table_ref']) : '' ?><?= $o['staff_name'] ? ' · ' . esc($o['staff_name']) : '' ?></small></div><span class="kt-pg" title="Items done"><?= (int) $o['done'] ?>/<?= count($o['items']) ?></span><span class="kt-no">#<?= esc($o['doc_no']) ?></span></header>
        <div class="kt-meta"><span>Token No: <b><?= (int) $o['token'] ?></b></span><span><?= esc(datetime_str($o['created_at'])) ?></span></div>
        <ul>
          <?php foreach ($o['items'] as $i): $dn = (int) $i['kds_done'] === 1 || $served; ?>
            <li class="kt-it <?= $dn ? 'done' : '' ?>"><label class="kt-ck" title="<?= $dn ? 'Mark as not done' : 'Mark as done' ?>"><input type="checkbox" data-kds-item="<?= (int) $i['id'] ?>" <?= $dn ? 'checked' : '' ?> <?= $served ? 'disabled' : '' ?> aria-label="<?= esc($i['name'], 'attr') ?> done"><i><?= icon('check', 'sm') ?></i></label><span class="kt-name"><?= esc($i['name']) ?><?= $i['variant_name'] ? ' – <span class="muted">' . esc($i['variant_name']) . '</span>' : '' ?></span><b class="kt-q">×<?= esc(qty($i['qty'], $i['unit'] ?: null)) ?></b><?php if ($i['note']): ?><div class="kt-note">Notes: <?= esc($i['note']) ?></div><?php endif ?></li>
          <?php endforeach ?>
          <?php if ($o['note']): ?><li><span></span><span class="kt-note" style="grid-column:2/4"><?= icon('edit', 'sm') ?> <?= esc($o['note']) ?></span></li><?php endif ?>
        </ul>
        <div class="kt-prog">
          <?php if ($st === 'delayed'): ?><div class="st" style="color:var(--danger)"><?= icon('clock', 'sm') ?> Delayed by <?= (int) floor((time() - $dueTs) / 60) ?> min</div>
          <?php elseif ($st === 'ready'): ?><div class="st" style="color:var(--ok)"><?= icon('check', 'sm') ?> Ready</div>
          <?php elseif ($served): ?><div class="st muted">Done at <?= esc(time_str($o['updated_at'])) ?></div><?php endif ?>
          <div class="row"><div class="bar <?= $st === 'delayed' ? 'late' : ($st === 'cooking' ? 'warn' : '') ?>"><i style="width:<?= $live ? min(100, max(2, (int) round((time() - $at) / max(1, $dueTs - $at) * 100))) : 100 ?>%"></i></div><span><?= icon('clock', 'sm') ?></span><b <?= $live ? 'data-since="' . $at . '"' : '' ?>><?= esc(gmdate((time() - $at) >= 3600 ? 'G:i:s' : 'i:s', max(0, ($live ? time() : strtotime((string) $o['updated_at'])) - $at))) ?></b></div>
        </div>
        <footer>
          <?php if ($o['kitchen_status'] === 'new'): ?><?= $btn((int) $o['id'], 'cooking', 'play', 'Start') ?><?= $btn((int) $o['id'], 'ready', 'checks', 'Mark ready') ?>
          <?php elseif ($o['kitchen_status'] === 'cooking'): ?><?= $btn((int) $o['id'], 'new', 'pause', 'Pause') ?><?= $btn((int) $o['id'], 'ready', 'checks', 'Mark ready', 'primary') ?>
          <?php elseif ($o['kitchen_status'] === 'ready'): ?><?= $btn((int) $o['id'], 'cooking', 'undo', 'Recall') ?><?= $btn((int) $o['id'], 'served', 'check', 'Done', 'ok') ?>
          <?php else: ?><a class="btn" href="<?= esc(site_url('orders/' . $o['id']), 'attr') ?>"><?= icon('receipt', 'sm') ?>View order</a><?php endif ?>
        </footer>
      </article>
    <?php endforeach ?>
  </div>
<?php else: ?>
  <div class="card"><div class="empty"><span class="empty-ic"><?= icon('chef-hat') ?></span><b>All caught up</b><span>Tickets appear when a sale is finalized or sent with “<?= esc($kitchen) ?> order” ticked.</span></div></div>
<?php endif ?>
