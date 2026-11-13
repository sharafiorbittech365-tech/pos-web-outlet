<?php
/**
 * @var array<string, array> $types
 * @var string               $type
 * @var array<string, array> $modules
 * @var list<array>          $plans
 * @var array<string, int>   $addonCounts
 * @var array<string, array> $graph       ModuleRules::graph()
 * @var list<string>         $recommended recommended modules of $type
 * @var array<string, array> $features    module features (PlatformCatalog::features())
 */
helper('ui');
$catalog = service('catalog');
$tk = static fn ($n): string => money($n, ['decimals' => 0]);
$ty = $types[$type];
$starter = $plans[0] ?? null;
$dlgHead = static fn (string $title): string => '<div class="modal-h"><h3>' . esc($title) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>';
?>
<div class="page">
  <?php if (service('pricing')->active()): ?><div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Module prices, on/off and discounts are now one price list for all business types, managed under <b>Pricing</b>. This page keeps module rules, features and recommendations.</span><a class="btn sm primary" href="<?= esc(site_url('pricing#prices'), 'attr') ?>">Open Pricing</a></div><?php endif ?>
  <div class="page-h"><p class="page-sub">Every module has its own monthly price for each business type. A plan includes some modules for free; tenants can add any other module on its own. Leave the price empty if a module does not fit that business type.</p></div>
  <div class="cat-chips type-chips">
    <?php foreach ($types as $code => $t): ?><a class="chip sm <?= $type === $code ? 'on' : '' ?>" href="<?= esc(site_url('modules') . '?type=' . rawurlencode($code), 'attr') ?>"><?= sym_icon($t['icon']) ?> <?= esc($t['name']) ?></a><?php endforeach ?>
  </div>
  <div class="adm-grid2 mp-top">
    <section class="card"><div class="card-b mp-type"><span class="mp-emo"><?= sym_icon($ty['icon'], '') ?></span><div><b><?= esc($ty['name']) ?></b>
      <small class="muted">Plan base: <?= esc(implode(' · ', array_map(static fn (array $p): string => $p['name'] . ' ' . (($v = $catalog->planPrice((int) $p['id'], $type)) !== null ? money($v, ['decimals' => 0]) : '—'), $plans))) ?></small>
      <small class="muted">Storefront: <?= esc($ty['store_kind']) ?><?= $ty['booking_kind'] ? ' · online ' . esc($ty['booking_kind']) . ' requests' : '' ?></small></div></div></section>
    <?php if ($starter !== null): $sum = ($catalog->planPrice((int) $starter['id'], $type) ?? 0) + array_sum(array_map(static fn (string $c): float => $catalog->planIncludes($starter, $c) ? 0.0 : (float) ($catalog->modulePrice($c, $type) ?? 0), array_keys($modules))); ?>
      <section class="card"><div class="card-b mp-type"><div><b>Example bill</b><small class="muted"><?= esc($starter['name']) ?> + every module not in it: <?= esc($tk($sum)) ?>/mo<?php foreach (array_slice($plans, 1, 1) as $p): ?> vs <?= esc($p['name']) ?> plan <?= esc(($v = $catalog->planPrice((int) $p['id'], $type)) !== null ? $tk($v) : '—') ?>/mo<?php endforeach ?></small></div></div></section>
    <?php endif ?>
  </div>

  <form method="post" action="<?= esc(site_url('modules/prices'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
    <section class="card"><div class="card-h"><h3>Module prices for <?= esc($ty['name']) ?></h3><button class="btn sm primary" type="submit"><?= icon('check', 'sm') ?>Save prices</button></div>
      <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Module</th><th>Included in</th><th class="num">Price / month (৳)</th><th class="num">Add-on tenants (this type)</th><th></th></tr></thead><tbody>
        <?php foreach ($modules as $code => $m): $v = $catalog->modulePrice($code, $type); $n = $addonCounts[$code] ?? 0; ?>
          <tr><td data-l="Module"><span class="mp-mod"><?= icon($m['icon'] ?: 'grid', 'sm') ?><span><b><?= esc($m['name']) ?></b><small class="muted"><?= esc($m['description'] ?? '') ?></small>
              <?php if ($m['needs']): ?><small class="muted">Needs <?= esc(implode(', ', array_map(static fn (string $x): string => (string) ($modules[$x]['name'] ?? $x), $m['needs']))) ?></small><?php endif ?></span></span></td>
            <td data-l="Included in"><?php if ($m['is_core']): ?><?= tag_html('Core · always on', 'ok') ?><?php else: $in = array_filter($plans, static fn (array $p): bool => $catalog->planIncludes($p, $code)); ?>
              <?php foreach ($in as $p): ?><span class="tag" style="color:<?= css_color($p['color']) ?>"><?= esc($p['name']) ?></span> <?php endforeach ?><?= $in === [] ? '<span class="muted">Add-on only</span>' : '' ?><?= $m['is_opt_in'] ? ' ' . tag_html('Opt-in', 'warn') : '' ?><?php endif ?></td>
            <td class="num" data-l="Price"><input class="input mp-in" type="number" min="0" step="any" name="prices[<?= esc($code, 'attr') ?>]" value="<?= $v !== null ? esc((string) (float) $v, 'attr') : '' ?>" placeholder="not offered" aria-label="<?= esc($m['name'], 'attr') ?> price"></td>
            <td class="num" data-l="Add-on"><?= $n ?> tenant<?= $n === 1 ? '' : 's' ?></td>
            <td class="num"><button class="btn sm" type="button" data-modal-open="mod-<?= esc($code, 'attr') ?>"><?= icon('edit', 'sm') ?>Edit</button></td></tr>
        <?php endforeach ?>
      </tbody></table></div>
      <?= field_error('prices') ?>
    </section>
  </form>

  <section class="card" id="features"><div class="card-h"><h3><?= icon('sliders', 'sm') ?> Features &amp; feature prices</h3><button class="btn sm primary" type="button" data-modal-open="feat-new"><?= icon('plus', 'sm') ?>New feature</button></div>
    <div class="card-b"><p class="muted" style="margin:0">Features are priced parts of a module. A default feature is included in the module price; other features are billed per month on top when the customer (or you) switches them on. Prices below are for <?= esc($ty['name']) ?>.</p></div>
    <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Feature</th><th>Module</th><th class="num">Price / month</th><th>Included</th><th>Self-select</th><th></th></tr></thead><tbody>
      <?php foreach ($features as $fc => $f): $fp = $catalog->featurePrice((string) $fc, $type); ?>
        <tr><td data-l="Feature"><b><?= esc($f['name']) ?></b><br><small class="muted"><code><?= esc((string) $fc) ?></code><?= $f['description'] ? ' · ' . esc($f['description']) : '' ?></small></td>
          <td data-l="Module"><?= esc((string) ($modules[$f['module_code']]['name'] ?? $f['module_code'])) ?></td>
          <td class="num" data-l="Price"><?= $fp === null ? '<span class="muted">not offered</span>' : esc($tk($fp)) ?><?= array_key_exists($type, (array) $f['type_prices']) ? '<br><small class="muted">type price</small>' : '' ?></td>
          <td data-l="Included"><?= $f['is_default'] ? tag_html('Default', 'ok') : '<span class="muted">Extra</span>' ?></td>
          <td data-l="Self-select"><?= $f['self_select'] ? 'Yes' : tag_html('Support only', 'warn') ?></td>
          <td class="num"><button class="btn sm" type="button" data-modal-open="feat-<?= esc(str_replace('.', '-', (string) $fc), 'attr') ?>"><?= icon('edit', 'sm') ?>Edit</button></td></tr>
      <?php endforeach ?>
      <?php if ($features === []): ?><tr><td colspan="6" class="muted">No features yet.</td></tr><?php endif ?>
    </tbody></table></div></section>

  <?php
  $featForm = static function (?string $fc, array $f) use ($modules, $type, $ty, $dlgHead): string {
      $isNew = $fc === null;
      $tp = (array) ($f['type_prices'] ?? []);
      ob_start(); ?>
    <dialog class="modal-dlg" id="feat-<?= esc($isNew ? 'new' : str_replace('.', '-', $fc), 'attr') ?>"><form method="post" action="<?= esc(site_url($isNew ? 'modules/features' : 'modules/features/' . $fc), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
      <?= $dlgHead($isNew ? 'New feature' : 'Edit feature · ' . $f['name']) ?>
      <div class="modal-b">
        <?php if ($isNew): ?><label class="field">Code<input class="input" name="code" required maxlength="48" placeholder="invoicing.recurring" pattern="[a-z][a-z0-9_]*\.[a-z0-9_]{2,30}"><small class="muted">module code, a dot, a short name</small></label>
        <?php else: ?><p class="muted" style="margin:0">Code <code><?= esc($fc) ?></code></p><?php endif ?>
        <label class="field">Module<select class="select" name="module_code"><?php foreach ($modules as $mc => $mm): ?><option value="<?= esc($mc, 'attr') ?>" <?= selected((string) ($f['module_code'] ?? ''), $mc) ?>><?= esc($mm['name']) ?></option><?php endforeach ?></select></label>
        <label class="field">Name<input class="input" name="name" required maxlength="80" value="<?= esc((string) ($f['name'] ?? ''), 'attr') ?>"></label>
        <label class="field">Description<input class="input" name="description" maxlength="255" value="<?= esc((string) ($f['description'] ?? ''), 'attr') ?>"></label>
        <label class="field">Flat price / month (৳)<input class="input" type="number" min="0" step="any" name="price" required value="<?= esc((string) (float) ($f['price'] ?? 0), 'attr') ?>"></label>
        <label class="field">Price for <?= esc($ty['name']) ?> (empty = flat price)<input class="input" type="number" min="0" step="any" name="type_price" value="<?= array_key_exists($type, $tp) && $tp[$type] !== null ? esc((string) (float) $tp[$type], 'attr') : '' ?>"></label>
        <label class="check"><input type="hidden" name="not_offered" value="0"><input type="checkbox" name="not_offered" value="1" <?= checked(array_key_exists($type, $tp) && $tp[$type] === null) ?>>Not offered for <?= esc($ty['name']) ?></label>
        <label class="check"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" <?= checked((bool) ($f['is_default'] ?? false)) ?>>Included in the module price (default on)</label>
        <label class="check"><input type="hidden" name="self_select" value="0"><input type="checkbox" name="self_select" value="1" <?= checked((bool) ($f['self_select'] ?? true)) ?>>Customers may switch it on / off themselves</label>
      </div>
      <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save feature</button></div>
    </form>
    <?php if (! $isNew): ?><form method="post" action="<?= esc(site_url('modules/features/' . $fc . '/delete'), 'attr') ?>" data-confirm="Remove this feature? Customers paying for it keep it until you remove it from their account (it is retired)."><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>"><div class="modal-f"><button class="btn danger sm" type="submit"><?= icon('trash', 'sm') ?>Remove feature</button></div></form><?php endif ?>
    </dialog>
  <?php return (string) ob_get_clean();
  };
  echo $featForm(null, []);
  foreach ($features as $fc => $f) {
      echo $featForm((string) $fc, $f);
  }
  ?>

  <form method="post" action="<?= esc(site_url('modules/recommended'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
    <section class="card"><div class="card-h"><h3><?= icon('star', 'sm') ?> Recommended for <?= esc($ty['name']) ?></h3><button class="btn sm primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
      <div class="card-b">
        <p class="muted" style="margin:0">Switched on automatically when a <?= esc($ty['name']) ?> account signs up or adds an outlet of this type. Modules the plan includes are simply on; on a free trial the others are added as add-ons (the owner can remove them under Subscription); paid accounts only see them as suggestions. Needed modules are added too.</p>
        <div class="cat-chips">
          <?php foreach ($modules as $code => $m): if ((bool) $m['is_core']) { continue; } ?>
            <label class="chip"><input type="checkbox" name="recommended[]" value="<?= esc($code, 'attr') ?>" <?= checked(in_array($code, $recommended, true)) ?>> <?= esc($m['name']) ?><?= $catalog->modulePrice($code, $type) === null ? ' <small class="muted">not offered</small>' : '' ?></label>
          <?php endforeach ?>
        </div>
        <?= field_error('recommended') ?>
      </div>
    </section>
  </form>

  <section class="card"><div class="card-h"><h3><?= icon('network', 'sm') ?> Dependencies &amp; compatibility</h3><small class="muted">A module runs only when every module it needs is on; conflicting modules never run together</small></div>
    <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Module</th><th>Needs</th><th>Needed by</th><th>Cannot run with</th><th>Business types</th></tr></thead><tbody>
      <?php $nm = static fn (array $codes): string => $codes === [] ? '<span class="muted">—</span>' : implode(' ', array_map(static fn (string $c): string => tag_html((string) ($modules[$c]['name'] ?? $c)), $codes));
      $byDepth = $graph; uasort($byDepth, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);
      foreach ($byDepth as $code => $g): $m = $modules[$code]; ?>
        <tr><td data-l="Module"><span class="mp-mod" style="padding-left:<?= (int) min(4, $g['depth']) * 14 ?>px"><?= icon($m['icon'] ?: 'grid', 'sm') ?><span><b><?= esc($m['name']) ?></b><small class="muted"><code><?= esc($code) ?></code><?= $m['is_core'] ? ' · core' : '' ?><?= $g['depth'] > 0 ? ' · level ' . (int) $g['depth'] : '' ?></small></span></span></td>
          <td data-l="Needs"><?= $nm($g['needs']) ?></td>
          <td data-l="Needed by"><?= $nm($g['needed_by']) ?></td>
          <td data-l="Conflicts"><?= $g['conflicts'] === [] ? '<span class="muted">—</span>' : implode(' ', array_map(static fn (string $c): string => tag_html((string) ($modules[$c]['name'] ?? $c), 'warn'), $g['conflicts'])) ?></td>
          <td data-l="Types"><?= $g['types'] === [] ? '<span class="muted">All</span>' : esc(implode(', ', array_map(static fn (string $t): string => (string) ($types[$t]['name'] ?? $t), $g['types']))) ?></td></tr>
      <?php endforeach ?>
    </tbody></table></div></section>

  <section class="card"><div class="card-h"><h3>All business types at a glance</h3><small class="muted">৳ per month · — = not offered</small></div>
    <div class="table-wrap"><table class="tbl price-matrix mp-matrix"><thead><tr><th>Module</th>
      <?php foreach ($types as $code => $t): ?><th class="num" title="<?= esc($t['name'], 'attr') ?>"><a class="link" href="<?= esc(site_url('modules') . '?type=' . rawurlencode($code), 'attr') ?>"><?= esc(preg_split('/[ &,\/]/', $t['name'])[0]) ?></a></th><?php endforeach ?></tr></thead><tbody>
      <?php foreach ($modules as $code => $m): ?>
        <tr><td><b><?= esc($m['name']) ?></b></td>
          <?php foreach ($types as $tc => $t): $v = $catalog->modulePrice($code, $tc); ?><td class="num <?= $tc === $type ? 'hl-col' : '' ?>"><?= $v === null ? '<span class="muted">—</span>' : esc(number_format($v)) ?></td><?php endforeach ?></tr>
      <?php endforeach ?>
    </tbody></table></div></section>
</div>

<?php foreach ($modules as $code => $m): ?>
  <dialog class="modal-dlg" id="mod-<?= esc($code, 'attr') ?>"><form method="post" action="<?= esc(site_url('modules/' . $code), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
    <?= $dlgHead('Edit module · ' . $m['name']) ?>
    <div class="modal-b">
      <label class="field">Name<input class="input" name="name" value="<?= esc($m['name'], 'attr') ?>" required maxlength="80"></label>
      <label class="field">Description<textarea class="input" name="description" rows="3" maxlength="255"><?= esc($m['description'] ?? '') ?></textarea></label>
      <?php if (! $m['is_core']): ?><label class="check"><input type="hidden" name="is_opt_in" value="0"><input type="checkbox" name="is_opt_in" value="1" <?= checked((bool) $m['is_opt_in']) ?>>Opt-in: off unless switched on per tenant, even on all-module plans</label>
        <label class="check"><input type="hidden" name="self_select" value="0"><input type="checkbox" name="self_select" value="1" <?= checked((bool) ($m['self_select'] ?? true)) ?>>Customers may add / remove it themselves (Subscription page); lock it per customer under Tenants › Modules</label>
      <?php else: ?><p class="muted" style="margin:0">Core module — always on for every tenant.</p><?php endif ?>
      <label class="field">Base price / month (৳)<input class="input" type="number" min="0" step="any" name="base_price" value="<?= esc((string) (float) $m['base_price'], 'attr') ?>"><small class="muted">Reference price; each business type has its own price below.</small></label>
      <p class="muted" style="margin:0">Code <code><?= esc($code) ?></code></p>
      <?php if (! $m['is_core']): ?>
        <input type="hidden" name="rules" value="1">
        <fieldset class="field"><legend>Needs (must be on for this module to run)</legend><div class="cat-chips">
          <?php foreach ($modules as $oc => $om): if ($oc === $code) { continue; } ?><label class="chip sm"><input type="checkbox" name="needs[]" value="<?= esc($oc, 'attr') ?>" <?= checked(in_array($oc, (array) $m['needs'], true)) ?>> <?= esc($om['name']) ?></label><?php endforeach ?>
        </div><?= field_error('needs') ?></fieldset>
        <fieldset class="field"><legend>Cannot run together with</legend><div class="cat-chips">
          <?php foreach ($modules as $oc => $om): if ($oc === $code || $om['is_core']) { continue; } ?><label class="chip sm"><input type="checkbox" name="conflicts[]" value="<?= esc($oc, 'attr') ?>" <?= checked(in_array($oc, (array) ($graph[$code]['conflicts'] ?? []), true)) ?>> <?= esc($om['name']) ?></label><?php endforeach ?>
        </div><?= field_error('conflicts') ?></fieldset>
        <fieldset class="field"><legend>Only for these business types (none ticked = every type)</legend><div class="cat-chips">
          <?php foreach ($types as $tc => $tt): ?><label class="chip sm"><input type="checkbox" name="types[]" value="<?= esc($tc, 'attr') ?>" <?= checked(in_array($tc, (array) ($m['requires_types'] ?? []), true)) ?>> <?= esc($tt['name']) ?></label><?php endforeach ?>
        </div><?= field_error('requires_types') ?></fieldset>
        <fieldset class="field"><legend>Price per business type (৳ / month, empty = not offered)</legend>
          <div class="form-grid">
            <?php foreach ($types as $tc => $tt): $pv = $catalog->modulePrice($code, $tc); ?>
              <label class="field"><?= esc($tt['name']) ?><input class="input" type="number" min="0" step="any" name="tprices[<?= esc($tc, 'attr') ?>]" value="<?= $pv !== null ? esc((string) (float) $pv, 'attr') : '' ?>" placeholder="not offered"></label>
            <?php endforeach ?>
          </div><?= field_error('prices') ?></fieldset>
      <?php endif ?>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save module</button></div>
  </form></dialog>
<?php endforeach ?>
