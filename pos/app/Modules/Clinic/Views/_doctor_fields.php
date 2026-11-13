<?php
/**
 * Doctor profile fields (add form, edit page).
 *
 * @var array<string, mixed>              $d
 * @var list<array{id: int, name: string}> $users
 */
use App\Modules\Clinic\Services\ClinicFiles;

$v = static fn (string $k): string => (string) (old($k) ?? ($d[$k] ?? ''));
$new = empty($d['id']);
$chk = static fn (string $k): string => (old($k) !== null ? old($k) : ($new ? '1' : (string) ($d[$k] ?? '0'))) ? 'checked' : '';
?>
<div class="form-grid">
  <label class="field">Name *<input class="input" name="name" required maxlength="120" value="<?= esc($v('name'), 'attr') ?>" placeholder="e.g. Dr. ..."><?= field_error('name') ?></label>
  <label class="field">Degrees<input class="input" name="degrees" maxlength="255" value="<?= esc($v('degrees'), 'attr') ?>" placeholder="As written on the doctor's pad"></label>
  <label class="field">Specialty<input class="input" name="specialty" maxlength="160" value="<?= esc($v('specialty'), 'attr') ?>"></label>
  <label class="field">Registration no.<input class="input" name="reg_no" maxlength="60" value="<?= esc($v('reg_no'), 'attr') ?>" placeholder="e.g. BMDC reg. no. — exactly as issued"></label>
  <label class="field span2">Designation / hospital<input class="input" name="designation" maxlength="200" value="<?= esc($v('designation'), 'attr') ?>"></label>
  <label class="field span2">Chamber<input class="input" name="chamber" maxlength="255" value="<?= esc($v('chamber'), 'attr') ?>" placeholder="Room, floor, address"></label>
  <label class="field">Phone (on the pad)<input class="input" name="phone" maxlength="32" inputmode="tel" value="<?= esc($v('phone'), 'attr') ?>"><?= field_error('phone') ?></label>
  <label class="field">Consultation fee<input class="input" type="number" min="0" step="any" name="fee" value="<?= esc($v('fee') !== '' ? (string) (float) $v('fee') : '', 'attr') ?>"><?= field_error('fee') ?></label>
  <label class="field">POS user (the doctor's own sign-in)<select class="select" name="user_id"><option value="">— none —</option>
    <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $v('user_id') === $u['id'] ? 'selected' : '' ?>><?= esc($u['name']) ?></option><?php endforeach ?></select><?= field_error('user_id') ?>
    <small class="muted">A linked user can only prescribe as this doctor.</small></label>
  <label class="field">Order on the website<input class="input" type="number" min="0" max="999" name="sort_order" value="<?= esc($v('sort_order') ?: '0', 'attr') ?>"></label>
  <label class="field span2">About (shown on the website)<textarea class="input" name="bio" rows="3" maxlength="3000"><?= esc($v('bio')) ?></textarea></label>
  <label class="field">Photo<input class="input" type="file" name="photo" accept="image/png,image/jpeg,image/webp"><?= field_error('photo') ?>
    <?php if (! empty($d['photo_file_id'])): ?><span class="cl-thumb"><img src="<?= esc(ClinicFiles::url((int) $d['photo_file_id']), 'attr') ?>" alt="Current photo"><label class="cl-check"><input type="checkbox" name="remove_photo" value="1"> Remove</label></span><?php endif ?></label>
  <label class="field">Signature (printed on prescriptions)<input class="input" type="file" name="signature" accept="image/png,image/jpeg,image/webp"><?= field_error('signature') ?>
    <?php if (! empty($d['signature_file_id'])): ?><span class="cl-thumb sig"><img src="<?= esc(ClinicFiles::url((int) $d['signature_file_id']), 'attr') ?>" alt="Current signature"><label class="cl-check"><input type="checkbox" name="remove_signature" value="1"> Remove</label></span><?php endif ?></label>
  <label class="field cl-check"><input type="checkbox" name="is_active" value="1" <?= $chk('is_active') ?>> Seeing patients</label>
  <label class="field cl-check"><input type="checkbox" name="is_public" value="1" <?= $chk('is_public') ?>> Show on the website &amp; take online bookings</label>
</div>
