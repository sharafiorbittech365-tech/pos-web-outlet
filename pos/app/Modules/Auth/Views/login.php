<?php
/**
 * @var bool   $moved   arrived from another POS server
 * @var string $email
 * @var string $server  this server's code
 */
helper('ui');
?>
<h2>Sign in to your workspace</h2>
<p class="muted">Use the owner email and password of your business account.</p>
<?php if ($moved): ?>
  <div class="tag primary" style="height:auto;padding:8px 10px;white-space:normal"><?= icon('swap', 'sm') ?>Your account is served by this POS server. Please sign in here.</div>
<?php endif ?>
<form method="post" action="<?= esc(site_url('login'), 'attr') ?>" class="form-grid">
  <?= csrf_field() ?><?php helper('ui'); ?>
  <label class="field span2">Email
    <input class="input" type="email" name="email" value="<?= old_or('email', $email) ?>" autocomplete="username" required autofocus maxlength="160">
    <?= field_error('email') ?>
  </label>
  <label class="field span2">Password
    <input class="input" type="password" name="password" autocomplete="current-password" required maxlength="200">
    <?= field_error('password') ?>
  </label>
  <div class="span2"><?= shield_field() ?></div>
  <button class="btn primary lg full span2" type="submit"><?= icon('user', 'sm') ?>Sign in</button>
</form>
<?php if ((bool) service('catalog')->setting('signup', true)): ?>
  <p class="ws-alt">New to <?= esc(brand_name()) ?>? <a class="link" href="<?= esc(site_url('signup'), 'attr') ?>">Start a <?= (int) service('catalog')->setting('trialDays', 14) ?>-day free trial</a></p>
<?php endif ?>
<?php if (ENVIRONMENT !== 'production'): ?>
  <details class="ws-demo"><summary>Demo accounts <small>(password demo1234)</small></summary>
    <div class="ws-demo-list">
      <span class="muted" style="font-size:12.5px">arif@digitalzone.test · farhana@nakshifashion.test · malek@bismillahtraders.test · rafiq@kathghar.test · imran@stylecut.test (this server: <?= esc($server) ?>)</span>
      <span class="muted" style="font-size:12.5px">kamal@bhojghar.test · shahid@freshmart.test are on POS Server 2</span>
    </div>
  </details>
<?php endif ?>
