<?php
/**
 * The guest's order page (signed link): where the order is now. Refreshes itself until it is served.
 *
 * @var string               $title
 * @var array<string, mixed> $o       TableOrderService::status()
 * @var array<string, mixed> $info
 * @var string               $base    the table page
 * @var string               $self    this page's address
 * @var string               $primary
 * @var string               $logo
 */
helper('ui');
$cur = $info['currency'];
$money = static fn ($n): string => esc($cur['symbol'] . number_format((float) $n, (int) $cur['decimals']));
$steps = ['Order received', $info['kitchen'] ? 'In the kitchen' : 'Being prepared', 'Served'];
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<?= view('App\Modules\TableOrder\Views\_head', ['title' => $title, 'primary' => $primary]) ?>
</head>
<body class="to to-plain">
<main class="to-done-card to-status">
  <div class="to-brand"><span class="to-logo"><?php if ($logo !== ''): ?><img src="<?= esc($logo, 'attr') ?>" alt=""><?php else: ?><?= icon('utensils') ?><?php endif ?></span>
    <div class="to-id"><b><?= esc($info['shop']) ?></b><span><?= esc($info['table']) ?></span></div></div>
  <h1>Order <?= esc($o['reference']) ?></h1>
  <div class="to-steps"><?php foreach ($steps as $i => $l): ?><div class="<?= $i <= $o['step'] ? 'done' : '' ?>"><i><?= $i <= $o['step'] ? icon('check', 'sm') : $i + 1 ?></i><span><?= esc($l) ?></span></div><?php endforeach ?></div>
  <div class="to-items">
    <?php foreach ($o['items'] as $it): ?><div class="r"><span><?= esc($it['name']) ?> × <?= esc(rtrim(rtrim(number_format($it['qty'], 3), '0'), '.')) ?></span><b><?= $money($it['total']) ?></b></div><?php endforeach ?>
    <div class="r tot"><span>Total</span><b><?= $money($o['total']) ?></b></div>
  </div>
  <p class="muted"><?= $o['status'] === 'paid' ? 'Paid — thank you!' : 'Pay at the table when you are done.' ?></p>
  <a class="btn primary lg full" href="<?= esc($base, 'attr') ?>"><?= icon('plus', 'sm') ?>Order something else</a>
</main>
<?php if ((int) $o['step'] < 2): ?><script>setTimeout(function () { location.reload(); }, 20000);</script><?php endif ?>
</body>
</html>
