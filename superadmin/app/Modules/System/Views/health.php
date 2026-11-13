<?php
/**
 * System health: one central database, its backups and the platform services around it.
 *
 * @var array<string, mixed> $r SystemHealth::report()
 */
helper('ui');
use OmniPOS\Core\Services\DatabaseBackup;

$kind = static fn (string $s): string => $s === 'ok' ? 'ok' : ($s === 'warn' ? 'warn' : 'danger');
$label = static fn (string $s): string => $s === 'ok' ? 'OK' : ($s === 'warn' ? 'Attention' : 'Failing');
$icons = ['database' => 'server', 'migrations' => 'layers', 'backups' => 'archive', 'disk' => 'hard-drive', 'cache' => 'zap', 'php' => 'code'];
$db = $r['checks']['database'];
$b = $r['backups'];
$dur = static function (?int $s): string {
    if ($s === null) {
        return '—';
    }

    return $s >= 86400 ? intdiv($s, 86400) . ' d ' . intdiv($s % 86400, 3600) . ' h' : intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
};
?>
<div class="page">
  <div class="page-h"><div><p class="page-sub">One central MySQL database serves every application; tenants are isolated by tenant_id / business_id. Checked <?= esc(datetime_str($r['at'])) ?> · <a class="link" href="<?= esc(site_url('system/health') . '?format=json', 'attr') ?>">JSON</a></p></div>
    <a class="btn" href="<?= esc(site_url('system/health'), 'attr') ?>"><?= icon('refresh', 'sm') ?>Check again</a></div>

  <div class="sbanner <?= $r['status'] === 'ok' ? 'ok' : 'warn' ?>"><?= icon($r['status'] === 'ok' ? 'circle-check-big' : 'alert', 'sm') ?><span class="grow"><b><?= $r['status'] === 'ok' ? 'All systems healthy' : ($r['status'] === 'warn' ? 'Some checks need attention' : 'A check is failing') ?></b></span></div>

  <section class="card"><div class="card-h"><h3>Checks</h3></div>
    <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead><tbody>
      <?php foreach ($r['checks'] as $k => $c): ?>
        <tr><td data-l="Check"><?= icon($icons[$k] ?? 'info', 'sm') ?> <b><?= esc($c['label']) ?></b></td><td data-l="Status"><?= tag_html($label($c['status']), $kind($c['status'])) ?></td><td data-l="Detail"><?= esc($c['detail']) ?></td></tr>
      <?php endforeach ?>
    </tbody></table></div></section>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3><?= icon('server', 'sm') ?> Database server</h3></div><div class="card-b kv">
      <div><small>Database</small><b><code><?= esc((string) ($db['database'] ?? '—')) ?></code></b></div><div><small>Version</small><b><?= esc((string) ($db['version'] ?? '—')) ?></b></div>
      <div><small>Latency</small><b><?= isset($db['latency_ms']) ? esc((string) $db['latency_ms']) . ' ms' : '—' ?></b></div><div><small>Uptime</small><b><?= esc($dur($db['uptime'] ?? null)) ?></b></div>
      <div><small>Connections</small><b><?= (int) ($db['threads'] ?? 0) ?> / <?= (int) ($db['max_connections'] ?? 0) ?> <small class="muted">(peak <?= (int) ($db['max_used'] ?? 0) ?>)</small></b></div>
      <div><small>Slow queries</small><b><?= isset($db['slow_queries']) ? number_format((int) $db['slow_queries']) : '—' ?></b><small class="muted"><?= ! empty($db['slow_log']) ? 'slow log on · over ' . esc((string) $db['long_query_time']) . ' s' : 'slow query log off (see docs/DEPLOYMENT.md)' ?></small></div>
      <div><small>Queries since start</small><b><?= isset($db['questions']) ? number_format((int) $db['questions']) : '—' ?></b></div><div><small>Aborted connects</small><b><?= isset($db['aborted_connects']) ? number_format((int) $db['aborted_connects']) : '—' ?></b></div>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('archive', 'sm') ?> Backups</h3><small class="muted"><code>php spark db:backup</code></small></div><div class="card-b kv">
      <div class="span2"><small>Folder</small><b><code><?= esc((string) ($b['directory'] ?? '—')) ?></code></b></div>
      <div><small>Last successful</small><b><?= ! empty($b['last_ok']) ? esc(datetime_str($b['last_ok']['finished_at'] ?? $b['last_ok']['started_at'])) : '—' ?></b><?php if (! empty($b['last_ok'])): ?><small class="muted"><?= esc(DatabaseBackup::bytes((int) $b['last_ok']['size_bytes'])) ?> · <?= round((int) $b['last_ok']['duration_ms'] / 1000, 1) ?> s</small><?php endif ?></div>
      <div><small>Latest run</small><b><?= ! empty($b['last']) ? status_tag((string) $b['last']['status']) . ' ' . esc(datetime_str($b['last']['started_at'])) : '—' ?></b><?php if (! empty($b['last']['host'])): ?><small class="muted">on <?= esc((string) $b['last']['host']) ?></small><?php endif ?></div>
      <div><small>Files kept</small><b><?= (int) ($b['count'] ?? 0) ?></b><small class="muted"><?= esc(DatabaseBackup::bytes((int) ($b['total_bytes'] ?? 0))) ?></small></div>
      <div><small>Disk</small><b><?= esc((string) ($r['checks']['disk']['detail'] ?? '—')) ?></b></div>
      <?php if (! empty($b['last']) && $b['last']['status'] === 'failed'): ?><div class="span2"><small>Error</small><b class="neg"><?= esc((string) $b['last']['message']) ?></b></div><?php endif ?>
    </div>
    <?php if (! empty($b['files'])): ?>
      <div class="table-wrap"><table class="tbl compact"><thead><tr><th>File</th><th class="num">Size</th><th>Written</th></tr></thead><tbody>
        <?php foreach ($b['files'] as $f): ?><tr><td><code><?= esc($f['name']) ?></code></td><td class="num"><?= esc(DatabaseBackup::bytes($f['size'])) ?></td><td><?= esc(datetime_str(date('Y-m-d H:i:s', $f['mtime']))) ?></td></tr><?php endforeach ?>
      </tbody></table></div>
    <?php endif ?></section>
  </div>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3><?= icon('layers', 'sm') ?> Largest tables</h3><small class="muted">rows are InnoDB estimates</small></div>
      <div class="table-wrap"><table class="tbl compact"><thead><tr><th>Table</th><th class="num">Rows</th><th class="num">Data</th><th class="num">Indexes</th><th class="num">Total</th></tr></thead><tbody>
        <?php foreach ($r['tables'] as $t): ?><tr><td><code><?= esc($t['name']) ?></code></td><td class="num"><?= number_format($t['rows']) ?></td><td class="num"><?= esc(DatabaseBackup::bytes($t['data'])) ?></td><td class="num"><?= esc(DatabaseBackup::bytes($t['index'])) ?></td><td class="num"><b><?= esc(DatabaseBackup::bytes($t['total'])) ?></b></td></tr><?php endforeach ?>
        <?php if ($r['tables'] === []): ?><tr><td colspan="5" class="muted">Table sizes are not available.</td></tr><?php endif ?>
      </tbody></table></div></section>

    <section class="card"><div class="card-h"><h3><?= icon('layers', 'sm') ?> Migrations</h3></div><div class="card-b">
      <p style="margin:0"><?= (int) $r['migrations']['applied'] ?> applied · latest <code><?= esc((string) ($r['migrations']['latest'] ?? '—')) ?></code></p>
      <?php if ($r['migrations']['pending'] !== []): ?>
        <p class="neg" style="margin:8px 0 4px"><?= count($r['migrations']['pending']) ?> pending — run <code>cd database &amp;&amp; php spark migrate --all</code></p>
        <ul class="muted" style="margin:0"><?php foreach ($r['migrations']['pending'] as $p): ?><li><code><?= esc($p) ?></code></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted" style="margin:8px 0 0">The schema is up to date.</p><?php endif ?>
    </div></section>
  </div>
</div>
