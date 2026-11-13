<?php
/**
 * @var array<string, mixed>|null $u
 * @var list<array>               $outlets
 * @var list<array>               $roles
 * @var array                     $counts
 * @var int                       $me
 * @var int                       $bid
 */
helper('ui');
$isNew = $u === null;
$u ??= ['name' => '', 'email' => '', 'phone' => '', 'is_owner' => 0, 'is_active' => 1, 'roles' => []];
$oldRoles = old('roles');
$defaultRole = 0;
foreach ($roles as $r) {
    if (! $r['is_locked']) {
        $defaultRole = (int) $r['id'];
        break;
    }
}
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= esc($isNew ? 'Add team member' : (string) $u['name']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('users'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Team</a></p></div>
  </div>
  <form class="card" method="post" action="<?= esc(site_url($isNew ? 'users' : 'users/' . $u['id']), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="card-b">
      <?php if ($u['is_owner']): ?><div class="sbanner info"><?= icon('crown', 'sm') ?><span class="grow">This is the account owner — always active with full access in every outlet.</span></div><?php endif ?>
      <div class="form-grid">
        <label class="field span2">Full name *<input class="input" name="name" value="<?= old_or('name', $u['name']) ?>" required maxlength="100" autofocus><?= field_error('name') ?></label>
        <label class="field">Phone<input class="input" name="phone" value="<?= old_or('phone', $u['phone']) ?>" maxlength="32" inputmode="tel"></label>
        <label class="field">Email <small class="muted">(optional — for back-office sign-in)</small><input class="input" type="email" name="email" value="<?= old_or('email', $u['email']) ?>" maxlength="160"><?= field_error('email') ?></label>
        <label class="field">4-digit PIN <?= $isNew ? '*' : '<small class="muted">(leave empty to keep)</small>' ?><input class="input" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" placeholder="e.g. 4821" <?= $isNew ? 'required' : '' ?>><?= field_error('pin') ?></label>
        <label class="field">Back-office password <small class="muted">(optional, 8+ characters)</small><input class="input" type="password" name="password" autocomplete="new-password" minlength="8"><?= field_error('password') ?></label>
        <?php if (! $u['is_owner']): ?>
          <label class="check span2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= checked(old('is_active') ?? $u['is_active']) ?> <?= ! $isNew && (int) $u['id'] === $me ? 'disabled' : '' ?>>Active (can sign in)<?= ! $isNew && (int) $u['id'] === $me ? '<input type="hidden" name="is_active" value="1">' : '' ?></label>
          <?= field_error('is_active') ?>
        <?php endif ?>
      </div>
      <div class="field">Role in each outlet <?= field_error('roles') ?></div>
      <div class="table-wrap"><table class="tbl compact"><thead><tr><th>Outlet</th><th>Role</th></tr></thead><tbody>
        <?php foreach ($outlets as $o): $cur = (int) ($oldRoles[$o['id']] ?? $u['roles'][$o['id']] ?? ($isNew && $o['id'] === $bid ? $defaultRole : 0)); ?>
          <tr><td><b><?= esc($o['name']) ?></b><?= $o['id'] === $bid ? ' <span class="tag">This outlet</span>' : '' ?></td>
            <td><select class="select" name="roles[<?= (int) $o['id'] ?>]" <?= $u['is_owner'] ? 'disabled' : '' ?>>
              <option value="0">No access</option>
              <?php foreach ($roles as $r): if ($r['is_locked'] && ! $u['is_owner']) {
                  continue;
              } ?>
                <option value="<?= (int) $r['id'] ?>" <?= selected($cur, $r['id']) ?>><?= esc($r['name']) ?></option>
              <?php endforeach ?>
            </select></td></tr>
        <?php endforeach ?>
      </tbody></table></div>
      <p class="muted bo-note">Change what each role can do under <a class="link" href="<?= esc(site_url('users/roles'), 'attr') ?>">Roles &amp; permissions</a>. Changes apply within a minute.</p>
      <div class="bo-actions"><a class="btn" href="<?= esc(site_url('users'), 'attr') ?>">Cancel</a><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
    </div>
  </form>
</div>
