<?php
/**
 * @var array<string, mixed> $kpi
 * @var list<array>          $revenue
 * @var list<array>          $attention
 * @var list<array>          $newest
 * @var list<array>          $servers
 * @var list<array>          $audit
 */
helper('ui');
$c = $kpi['counts'];
$paying = $c['active'] + $c['past_due'];
$max = max(1.0, ...array_column($revenue, 'total'));
$tk = static fn (float $n): string => money($n, ['decimals' => 0]);
$stat = static fn (string $ic, string $l, string $v, string $sub = '', string $color = ''): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'sub' => $sub, 'color' => $color]);
$session = service('adminSession');
?>
<div class="page">
  <div class="stats">
    <?= $stat('store', 'Accounts', (string) $c['all'], $paying . ' paying') ?>
    <?= $stat('check', 'Active', (string) $c['active']) ?>
    <?= $stat('clock', 'On trial', (string) $c['trial'], $c['all'] ? round($paying / $c['all'] * 100) . '% paying' : '') ?>
    <?= $stat('alert', 'Past due', (string) $c['past_due'], $c['suspended'] . ' suspended') ?>
    <?= $stat('chart', 'MRR', $tk($kpi['mrr']), 'ARR ' . $tk($kpi['mrr'] * 12)) ?>
    <?= $stat('receipt', 'Open invoices', $tk($kpi['open_total']), $kpi['open_count'] . ' open · ' . $kpi['overdue'] . ' overdue') ?>
    <?= $stat('cash', 'Payments this month', $tk($kpi['paid_month']), $kpi['paid_month_count'] . ' payments') ?>
  </div>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Revenue collected</h3><small class="muted">Payments · last 6 months</small></div>
      <div class="card-b"><div class="vbars" role="img" aria-label="Revenue by month">
        <?php foreach ($revenue as $m): ?>
          <div class="vbar" title="<?= esc($m['label'] . ': ' . $tk($m['total']), 'attr') ?>"><span class="vb-v"><?= $m['total'] > 0 ? esc($tk($m['total'])) : '' ?></span><i style="height:<?= (string) ((float) max(2, round($m['total'] / $max * 100))) ?>%"></i><small><?= esc($m['label']) ?></small></div>
        <?php endforeach ?>
      </div></div></section>

    <section class="card"><div class="card-h"><h3>POS servers</h3><?php if ($session->can('servers.manage')): ?><a class="link" href="<?= esc(site_url('servers'), 'attr') ?>">Manage</a><?php endif ?></div>
      <div class="card-b hbars">
        <?php foreach ($servers as $s): ?>
          <div class="hbar" title="<?= esc($s['name'] . ': ' . $s['tenants'] . ' / ' . $s['capacity'] . ' tenants', 'attr') ?>"><span><?= esc($s['name']) ?></span><div><i style="width:<?= (string) ((float) max(2, min(100, $s['load_pct']))) ?>%;background:<?= $s['load_pct'] >= 90 ? 'var(--danger)' : ($s['load_pct'] >= 75 ? 'var(--warn)' : 'var(--primary)') ?>"></i></div><b><?= (int) $s['tenants'] ?>/<?= (int) $s['capacity'] ?></b></div>
          <div class="muted srv-line"><?= status_tag($s['status']) ?> <?= $s['online'] ? tag_html('Online', 'ok') : tag_html('No heartbeat', 'danger') ?> <small><?= esc($s['region']) ?><?= $s['last_heartbeat_at'] ? ' · seen ' . esc(datetime_str($s['last_heartbeat_at'])) : '' ?></small></div>
        <?php endforeach ?>
        <?php if ($servers === []): ?><div class="kcol-empty">No POS servers yet</div><?php endif ?>
      </div></section>
  </div>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Needs attention</h3><?= tag_html((string) count($attention), $attention ? 'warn' : 'ok') ?></div>
      <div class="dlist">
        <?php foreach ($attention as $t):
            [$kind, $ic, $txt] = match ($t['status']) {
                'past_due' => ['warn', 'alert', 'Payment overdue'],
                'suspended' => ['danger', 'ban', 'Suspended · ' . ($t['suspend_reason'] ?? '')],
                default => ['primary', 'clock', 'Trial ends ' . date_str($t['trial_ends_at'])],
            }; ?>
          <a class="alert-row <?= $kind ?>" href="<?= esc(site_url('tenants/' . $t['id']), 'attr') ?>"><span class="ar-ic"><?= icon($ic, 'sm') ?></span><span class="grow"><b><?= esc($t['name']) ?></b><small><?= esc($txt) ?></small></span><?= icon('chevron', 'sm') ?></a>
        <?php endforeach ?>
        <?php if ($attention === []): ?><div class="kcol-empty">All accounts are in good standing</div><?php endif ?>
      </div></section>
    <section class="card"><div class="card-h"><h3>Newest accounts</h3></div>
      <div class="dlist">
        <?php foreach ($newest as $t): ?>
          <a class="alert-row primary" href="<?= esc(site_url('tenants/' . $t['id']), 'attr') ?>"><span class="ar-ic"><?= icon('store', 'sm') ?></span><span class="grow"><b><?= esc($t['name']) ?></b><small><?= esc($t['plan_name']) ?> · <?= esc(date_str($t['created_at'])) ?></small></span><?= status_tag($t['status']) ?></a>
        <?php endforeach ?>
      </div></section>
  </div>

  <?php if ($session->can('audit.view')): ?>
    <section class="card"><div class="card-h"><h3>Recent activity</h3><a class="link" href="<?= esc(site_url('audit'), 'attr') ?>">Audit log</a></div>
      <div class="table-wrap"><table class="tbl adm-tbl compact"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Tenant</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($audit as $a): ?>
          <tr><td class="nw" data-l="When"><?= esc(datetime_str($a['created_at'])) ?></td><td data-l="Who"><b><?= esc($a['actor_name']) ?></b><br><small class="muted"><?= esc($a['actor_role']) ?></small></td><td data-l="Action"><?= esc($a['action']) ?></td>
            <td data-l="Tenant"><?= $a['tenant_id'] ? '<a class="link" href="' . esc(site_url('tenants/' . $a['tenant_id']), 'attr') . '">' . esc($a['tenant_name'] ?? ('#' . $a['tenant_id'])) . '</a>' : '—' ?></td><td data-l="Detail"><?= esc($a['detail'] ?? '') ?></td></tr>
        <?php endforeach ?>
        <?php if ($audit === []): ?><tr class="dt-empty"><td colspan="5"><span class="muted">No activity yet.</span></td></tr><?php endif ?>
      </tbody></table></div></section>
  <?php endif ?>
</div>
