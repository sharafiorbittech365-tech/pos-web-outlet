<?php
/**
 * Patient profile fields (new patient dialog, profile form).
 *
 * @var array<string, mixed> $p
 * @var list<string>         $blood
 */
$v = static fn (string $k): string => (string) (old($k) ?? ($p[$k] ?? ''));
$sex = $v('sex');
$bg = $v('blood_group');
?>
<div class="form-grid">
  <label class="field">Full name *<input class="input" name="name" required maxlength="120" value="<?= esc($v('name'), 'attr') ?>"><?= field_error('name') ?><?= field_error('patient.name') ?></label>
  <label class="field">Phone *<input class="input" name="phone" required inputmode="tel" maxlength="20" value="<?= esc($v('phone'), 'attr') ?>"><?= field_error('patient.phone') ?></label>
  <label class="field">Date of birth<input class="input" type="date" name="dob" max="<?= esc(date('Y-m-d'), 'attr') ?>" value="<?= esc($v('dob'), 'attr') ?>"><?= field_error('patient.dob') ?></label>
  <label class="field">Age in years (if no date of birth)<input class="input" type="number" min="0" max="130" name="age" value="<?= esc((string) (old('age') ?? ($p['age_years'] ?? '')), 'attr') ?>"><?= field_error('patient.age') ?></label>
  <label class="field">Sex<select class="select" name="sex"><option value="">—</option><?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= $sex === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach ?></select></label>
  <label class="field">Blood group (optional)<select class="select" name="blood_group"><option value="">—</option><?php foreach ($blood as $g): ?><option <?= $bg === $g ? 'selected' : '' ?>><?= esc($g) ?></option><?php endforeach ?></select></label>
  <label class="field">Email<input class="input" type="email" name="email" maxlength="160" value="<?= esc($v('email'), 'attr') ?>"><?= field_error('patient.email') ?></label>
  <label class="field">Guardian / attendant<input class="input" name="guardian" maxlength="120" value="<?= esc($v('guardian'), 'attr') ?>"></label>
  <label class="field span2">Address<input class="input" name="address" maxlength="300" value="<?= esc($v('address'), 'attr') ?>"></label>
</div>
