<?php
/**
 * New POS server (also used, without the code field, inside the edit dialog).
 *
 * @var array<string, mixed>|null $server
 */
helper('ui');
$s = $server ?? ['code' => '', 'name' => '', 'base_url' => 'https://', 'region' => 'dhaka', 'capacity' => 500, 'status' => 'active', 'accepts_new' => 1];
$isNew = $server === null;
?>
<?php if ($isNew): ?><div class="page"><section class="card"><div class="card-h"><h3>New POS server</h3></div><div class="card-b">
  <form method="post" action="<?= esc(site_url('servers'), 'attr') ?>"><?= csrf_field() ?>
<?php endif ?>
    <div class="form-grid">
      <?php if ($isNew): ?>
        <label class="field">Code <small class="muted">matches omnipos.serverCode in that server's .env</small>
          <input class="input" name="code" value="<?= old_or('code', $s['code']) ?>" required maxlength="32" pattern="[A-Za-z0-9_-]+" placeholder="pos-3" autofocus><?= field_error('code') ?></label>
      <?php endif ?>
      <label class="field">Name<input class="input" name="name" value="<?= old_or('name', $s['name']) ?>" required maxlength="80"><?= field_error('name') ?></label>
      <label class="field span2">Base URL<input class="input" name="base_url" type="url" value="<?= old_or('base_url', $s['base_url']) ?>" required maxlength="255"><?= field_error('base_url') ?></label>
      <label class="field">Region<input class="input" name="region" value="<?= old_or('region', $s['region']) ?>" required maxlength="48" pattern="[A-Za-z0-9_-]+"><?= field_error('region') ?></label>
      <label class="field">Capacity (max tenants)<input class="input" name="capacity" type="number" min="1" max="100000" value="<?= old_or('capacity', $s['capacity']) ?>" required><?= field_error('capacity') ?></label>
      <label class="field">Status<select class="select" name="status">
        <?php foreach (['active' => 'Active — serving', 'draining' => 'Draining — moving tenants off', 'maintenance' => 'Maintenance — tenants see a notice', 'offline' => 'Offline'] as $k => $l): ?>
          <option value="<?= esc($k, 'attr') ?>" <?= selected(old('status') ?? $s['status'], $k) ?>><?= esc($l) ?></option>
        <?php endforeach ?>
      </select></label>
      <label class="check"><input type="hidden" name="accepts_new" value="0"><input type="checkbox" name="accepts_new" value="1" <?= checked((bool) (old('accepts_new') ?? $s['accepts_new'])) ?>>Accepts new tenants (sign-ups)</label>
    </div>
<?php if ($isNew): ?>
    <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Add server</button><a class="btn" href="<?= esc(site_url('servers'), 'attr') ?>">Cancel</a></div>
  </form>
</div></section></div>
<?php endif ?>
