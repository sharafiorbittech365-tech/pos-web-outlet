<?php
/**
 * Set-up step for an outlet that was created empty (settings.setup = false).
 *
 * @var array<string, mixed>  $business
 * @var array<string, string> $types
 * @var bool                  $hasPin
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><div><h2>Welcome to <?= esc($business['name']) ?></h2><p class="page-sub">One short step and the till is ready.</p></div></div>
  <section class="card reg-open">
    <div class="card-h"><h3><?= icon('setup', 'sm') ?> Set up your outlet</h3></div>
    <form method="post" action="<?= esc(site_url('welcome'), 'attr') ?>" class="form-grid" style="padding:14px">
      <?= csrf_field() ?>
      <label class="field span2">Business type<select class="select" name="type">
        <?php foreach ((new \App\Modules\Auth\Services\SignupService())->groupedTypes($types) as $group => $list): ?><optgroup label="<?= esc($group, 'attr') ?>"><?php foreach ($list as $code => $name): ?><option value="<?= esc($code, 'attr') ?>" <?= selected($business['business_type'], $code) ?>><?= esc($name) ?></option><?php endforeach ?></optgroup><?php endforeach ?>
      </select><small class="muted">Sets the POS features, wording, payment methods, VAT and tables for this type.</small></label>
      <label class="check span2"><input type="checkbox" name="samples" value="1" checked>Add the starter categories, sample products and customers</label>
      <?php if (! $hasPin): ?>
        <label class="field span2">Your 4-digit till PIN<input class="input" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="e.g. 2580"><small class="muted">Used to unlock the till after "Lock / switch user".</small><?= field_error('pin') ?></label>
      <?php endif ?>
      <button class="btn primary span2" type="submit"><?= icon('check', 'sm') ?>Set up and open the dashboard</button>
    </form>
  </section>
</div>
