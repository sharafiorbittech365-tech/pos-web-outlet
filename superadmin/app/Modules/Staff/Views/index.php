<?php
/**
 * @var string      $tab    staff | roles
 * @var string|null $table
 * @var list<array> $rows   staff on this page (edit dialogs)
 * @var list<array> $roles
 */
helper('ui');
use App\Libraries\PlatformPermissions;

$rows ??= [];
$me = service('adminSession')->actor()['id'];
$roleOpts = static function (int $sel) use ($roles): string {
    $o = '';
    foreach ($roles as $r) {
        $o .= '<option value="' . (int) $r['id'] . '" ' . selected($sel, $r['id']) . '>' . esc($r['name']) . '</option>';
    }

    return $o;
};
$head = static fn (string $title): string => '<div class="modal-h"><h3>' . esc($title) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>';
$foot = static fn (string $label): string => '<div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">' . esc($label) . '</button></div>';
?>
<div class="page">
  <div class="page-h">
    <div class="seg">
      <form class="inline" method="get" action="<?= esc(site_url('staff'), 'attr') ?>"><button class="<?= $tab === 'staff' ? 'on' : '' ?>" type="submit">Staff</button></form>
      <form class="inline" method="get" action="<?= esc(site_url('staff/roles'), 'attr') ?>"><button class="<?= $tab === 'roles' ? 'on' : '' ?>" type="submit">Roles &amp; permissions</button></form>
    </div>
    <div class="spacer"></div>
    <?php if ($tab === 'staff'): ?>
      <button class="btn primary" type="button" data-modal-open="staff-new"><?= icon('userplus', 'sm') ?>Add staff</button>
    <?php else: ?>
      <button class="btn primary" type="button" data-modal-open="role-new"><?= icon('plus', 'sm') ?>New role</button>
    <?php endif ?>
  </div>

<?php if ($tab === 'staff'): ?>
  <section class="card"><?= $table ?></section>
<?php else: ?>
  <form method="post" action="<?= esc(site_url('staff/roles/permissions'), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Permission matrix</h3><button class="btn sm primary" type="submit"><?= icon('check', 'sm') ?>Save permissions</button></div>
      <div class="table-wrap"><table class="tbl perm-matrix"><thead><tr><th>Permission</th>
        <?php foreach ($roles as $r): ?><th class="num"><span style="color:<?= css_color($r['color']) ?>"><?= esc($r['name']) ?></span><br><small class="muted"><?= (int) $r['staff'] ?> staff</small>
          <?php if (! $r['is_locked']): ?><br><button class="linkbtn" type="button" data-modal-open="role-<?= (int) $r['id'] ?>"><?= icon('edit', 'sm') ?>Edit</button><?php else: ?><br><?= tag_html('Locked', 'primary') ?><?php endif ?></th><?php endforeach ?>
      </tr></thead><tbody>
        <?php foreach (PlatformPermissions::GROUPS as $group => $perms): ?>
          <tr class="grp"><td colspan="<?= count($roles) + 1 ?>"><b><?= esc($group) ?></b></td></tr>
          <?php foreach ($perms as $key => $label): ?>
            <tr><td><?= esc($label) ?> <small class="muted"><code><?= esc($key) ?></code></small></td>
              <?php foreach ($roles as $r): $on = $r['is_locked'] || in_array($key, $r['perms'], true); ?>
                <td class="num"><input type="checkbox" <?= $r['is_locked'] ? 'disabled' : 'name="perm[' . (int) $r['id'] . '][]" value="' . esc($key, 'attr') . '"' ?> <?= checked($on) ?> aria-label="<?= esc($r['name'] . ': ' . $label, 'attr') ?>"></td>
              <?php endforeach ?></tr>
          <?php endforeach ?>
        <?php endforeach ?>
      </tbody></table></div></section>
  </form>
  <p class="muted adm-note"><?= icon('shield', 'sm') ?> No permission gives access to tenant workspace data. That is a platform rule, not a role setting. The Super Admin role always has every permission.</p>
<?php endif ?>
</div>

<dialog class="modal-dlg" id="staff-new"><form method="post" action="<?= esc(site_url('staff'), 'attr') ?>"><?= csrf_field() ?><?= $head('Add staff member') ?>
  <div class="modal-b"><div class="form-grid">
    <label class="field span2">Full name<input class="input" name="name" required maxlength="100"></label>
    <label class="field span2">Work email<input class="input" type="email" name="email" required maxlength="160"></label>
    <label class="field">Role<select class="select" name="role_id"><?= $roleOpts(0) ?></select></label>
    <label class="field">Password <small class="muted">min. 10 characters</small><input class="input" type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
    <label class="check span2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked>Active (can sign in)</label>
  </div></div><?= $foot('Add staff') ?></form></dialog>

<?php foreach ($rows as $a): ?>
  <dialog class="modal-dlg" id="staff-<?= (int) $a['id'] ?>"><form method="post" action="<?= esc(site_url('staff/' . $a['id']), 'attr') ?>"><?= csrf_field() ?><?= $head('Edit ' . $a['name']) ?>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Full name<input class="input" name="name" value="<?= esc($a['name'], 'attr') ?>" required maxlength="100"></label>
      <label class="field span2">Work email<input class="input" type="email" name="email" value="<?= esc($a['email'], 'attr') ?>" required maxlength="160"></label>
      <label class="field">Role<select class="select" name="role_id"><?= $roleOpts((int) $a['role_id']) ?></select></label>
      <label class="field">New password <small class="muted">leave empty to keep</small><input class="input" type="password" name="password" minlength="10" maxlength="200" autocomplete="new-password"></label>
      <label class="check span2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= checked((bool) $a['is_active']) ?> <?= (int) $a['id'] === $me ? 'disabled' : '' ?>>Active (can sign in)</label>
      <?php if ((int) $a['id'] === $me): ?><input type="hidden" name="is_active" value="1"><?php endif ?>
    </div></div>
    <div class="modal-f"><?php if ((int) $a['id'] !== $me): ?><button class="btn danger" type="submit" form="staff-del-<?= (int) $a['id'] ?>">Remove</button><?php endif ?><span class="spacer"></span>
      <button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>
    <form id="staff-del-<?= (int) $a['id'] ?>" method="post" action="<?= esc(site_url('staff/' . $a['id'] . '/delete'), 'attr') ?>" data-confirm="Remove <?= esc($a['name'], 'attr') ?>? They can no longer sign in."><?= csrf_field() ?></form></dialog>
<?php endforeach ?>

<dialog class="modal-dlg sm" id="role-new"><form method="post" action="<?= esc(site_url('staff/roles'), 'attr') ?>"><?= csrf_field() ?><?= $head('New role') ?>
  <div class="modal-b"><label class="field">Name<input class="input" name="name" required maxlength="60"></label>
    <label class="field">Code<input class="input" name="code" required maxlength="32" pattern="[A-Za-z0-9_-]+" placeholder="e.g. onboarding"></label>
    <label class="field">Colour<input class="input" type="color" name="color" value="#64748B"></label></div><?= $foot('Create role') ?></form></dialog>

<?php foreach ($roles as $r): if ($r['is_locked']) { continue; } ?>
  <dialog class="modal-dlg sm" id="role-<?= (int) $r['id'] ?>"><form method="post" action="<?= esc(site_url('staff/roles/' . $r['id']), 'attr') ?>"><?= csrf_field() ?><?= $head('Edit role · ' . $r['name']) ?>
    <div class="modal-b"><label class="field">Name<input class="input" name="name" value="<?= esc($r['name'], 'attr') ?>" required maxlength="60"></label>
      <label class="field">Colour<input class="input" type="color" name="color" value="<?= esc($r['color'], 'attr') ?>"></label>
      <p class="muted" style="margin:0">Code <code><?= esc($r['code']) ?></code> · <?= (int) $r['staff'] ?> staff</p></div>
    <div class="modal-f"><?php if ((int) $r['staff'] === 0): ?><button class="btn danger" type="submit" form="role-del-<?= (int) $r['id'] ?>">Delete role</button><?php endif ?><span class="spacer"></span>
      <button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>
    <form id="role-del-<?= (int) $r['id'] ?>" method="post" action="<?= esc(site_url('staff/roles/' . $r['id'] . '/delete'), 'attr') ?>" data-confirm="Delete the <?= esc($r['name'], 'attr') ?> role?"><?= csrf_field() ?></form></dialog>
<?php endforeach ?>
