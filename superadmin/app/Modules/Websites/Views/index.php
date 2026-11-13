<?php
/**
 * @var string             $table
 * @var string             $mode
 * @var array<string, int> $stats
 */
helper('ui');
$stat = static fn (string $ic, string $l, string $v, string $sub = ''): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'sub' => $sub]);
$filters = ['all' => 'All', 'storefront' => 'Storefront', 'api' => 'API-only', 'pending' => 'DNS pending', 'ssl' => 'SSL due', 'off' => 'Off'];
?>
<div class="page">
  <div class="stats">
    <?= $stat('store', 'Live storefronts', (string) $stats['storefront'], $stats['listed'] . ' on the marketplace') ?>
    <?= $stat('code', 'API-only sites', (string) $stats['api']) ?>
    <?= $stat('globe', 'Custom domains', (string) $stats['domains'], $stats['pending'] . ' waiting for DNS') ?>
    <?= $stat('shield', 'SSL renewing in 35 days', (string) $stats['ssl_soon'], 'auto-renew on') ?>
  </div>
  <div class="page-h"><div class="spacer"></div>
    <form class="seg" method="get" action="<?= esc(site_url('websites'), 'attr') ?>">
      <?php foreach ($filters as $k => $l): ?><button class="<?= $mode === $k ? 'on' : '' ?>" type="submit" name="mode" value="<?= esc($k, 'attr') ?>"><?= esc($l) ?></button><?php endforeach ?>
    </form>
  </div>
  <section class="card"><?= $table ?></section>
  <p class="muted adm-note"><?= icon('shield', 'sm') ?> Platform staff see routing records only (domains, SSL, marketplace listing). Store content, keys and orders stay with the business. A domain is verified by a TXT record <code>_omnipos-verify.&lt;domain&gt;</code> with its token or a CNAME to <code>&lt;slug&gt;.<?= esc(config(\OmniPOS\Core\Config\OmniPOS::class)->siteHost) ?></code>.</p>
</div>
