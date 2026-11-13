<?php helper('ui'); ?>
<h2>Staff sign-in</h2>
<p class="muted">For OmniPOS platform staff only. Business owners sign in from the <a href="<?= esc(config(\OmniPOS\Core\Config\OmniPOS::class)->posUrl, 'attr') ?>">POS app</a>.</p>
<form method="post" action="<?= esc(site_url('login'), 'attr') ?>" class="form-grid one">
  <?= csrf_field() ?><?php helper('ui'); ?>
  <label class="field">Email
    <input class="input" type="email" name="email" value="<?= old_or('email') ?>" autocomplete="username" required autofocus maxlength="160">
    <?= field_error('email') ?>
  </label>
  <label class="field">Password
    <input class="input" type="password" name="password" autocomplete="current-password" required maxlength="200">
    <?= field_error('password') ?>
  </label>
  <div class="span2"><?= shield_field() ?></div>
  <button class="btn primary lg full" type="submit"><?= icon('shield', 'sm') ?>Sign in</button>
</form>
<?php if (ENVIRONMENT !== 'production'): ?>
  <small class="muted">Demo: owner@omnipos.test · support@ · finance@ · sales@omnipos.test — password <?= esc(\OmniPOS\Core\Database\Seeds\PlatformSeeder::DEMO_PASSWORD) ?></small>
<?php endif ?>
