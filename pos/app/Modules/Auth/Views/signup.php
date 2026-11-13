<?php
/**
 * @var array<string, string> $types
 * @var int                   $trialDays
 * @var string                $planName
 * @var bool                  $open
 */
helper('ui');
?>
<h2>Start your free trial</h2>
<p class="muted"><?= (int) $trialDays ?> days of the <?= esc($planName) ?> plan. No card needed.</p>
<?php if (! $open): ?>
  <div class="ws-err"><?= icon('alert', 'sm') ?>New sign-ups are closed right now.</div>
<?php else: ?>
<form method="post" action="<?= esc(site_url('signup'), 'attr') ?>" class="form-grid">
  <?= csrf_field() ?><?php helper('ui'); ?>
  <label class="field span2">Business / company name<input class="input" name="account" value="<?= old_or('account') ?>" placeholder="e.g. Karim Electronics" autocomplete="organization" required maxlength="160"><?= field_error('account') ?></label>
  <label class="field">Your name<input class="input" name="owner" value="<?= old_or('owner') ?>" autocomplete="name" required maxlength="100"><?= field_error('owner') ?></label>
  <label class="field">Mobile<input class="input" name="phone" value="<?= old_or('phone') ?>" inputmode="tel" placeholder="01XXX-XXXXXX" autocomplete="tel" maxlength="20"><?= field_error('phone') ?></label>
  <label class="field span2">Email<input class="input" type="email" name="email" value="<?= old_or('email') ?>" autocomplete="email" required maxlength="160"><?= field_error('email') ?></label>
  <label class="field span2">Password<input class="input" type="password" name="password" placeholder="At least 6 characters" autocomplete="new-password" required minlength="6" maxlength="200"><?= field_error('password') ?></label>
  <label class="field span2">Business type<select class="select" name="type" required>
    <?php foreach ((new \App\Modules\Auth\Services\SignupService())->groupedTypes($types) as $group => $list): ?><optgroup label="<?= esc($group, 'attr') ?>"><?php foreach ($list as $code => $name): ?><option value="<?= esc($code, 'attr') ?>" <?= selected(old('type'), $code) ?>><?= esc($name) ?></option><?php endforeach ?></optgroup><?php endforeach ?>
  </select><?= field_error('type') ?></label>
  <div class="span2"><?= shield_field() ?></div>
  <button class="btn primary lg full span2" type="submit"><?= icon('check', 'sm') ?>Create my workspace</button>
</form>
<?php endif ?>
<p class="ws-alt">Already have an account? <a class="link" href="<?= esc(site_url('login'), 'attr') ?>">Sign in</a></p>
