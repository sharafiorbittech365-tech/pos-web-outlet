<?php
/**
 * @var array<string, mixed> $reg
 * @var array<string, mixed> $R
 */
helper('ui');
$cur = service('posSession')->currency();
?>
<div class="page">
  <div class="page-h">
    <div><h2>Register report</h2><p class="page-sub"><?= esc(datetime_str($reg['opened_at'])) ?> – <?= $reg['closed_at'] ? esc(datetime_str($reg['closed_at'])) : 'now (open)' ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('register'), 'attr') ?>"><?= icon('back', 'sm') ?>Register</a>
    <button class="btn" type="button" onclick="window.print()"><?= icon('print', 'sm') ?>Print</button>
  </div>
  <?php if ($reg['closed_at']): ?>
    <div class="stats">
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Expected', 'value' => money($reg['expected_cash'], $cur)]) ?>
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'register', 'label' => 'Counted', 'value' => money($reg['counted_cash'], $cur)]) ?>
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Difference', 'value' => money((float) $reg['counted_cash'] - (float) $reg['expected_cash'], $cur), 'color' => (float) $reg['counted_cash'] < (float) $reg['expected_cash'] ? 'var(--danger)' : 'var(--ok)']) ?>
    </div>
  <?php endif ?>
  <div class="reg-grid"><?= view('App\Modules\Register\Views\_tables', ['R' => $R]) ?></div>
</div>
