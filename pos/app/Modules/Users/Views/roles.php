<?php
/**
 * Roles & permission matrix (owner role locked).
 *
 * @var string                               $tab
 * @var list<array>                          $roles
 * @var array<string, mixed>|null            $role
 * @var bool                                 $isNew
 * @var array<string, array<string, string>> $groups
 * @var int                                  $all
 * @var array<string, string>                $homes
 * @var list<string>                         $icons
 */
helper('ui');
$r = $isNew ? ['id' => 0, 'name' => '', 'color' => '#0891B2', 'icon' => 'user', 'home' => 'pos', 'max_discount' => 0, 'is_locked' => 0, 'perms' => old('perms') ?? ['pos.sell', 'orders.view'], 'users' => 0] : $role;
$locked = (bool) $r['is_locked'];
$dis = $locked ? 'disabled' : '';
?>
<div class="page">
  <?= view('App\Modules\Users\Views\_head', ['tab' => $tab]) ?>
  <div class="roles-wrap">
    <div class="role-list">
      <?php foreach ($roles as $x): ?>
        <a class="role-item <?= ! $isNew && (int) $x['id'] === (int) $r['id'] ? 'on' : '' ?>" href="<?= esc(site_url('users/roles?role=' . $x['id']), 'attr') ?>" style="--ac:<?= css_color($x['color']) ?>">
          <span class="ri-ic"><?= sym_icon($x['icon'], '') ?></span>
          <span class="grow"><b><?= esc($x['name']) ?></b><small><?= $x['is_locked'] ? 'Full access' : count($x['perms']) . ' of ' . $all . ' permissions' ?> · <?= (int) $x['users'] ?> users</small></span>
        </a>
      <?php endforeach ?>
      <a class="btn soft block <?= $isNew ? 'on' : '' ?>" href="<?= esc(site_url('users/roles?new=1'), 'attr') ?>"><?= icon('plus', 'sm') ?>Add role</a>
    </div>
    <section class="card role-panel">
      <form method="post" action="<?= esc(site_url($isNew ? 'users/roles' : 'users/roles/' . $r['id']), 'attr') ?>">
        <?= csrf_field() ?>
        <div class="card-h"><h3><?= sym_icon($r['icon']) ?> <?= esc($isNew ? 'New role' : $r['name']) ?></h3>
          <?php if ($locked): ?><span class="tag primary">Owner — always full access</span><?php elseif (! $isNew && (int) $r['users'] > 0): ?><span class="tag"><?= (int) $r['users'] ?> users</span><?php endif ?>
        </div>
        <div class="card-b">
          <div class="form-grid">
            <label class="field">Role name<input class="input" name="name" value="<?= old_or('name', $r['name']) ?>" maxlength="60" required <?= $dis ?>><?= field_error('name') ?></label>
            <label class="field">Opens on sign-in<select class="select" name="home" <?= $dis ?>><?php foreach ($homes as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected(old('home') ?? $r['home'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
            <label class="field">Max discount without approval (%)<input class="input" type="number" min="0" max="100" step="any" name="max_discount" value="<?= $locked ? 100 : old_or('max_discount', $r['max_discount']) ?>" <?= $dis ?>></label>
            <label class="field">Colour<input class="input" type="color" name="color" value="<?= old_or('color', $r['color']) ?>" <?= $dis ?>></label>
            <label class="field">Icon<select class="select" name="icon" <?= $dis ?>><?php foreach ($icons as $ic): ?><option value="<?= esc($ic, 'attr') ?>" <?= selected(old('icon') ?? $r['icon'], $ic) ?>><?= esc(str_replace('-', ' ', $ic)) ?></option><?php endforeach ?></select></label>
          </div>
          <?php foreach ($groups as $g => $list): ?>
            <div class="perm-group"><div class="field"><?= esc($g) ?></div>
              <div class="perm-grid perm-matrix">
                <?php foreach ($list as $k => $l): $on = $locked || in_array($k, (array) $r['perms'], true); ?>
                  <label class="perm <?= $on ? 'on' : '' ?>" data-chip-check><input type="checkbox" name="perms[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?> <?= $dis ?>><span><?= esc($l) ?></span></label>
                <?php endforeach ?>
              </div>
            </div>
          <?php endforeach ?>
          <p class="muted" style="font-size:12.5px;margin:0">Anything a role can't do can still be done once when someone with the permission enters their PIN (manager approval).</p>
          <?php if (! $locked): ?>
            <div class="bo-actions">
              <?php if (! $isNew && (int) $r['users'] === 0): ?>
                <button class="btn danger" type="submit" form="role-del" style="margin-right:auto"><?= icon('trash', 'sm') ?>Delete role</button>
              <?php endif ?>
              <button class="btn primary" type="submit"><?= icon('check', 'sm') ?><?= $isNew ? 'Create role' : 'Save role' ?></button>
            </div>
          <?php endif ?>
        </div>
      </form>
      <?php if (! $isNew && ! $locked && (int) $r['users'] === 0): ?>
        <form id="role-del" method="post" action="<?= esc(site_url('users/roles/' . $r['id'] . '/delete'), 'attr') ?>" data-confirm="Delete the role <?= esc($r['name'], 'attr') ?>?"><?= csrf_field() ?></form>
      <?php endif ?>
    </section>
  </div>
</div>
