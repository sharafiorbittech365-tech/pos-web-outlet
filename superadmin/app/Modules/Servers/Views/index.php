<?php
/**
 * @var string      $table
 * @var list<array> $servers ServerAllocation::overview()
 * @var int         $unassigned accounts without a server
 */
helper('ui');
$tenants = array_sum(array_column($servers, 'tenants'));
$capacity = array_sum(array_map(static fn (array $s): int => (int) $s['capacity'], $servers));
$online = count(array_filter($servers, static fn (array $s): bool => $s['online']));
$open = count(array_filter($servers, static fn (array $s): bool => $s['status'] === 'active' && (bool) $s['accepts_new']));
$stat = static fn (string $ic, string $l, string $v, string $sub = ''): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'sub' => $sub]);
?>
<div class="page">
  <div class="stats">
    <?= $stat('server', 'POS servers', (string) count($servers), $online . ' online') ?>
    <?= $stat('store', 'Tenants placed', number_format($tenants), 'of ' . number_format($capacity) . ' places') ?>
    <?= $stat('gauge', 'Platform load', $capacity ? round($tenants / $capacity * 100) . '%' : '0%') ?>
    <?= $stat('userplus', 'Taking new tenants', (string) $open, $unassigned . ' unassigned account(s)') ?>
  </div>
  <div class="page-h">
    <p class="page-sub">Every tenant is served by exactly one POS server. All data lives in the central database, so moving a tenant moves no data — the next request is simply served by the new server.</p>
    <div class="spacer"></div>
    <a class="btn primary" href="<?= esc(site_url('servers/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>New server</a>
  </div>
  <section class="card"><?= $table ?></section>
  <p class="muted adm-note"><?= icon('info', 'sm') ?> A server is online when it sent a heartbeat (<code>php spark omnipos:heartbeat</code>, every minute from cron) in the last <?= (int) config(\OmniPOS\Core\Config\OmniPOS::class)->heartbeatTimeout ?> seconds. New sign-ups go to the least loaded active server that accepts new tenants.</p>
</div>
