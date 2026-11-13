<?php
/**
 * @var string                                      $table
 * @var array{active: int, limit: int, total: int}  $counts
 * @var string                                      $tab
 */
helper('ui');
$full = $counts['limit'] > 0 && $counts['active'] >= $counts['limit'];
?>
<div class="page">
  <?= view('App\Modules\Users\Views\_head', ['tab' => $tab]) ?>
  <div class="sbanner <?= $full ? 'warn' : 'info' ?>"><?= icon('users', 'sm') ?>
    <span class="grow"><b><?= (int) $counts['active'] ?> of <?= $counts['limit'] > 0 ? (int) $counts['limit'] : '∞' ?> active users</b> on your plan.<?= $full ? ' Disable a user or upgrade to add more.' : '' ?></span>
    <?php if ($full && service('posSession')->can('billing.manage')): ?><a class="btn sm primary" href="<?= esc(site_url('subscription'), 'attr') ?>">Upgrade</a><?php endif ?>
    <?php if (! $full): ?><a class="btn sm primary" href="<?= esc(site_url('users/new'), 'attr') ?>"><?= icon('userplus', 'sm') ?>Add team member</a><?php endif ?>
  </div>
  <div class="card"><?= $table ?></div>
</div>
