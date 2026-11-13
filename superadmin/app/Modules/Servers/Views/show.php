<?php
/**
 * @var array<string, mixed> $server
 * @var list<array>          $others  other servers (move targets)
 * @var string               $tenants data table
 * @var string               $history data table
 * @var list<array>          $beats   hourly heartbeat averages (24)
 */
helper('ui');
$s = $server;
$maxRpm = max(1.0, ...array_map(static fn (array $b): float => (float) ($b['rpm'] ?? 0), $beats));
$hasBeats = array_sum(array_column($beats, 'n')) > 0;
$targets = array_values(array_filter($others, static fn (array $o): bool => $o['status'] === 'active'));
?>
<div class="page">
  <div class="ten-head"><span class="ten-logo"><?= icon('server') ?></span><div class="grow"><h2><?= esc($s['name']) ?></h2>
      <span class="muted"><code><?= esc($s['code']) ?></code> · <?= esc($s['region']) ?> · <?= status_tag($s['status']) ?> <?= $s['online'] ? tag_html('Online', 'ok') : tag_html('Offline', 'danger') ?><?= $s['accepts_new'] ? '' : ' ' . tag_html('No new tenants') ?></span></div>
    <div class="ten-kpis"><div><small>Tenants</small><b><?= (int) $s['tenants'] ?>/<?= number_format((int) $s['capacity']) ?></b></div><div><small>Load</small><b class="<?= $s['load_pct'] >= 90 ? 'neg' : '' ?>"><?= esc((string) $s['load_pct']) ?>%</b></div></div>
    <button class="btn" type="button" data-modal-open="srv-edit"><?= icon('edit', 'sm') ?>Edit</button>
  </div>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Server</h3></div><div class="card-b kv">
      <div><small>Base URL</small><b><a class="link" href="<?= esc($s['base_url'], 'attr') ?>" target="_blank" rel="noopener"><?= esc($s['base_url']) ?></a></b></div>
      <div><small>App version</small><b><?= esc($s['app_version'] ?? '—') ?></b></div>
      <div><small>Last heartbeat</small><b><?= esc(datetime_str($s['last_heartbeat_at'])) ?></b></div>
      <div><small>CPU / memory</small><b><?= $s['cpu_load'] !== null ? esc((string) round((float) $s['cpu_load'])) . '%' : '—' ?> / <?= $s['mem_used_pct'] !== null ? esc((string) round((float) $s['mem_used_pct'])) . '%' : '—' ?></b></div>
      <div><small>Requests / min</small><b><?= $s['requests_per_min'] !== null ? number_format((int) $s['requests_per_min']) : '—' ?></b></div>
      <div><small>Avg response</small><b><?= $s['avg_response_ms'] !== null ? (int) $s['avg_response_ms'] . ' ms' : '—' ?></b></div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Requests per minute</h3><small class="muted">Hourly average · last 24 hours</small></div>
      <div class="card-b">
        <?php if ($hasBeats): ?>
          <div class="vbars hours" role="img" aria-label="Requests per minute, last 24 hours">
            <?php foreach ($beats as $i => $b): ?>
              <div class="vbar" title="<?= esc($b['label'] . ':00 — ' . ($b['n'] ? round((float) $b['rpm']) . ' rpm, CPU ' . round((float) $b['cpu']) . '%, ' . round((float) $b['ms']) . ' ms' : 'no heartbeat'), 'attr') ?>"><i style="height:<?= (string) ((float) max(2, round((float) ($b['rpm'] ?? 0) / $maxRpm * 100))) ?>%"></i><small><?= $i % 3 === 0 ? esc($b['label']) : '' ?></small></div>
            <?php endforeach ?>
          </div>
        <?php else: ?>
          <?= view('OmniPOS\Ui\Views\components\empty', ['icon' => 'gauge', 'title' => 'No heartbeats in the last 24 hours', 'text' => 'Schedule php spark omnipos:heartbeat every minute on this server.']) ?>
        <?php endif ?>
      </div></section>
  </div>

  <section class="card"><div class="card-h"><h3>Tenants on this server</h3>
      <?php if ($targets !== [] && (int) $s['tenants'] > 0): ?><button class="btn sm primary" type="button" data-modal-open="srv-move"><?= icon('swap', 'sm') ?>Move tenants…</button><?php endif ?></div>
    <?= $tenants ?>
  </section>

  <section class="card"><div class="card-h"><h3>Assignment history</h3></div><?= $history ?></section>
</div>

<dialog class="modal-dlg" id="srv-edit"><form method="post" action="<?= esc(site_url('servers/' . $s['id']), 'attr') ?>"><?= csrf_field() ?>
  <div class="modal-h"><h3>Edit <?= esc($s['name']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b"><?= view('App\Modules\Servers\Views\form', ['server' => $s]) ?>
    <p class="muted" style="margin:0">Maintenance and offline servers refuse sign-ins with a notice. Use Draining while moving tenants away; it stops new sign-ups.</p></div>
  <div class="modal-f"><?php if ((int) $s['tenants'] === 0): ?><button class="btn danger" type="submit" form="srv-del">Remove server</button><span class="spacer"></span><?php endif ?>
    <button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save server</button></div>
</form>
<form id="srv-del" method="post" action="<?= esc(site_url('servers/' . $s['id'] . '/delete'), 'attr') ?>" data-confirm="Remove <?= esc($s['name'], 'attr') ?>?"><?= csrf_field() ?></form></dialog>

<dialog class="modal-dlg" id="srv-move"><form method="post" action="<?= esc(site_url('servers/' . $s['id'] . '/drain'), 'attr') ?>" id="bulk-move" data-confirm="Move the tenants now? Their users are signed in again on the new server."><?= csrf_field() ?>
  <div class="modal-h"><h3>Move tenants off <?= esc($s['name']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-b">
    <label class="field">Move to<select class="select" name="to" required>
      <?php foreach ($targets as $o): ?><option value="<?= (int) $o['id'] ?>"><?= esc($o['name']) ?> · <?= (int) $o['tenants'] ?>/<?= (int) $o['capacity'] ?> tenants</option><?php endforeach ?>
    </select></label>
    <label class="field">Which tenants<select class="select" name="scope">
      <option value="selected">Only the tenants ticked in the list</option>
      <option value="all">All <?= (int) $s['tenants'] ?> tenants (drain this server)</option>
    </select></label>
    <label class="field">Reason<input class="input" name="reason" required maxlength="160" placeholder="e.g. Hardware upgrade, rebalancing"></label>
    <p class="muted" style="margin:0">No data moves: the database is shared. Each move is recorded in the assignment history and the audit log. Set this server to Draining first so it gets no new sign-ups.</p>
  </div>
  <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('swap', 'sm') ?>Move tenants</button></div>
</form></dialog>
