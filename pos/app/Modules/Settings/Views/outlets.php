<?php
/**
 * @var string                    $table
 * @var array{count:int,limit:int} $counts
 * @var array<string, array>      $templates
 * @var string                    $plan
 * @var list<array>               $outletRows  every outlet (edit-details dialogs)
 * @var array<int, list<array>>   $teamAccess  outlet id => people with access
 * @var array<string, list<array>> $recommended business type => recommended modules [code, name, on]
 * @var bool                      $trial
 * @var list<array>               $myOutlets   outlets the person can open (copy products from)
 */
helper('ui');
$full = $counts['limit'] > 0 && $counts['count'] >= $counts['limit'];
$prodMode = in_array(old('products'), ['template', 'copy', 'empty'], true) ? (string) old('products') : 'template';
$sel = (string) (old('type') ?? service('posSession')->business()['business_type'] ?? '');
$asset = static fn (string $f): string => base_url('assets/pos/' . $f) . '?v=' . (is_file(FCPATH . 'assets/pos/' . $f) ? filemtime(FCPATH . 'assets/pos/' . $f) : 0);
$dlgHead = static fn (string $title): string => '<div class="modal-h"><h3>' . esc($title) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>';
$canUsers = service('posSession')->can('users.manage');
?>
<link rel="stylesheet" href="<?= esc($asset('outlets.css'), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <div><h2>Outlets</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('settings'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Setup</a> · each outlet keeps its own catalogue, customers, stock, orders and register.</p></div>
    <?php if (service('posSession')->moduleOn('reports') && service('posSession')->can('reports.view')): ?><a class="btn" href="<?= esc(site_url('reports/outlets'), 'attr') ?>"><?= icon('chart', 'sm') ?>All-outlet report</a><?php endif ?>
  </div>
  <div class="sbanner <?= $full ? 'warn' : 'info' ?>"><?= icon('store', 'sm') ?><span class="grow"><b><?= (int) $counts['count'] ?> of <?= $counts['limit'] > 0 ? (int) $counts['limit'] : '∞' ?> outlets</b> used on the <?= esc($plan) ?> plan.<?= $full ? ' Upgrade to add another outlet.' : '' ?></span>
    <?php if ($full && service('posSession')->can('billing.manage')): ?><a class="btn sm primary" href="<?= esc(site_url('subscription'), 'attr') ?>">Upgrade</a><?php endif ?></div>
  <div class="card"><?= $table ?></div>

  <section class="card"><div class="card-h"><h3><?= icon('users', 'sm') ?> Who can open each outlet</h3>
      <?php if ($canUsers): ?><a class="btn sm" href="<?= esc(site_url('users'), 'attr') ?>"><?= icon('edit', 'sm') ?>Manage access</a><?php endif ?></div>
    <div class="card-b">
      <p class="muted out-note">A person sees only the outlets where they have a role (Team › edit person › Role in each outlet). The owner always has access everywhere.</p>
      <div class="out-access">
        <?php foreach ($outletRows as $o): $people = $teamAccess[(int) $o['id']] ?? []; ?>
          <div class="out-acc-row"><b><?= esc($o['name']) ?></b><?= (bool) $o['is_active'] ? '' : ' ' . tag_html('Closed') ?>
            <span class="out-people"><?php foreach ($people as $p): ?><span class="tag <?= $p['owner'] ? 'primary' : '' ?>"><?= esc($p['name']) ?> · <?= esc($p['role']) ?></span> <?php endforeach ?><?= $people === [] ? '<span class="muted">Nobody yet</span>' : '' ?></span></div>
        <?php endforeach ?>
      </div>
    </div>
  </section>

  <?php if (! $full): ?>
  <section class="card" id="add-outlet"><div class="card-h"><h3><?= icon('plus', 'sm') ?> Add an outlet</h3><small class="muted">Three quick steps — the business type switches on the features that business needs, with starter categories and products.</small></div>
    <div class="card-b">
      <form method="post" action="<?= esc(site_url('settings/outlets'), 'attr') ?>" class="out-wizard" data-outlet-wizard>
        <?= csrf_field() ?>
        <ol class="out-steps" aria-label="Steps">
          <li class="on" data-step-tab="1"><span>1</span>Business type</li>
          <li data-step-tab="2"><span>2</span>Details</li>
          <li data-step-tab="3"><span>3</span>Products &amp; team</li>
        </ol>

        <fieldset class="out-step" data-step="1"><legend class="field">Business type <?= field_error('type') ?></legend>
          <div class="biz-grid">
            <?php foreach ($templates as $id => $t): ?>
              <label class="tpl <?= $sel === $id ? 'on' : '' ?>" data-chip-check>
                <input type="radio" name="type" value="<?= esc($id, 'attr') ?>" class="out-radio" <?= checked($sel === $id) ?> required>
                <div class="tpl-top"><span class="biz-logo emo"><?= sym_icon($t['icon']) ?></span><div class="grow"><b><?= esc($t['name']) ?></b><br><small class="muted"><?= esc($t['bn']) ?></small></div></div>
                <p><?= esc($t['blurb']) ?></p>
              </label>
            <?php endforeach ?>
          </div>
          <div class="bo-actions out-nav"><button class="btn primary" type="button" data-step-go="2">Next<?= icon('chev', 'sm') ?></button></div>
        </fieldset>

        <fieldset class="out-step" data-step="2"><legend class="field">Outlet details</legend>
          <div class="form-grid">
            <label class="field">Outlet name *<input class="input" name="name" value="<?= old_or('name') ?>" required maxlength="160" placeholder="e.g. Digital Zone — Uttara"><?= field_error('name') ?></label>
            <label class="field">Phone<input class="input" name="phone" value="<?= old_or('phone') ?>" maxlength="32" inputmode="tel"></label>
            <label class="field">Area<input class="input" name="area" value="<?= old_or('area') ?>" maxlength="64"></label>
            <label class="field">City<input class="input" name="city" value="<?= old_or('city', 'Dhaka') ?>" maxlength="64"></label>
            <label class="field span2">Address<input class="input" name="address" value="<?= old_or('address') ?>" maxlength="255"></label>
          </div>
          <div class="bo-actions out-nav"><button class="btn" type="button" data-step-go="1"><?= icon('chevl', 'sm') ?>Back</button><button class="btn primary" type="button" data-step-go="3">Next<?= icon('chev', 'sm') ?></button></div>
        </fieldset>

        <fieldset class="out-step" data-step="3"><legend class="field">Products, team &amp; modules</legend>
          <div class="out-prod" data-out-prod>
            <b>Products for the new outlet</b>
            <label class="out-opt"><input type="radio" name="products" value="template" <?= checked($prodMode === 'template') ?>><span><b>Starter products</b><small class="muted">Sample categories and products from the business type — edit or delete them later.</small></span></label>
            <label class="out-opt" data-copy-opt><input type="radio" name="products" value="copy" <?= checked($prodMode === 'copy') ?>><span><b>Copy from one of my outlets</b><small class="muted">Pick the products and set their opening stock on the next screen. Only outlets of the same business type.</small>
              <select class="input out-copy-from" name="copy_from" aria-label="Outlet to copy products from">
                <option value="">Choose an outlet…</option>
                <?php foreach ($myOutlets as $o): ?><option value="<?= (int) $o['id'] ?>" data-type="<?= esc((string) $o['business_type'], 'attr') ?>" <?= (int) old('copy_from') === (int) $o['id'] ? 'selected' : '' ?>><?= esc($o['name']) ?> · <?= esc((string) $o['type_name']) ?></option><?php endforeach ?>
              </select>
              <small class="muted out-copy-none" hidden>None of your outlets has this business type yet.</small>
              <?= field_error('copy_from') ?></span></label>
            <label class="out-opt"><input type="radio" name="products" value="empty" <?= checked($prodMode === 'empty') ?>><span><b>Start empty</b><small class="muted">No products — add them yourself.</small></span></label>
          </div>
          <label class="check"><input type="checkbox" name="copy_team" value="1" checked>Give my team the same roles in the new outlet</label>
          <div class="out-rec">
            <b>Recommended modules for this business type</b>
            <?php foreach ($recommended as $type => $mods): ?>
              <div class="out-rec-list" data-rec-for="<?= esc($type, 'attr') ?>" <?= $sel === $type ? '' : 'hidden' ?>>
                <?php foreach ($mods as $m): ?><span class="tag <?= $m['on'] ? 'ok' : '' ?>"><?= $m['on'] ? icon('check', 'sm') : icon('plus', 'sm') ?> <?= esc($m['name']) ?><?= $m['on'] ? '' : ($trial ? ' · added for the trial' : ' · suggested') ?></span> <?php endforeach ?>
                <?= $mods === [] ? '<span class="muted">No recommendations for this type.</span>' : '' ?>
              </div>
            <?php endforeach ?>
            <small class="muted"><?= $trial ? 'During the free trial, recommended modules your plan does not include are added as add-ons — remove them any time under Subscription.' : 'Modules your plan does not include are only suggested — add them under Subscription › Modules.' ?></small>
          </div>
          <div class="bo-actions out-nav"><button class="btn" type="button" data-step-go="2"><?= icon('chevl', 'sm') ?>Back</button><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Create outlet</button></div>
        </fieldset>
      </form>
    </div>
  </section>
  <?php endif ?>
</div>

<?php foreach ($outletRows as $o): ?>
  <dialog class="modal-dlg" id="outlet-<?= (int) $o['id'] ?>"><form method="post" action="<?= esc(site_url('settings/outlets/' . (int) $o['id']), 'attr') ?>"><?= csrf_field() ?>
    <?= $dlgHead('Outlet details · ' . $o['name']) ?>
    <div class="modal-b">
      <label class="field">Outlet name *<input class="input" name="name" value="<?= esc($o['name'], 'attr') ?>" required maxlength="160"></label>
      <label class="field">Phone<input class="input" name="phone" value="<?= esc((string) $o['phone'], 'attr') ?>" maxlength="32" inputmode="tel"></label>
      <div class="form-grid">
        <label class="field">Area<input class="input" name="area" value="<?= esc((string) $o['area'], 'attr') ?>" maxlength="64"></label>
        <label class="field">City<input class="input" name="city" value="<?= esc((string) $o['city'], 'attr') ?>" maxlength="64"></label>
      </div>
      <label class="field">Address<input class="input" name="address" value="<?= esc((string) $o['address'], 'attr') ?>" maxlength="255"></label>
      <p class="muted out-note">Currency, VAT, receipts and features are set per outlet under Setup after switching to it.</p>
      <?php if ((bool) $o['is_active']): ?><a class="btn sm" href="<?= esc(site_url('settings/outlets/' . (int) $o['id'] . '/import'), 'attr') ?>"><?= icon('download', 'sm') ?>Import products from another outlet</a><?php endif ?>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save details</button></div>
  </form></dialog>
<?php endforeach ?>
<script src="<?= esc($asset('outlets.js'), 'attr') ?>" defer></script>
