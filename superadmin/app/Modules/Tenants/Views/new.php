<?php
/**
 * @var list<array>                        $plans active plans
 * @var array<string, array<string,mixed>> $types business types
 * @var int                                $trialDays
 * @var string                             $trialPlan
 */
helper('ui');
$catalog = service('catalog');
$defPlan = old('plan_id');
if ($defPlan === null) {
    foreach ($plans as $p) {
        if ($p['code'] === $trialPlan) {
            $defPlan = $p['id'];
        }
    }
}
?>
<div class="page">
  <section class="card"><div class="card-h"><h3>New tenant</h3></div><div class="card-b">
    <form method="post" action="<?= esc(site_url('tenants'), 'attr') ?>"><?= csrf_field() ?>
      <div class="form-grid">
        <label class="field span2">Business / company name<input class="input" name="name" value="<?= old_or('name') ?>" required maxlength="160" autofocus><?= field_error('name') ?></label>
        <label class="field">Owner name<input class="input" name="owner_name" value="<?= old_or('owner_name') ?>" required maxlength="100"><?= field_error('owner_name') ?></label>
        <label class="field">Owner mobile<input class="input" name="owner_phone" value="<?= old_or('owner_phone') ?>" inputmode="tel" maxlength="32"><?= field_error('owner_phone') ?></label>
        <label class="field span2">Owner email (sign-in)<input class="input" type="email" name="owner_email" value="<?= old_or('owner_email') ?>" required maxlength="160"><?= field_error('owner_email') ?></label>
        <label class="field span2">Business type (sets the price)<select class="select" name="business_type" required>
          <?php foreach ($types as $code => $ty): ?><option value="<?= esc($code, 'attr') ?>" <?= selected(old('business_type') ?? 'grocery', $code) ?>><?= esc($ty['name']) ?></option><?php endforeach ?>
        </select><?= field_error('business_type') ?></label>
        <label class="field">Plan<select class="select" name="plan_id" required>
          <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= selected($defPlan, $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach ?>
        </select><?= field_error('plan_id') ?></label>
        <label class="field">Cycle<select class="select" name="billing_cycle">
          <option value="monthly" <?= selected(old('billing_cycle'), 'monthly') ?>>Monthly</option>
          <option value="yearly" <?= selected(old('billing_cycle'), 'yearly') ?>>Yearly (<?= (int) $catalog->setting('yearlyMonths', 10) ?> × monthly)</option>
        </select></label>
        <label class="field span2">Start as<select class="select" name="start">
          <option value="trial" <?= selected(old('start'), 'trial') ?>>Free trial (<?= $trialDays ?> days)</option>
          <option value="invoice" <?= selected(old('start'), 'invoice') ?>>Paid — issue the first invoice now</option>
        </select></label>
      </div>
      <p class="muted">The account gets its first outlet and is placed on the least loaded POS server. The owner finishes the workspace setup at first sign-in. You get a one-time password to hand over — it is shown once.</p>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('userplus', 'sm') ?>Create tenant</button><a class="btn" href="<?= esc(site_url('tenants'), 'attr') ?>">Cancel</a></div>
    </form>
  </div></section>
</div>
