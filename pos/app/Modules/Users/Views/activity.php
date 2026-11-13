<?php
/**
 * @var string      $tab
 * @var string      $table
 * @var bool        $all
 * @var int         $user
 * @var list<array> $users
 */
helper('ui');
?>
<div class="page">
  <?= view('App\Modules\Users\Views\_head', ['tab' => $tab]) ?>
  <form class="page-h inline-form" method="get" action="<?= esc(site_url('users/activity'), 'attr') ?>">
    <select class="select" name="scope" data-auto-submit aria-label="Outlets"><option value="">This outlet</option><option value="all" <?= $all ? 'selected' : '' ?>>All outlets</option></select>
    <select class="select" name="user" data-auto-submit aria-label="User"><option value="">Everyone</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>" <?= selected($user, $x['id']) ?>><?= esc($x['name']) ?></option><?php endforeach ?></select>
  </form>
  <div class="card"><?= $table ?></div>
</div>
