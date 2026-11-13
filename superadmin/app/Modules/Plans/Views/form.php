<?php
/**
 * @var array<string, mixed>|null $plan
 * @var array<string, array>      $modules
 */
helper('ui');
use App\Modules\Plans\Services\PlanAdmin;

$isNew = $plan === null;
$p = $plan ?? ['code' => '', 'name' => '', 'tagline' => '', 'color' => '#7C3AED', 'limit_outlets' => 1, 'limit_users' => 5, 'all_modules' => 0, 'modules' => [], 'features' => [], 'is_active' => 1, 'is_popular' => 0, 'sort_order' => 0];
$oldMods = old('modules');
$oldFeat = old('features');
$all = (bool) (old('all_modules') ?? $p['all_modules']);
?>
<div class="page">
  <form method="post" action="<?= esc($isNew ? site_url('plans') : site_url('plans/' . $p['id']), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3><?= $isNew ? 'New plan' : 'Edit plan · ' . esc($p['name']) ?></h3></div><div class="card-b">
      <div class="form-grid">
        <?php if ($isNew): ?><label class="field">Code <small class="muted">used in links and settings, cannot change later</small><input class="input" name="code" value="<?= old_or('code') ?>" required maxlength="32" pattern="[A-Za-z0-9_-]+" autofocus><?= field_error('code') ?></label><?php endif ?>
        <label class="field">Name<input class="input" name="name" value="<?= old_or('name', $p['name']) ?>" required maxlength="80"><?= field_error('name') ?></label>
        <label class="field">Colour<input class="input" name="color" type="color" value="<?= old_or('color', $p['color']) ?>"><?= field_error('color') ?></label>
        <label class="field">Sort order<input class="input" name="sort_order" type="number" min="0" max="1000" value="<?= old_or('sort_order', $p['sort_order']) ?>"></label>
        <label class="field span2">Tagline<input class="input" name="tagline" value="<?= old_or('tagline', $p['tagline']) ?>" maxlength="160"></label>
        <label class="field">Outlets <small class="muted">0 = unlimited</small><input class="input" type="number" min="0" max="10000" name="limit_outlets" value="<?= old_or('limit_outlets', $p['limit_outlets']) ?>" required><?= field_error('limit_outlets') ?></label>
        <label class="field">Team members <small class="muted">0 = unlimited</small><input class="input" type="number" min="0" max="10000" name="limit_users" value="<?= old_or('limit_users', $p['limit_users']) ?>" required><?= field_error('limit_users') ?></label>
        <p class="muted span2" style="margin:0">Prices for each business type are set in the price list on the Plans page.</p>
      </div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Modules</h3></div><div class="card-b">
      <label class="check"><input type="hidden" name="all_modules" value="0"><input type="checkbox" name="all_modules" value="1" <?= checked($all) ?>>All modules, including future ones (opt-in modules stay off unless switched on per tenant)</label>
      <p class="muted" style="margin:0">Without "All modules", the plan includes only the modules ticked below (core modules are always on).</p>
      <div class="cat-chips">
        <?php foreach ($modules as $code => $m): if ((bool) $m['is_core']) { continue; } $on = is_array($oldMods) ? in_array($code, $oldMods, true) : in_array($code, (array) $p['modules'], true); ?>
          <label class="chip"><input type="checkbox" name="modules[]" value="<?= esc($code, 'attr') ?>" <?= checked($on) ?>> <?= esc($m['name']) ?><?= $m['is_opt_in'] ? ' <small class="muted">opt-in</small>' : '' ?></label>
        <?php endforeach ?>
      </div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Features</h3></div><div class="card-b">
      <div class="cat-chips">
        <?php foreach (PlanAdmin::FEATURES as $k => $l): $on = is_array($oldFeat) ? in_array($k, $oldFeat, true) : ! empty($p['features'][$k]); ?>
          <label class="chip"><input type="checkbox" name="features[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?>> <?= esc($l) ?></label>
        <?php endforeach ?>
      </div>
      <div class="row-add">
        <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= checked((bool) (old('is_active') ?? $p['is_active'])) ?>>Offered to new customers</label>
        <label class="check"><input type="hidden" name="is_popular" value="0"><input type="checkbox" name="is_popular" value="1" <?= checked((bool) (old('is_popular') ?? $p['is_popular'])) ?>>Mark as most popular</label>
      </div>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?><?= $isNew ? 'Create plan' : 'Save plan' ?></button><a class="btn" href="<?= esc(site_url('plans'), 'attr') ?>">Cancel</a></div>
    </div></section>
  </form>
</div>
