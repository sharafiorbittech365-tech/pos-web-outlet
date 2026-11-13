<?php
/**
 * @var string               $table
 * @var string               $filter
 * @var array<string, mixed> $stats
 * @var list<string>         $groups
 */
helper('ui');
$s = service('posSession');
$cur = $s->currency();
$chips = ['' => 'All', 'due' => 'With due', 'over' => 'Near limit'];
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= esc(term('customers')) ?> &amp; dues</h2><p class="page-sub">Due book · বাকির খাতা</p></div>
    <?php foreach ($chips as $k => $l): ?>
      <a class="chip <?= $filter === $k ? 'on' : '' ?>" href="<?= esc(site_url('customers') . ($k !== '' ? '?cust_f=' . $k : ''), 'attr') ?>"><?= esc($l) ?></a>
    <?php endforeach ?>
    <div class="spacer"></div>
    <?php if ($s->can('customers.manage')): ?><button class="btn primary" type="button" data-modal-open="add-cust"><?= icon('userplus', 'sm') ?>Add customer</button><?php endif ?>
  </div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'users', 'label' => 'Customers', 'value' => number_format($stats['customers'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'handcoin', 'label' => 'Total receivable (due)', 'value' => money($stats['receivable'], $cur), 'color' => 'var(--danger)']) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'receipt', 'label' => 'Customers with due', 'value' => number_format($stats['with_due'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Due collected today', 'value' => money($stats['collected_today'], $cur)]) ?>
  </div>
  <section class="card"><?= $table ?></section>
</div>
<?php if ($s->can('customers.manage')): ?>
<dialog class="modal-dlg" id="add-cust">
  <form method="post" action="<?= esc(site_url('customers'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Add customer</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Name *<input class="input" name="name" value="<?= old_or('name') ?>" required maxlength="120" autofocus><?= field_error('name') ?></label>
      <label class="field">Mobile<input class="input" name="phone" value="<?= old_or('phone') ?>" inputmode="tel" maxlength="20" placeholder="01XXX-XXXXXX"><?= field_error('phone') ?></label>
      <label class="field">Email<input class="input" type="email" name="email" value="<?= old_or('email') ?>" maxlength="160"></label>
      <label class="field span2">Address<input class="input" name="address" value="<?= old_or('address') ?>" maxlength="255"></label>
      <label class="field">Credit limit<input class="input" type="number" step="any" min="0" name="credit_limit" value="<?= old_or('credit_limit', '0') ?>"><small class="muted">0 = no limit</small></label>
      <label class="field">Price group<select class="select" name="price_group"><option value="">—</option><?php foreach ($groups as $g): ?><option <?= selected(old('price_group'), $g) ?>><?= esc($g) ?></option><?php endforeach ?></select></label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('userplus', 'sm') ?>Add customer</button></div>
  </form>
</dialog>
<?php endif ?>
