<?php
/**
 * @var array<string, mixed>              $t     business_types row, setup decoded
 * @var array{tenants: int, outlets: int} $usage
 */
helper('ui');
use OmniPOS\Core\Services\BusinessTypeSetup as Setup;

$s = $t['setup'];
$f = (array) ($s['features'] ?? []);
$tax = (array) ($s['tax'] ?? []);
$hours = (array) ($s['hours'] ?? []);
$o = static fn (string $k, mixed $def): mixed => old($k) ?? $def;
$num = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$orderLines = implode("\n", array_map(static function (string $ot) use ($s, $num): string {
    $c = $s['typeCharges'][$ot] ?? null;

    return $ot . ($c ? ' = ' . $num($c['value']) . ($c['kind'] === 'percent' ? '%' : '') : '');
}, (array) ($f['orderTypes'] ?? [])));
$oldFeat = old('features');
$oldPay = old('payments');
$oldLabels = (array) (old('labels') ?? []);
?>
<div class="page">
  <form method="post" action="<?= esc(site_url('business-types/' . $t['code']), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3><?= sym_icon($t['icon']) ?> <?= esc($t['name']) ?></h3><small class="muted"><?= $usage['tenants'] ?> account<?= $usage['tenants'] === 1 ? '' : 's' ?> · <?= $usage['outlets'] ?> outlet<?= $usage['outlets'] === 1 ? '' : 's' ?> · words and offered features apply to every outlet at once; the other settings to new outlets</small></div><div class="card-b">
      <div class="form-grid">
        <label class="field">Name<input class="input" name="name" value="<?= old_or('name', $t['name']) ?>" required maxlength="80"><?= field_error('name') ?></label>
        <label class="field">Bangla name<input class="input" name="name_bn" value="<?= old_or('name_bn', $t['name_bn'] ?? '') ?>" maxlength="80" lang="bn"></label>
        <label class="field">Icon <small class="muted">icon key, e.g. store, pill, cake</small><input class="input" name="icon" value="<?= old_or('icon', $t['icon']) ?>" required maxlength="40" pattern="[a-z0-9-]{2,40}"><?= field_error('icon') ?></label>
        <label class="field">Sort order<input class="input" type="number" min="0" max="1000" name="sort_order" value="<?= old_or('sort_order', $t['sort_order']) ?>"></label>
        <label class="field">Method (storefront)<select class="select" name="store_kind"><?php foreach (Setup::STORE_KINDS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) $o('store_kind', $t['store_kind']), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select>
          <small class="muted">Shop sells products for delivery; Menu adds dine-in & kitchen; Bookings takes appointments / rooms only; Jobs & quotes takes job orders and quote requests.</small></label>
        <label class="field">Online requests<select class="select" name="booking_kind"><option value="">None</option><?php foreach (Setup::BOOKING_KINDS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) $o('booking_kind', $t['booking_kind'] ?? ''), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select>
          <small class="muted">Shown on the website and marketplace when the account has the Bookings module.</small><?= field_error('booking_kind') ?></label>
      </div>
      <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" <?= checked((bool) $o('is_active', $t['is_active'])) ?>>Offered to new customers at sign-up</label>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('sliders', 'sm') ?> POS features</h3><small class="muted">Switched on for a new outlet; the owner can change them later</small></div><div class="card-b">
      <div class="cat-chips">
        <?php foreach (Setup::FEATURES as [$k, $l, $help]): $on = is_array($oldFeat) ? in_array($k, $oldFeat, true) : ! empty($f[$k]); ?>
          <label class="chip" title="<?= esc($help, 'attr') ?>"><input type="checkbox" name="features[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?>> <?= esc($l) ?></label>
        <?php endforeach ?>
      </div>
      <fieldset class="field"><legend>Offered to outlets of this type <small class="muted">— the switches an owner may turn on in POS › Setup. Switched-on features above are always offered; the rest are hidden. Applies to existing outlets at once.</small></legend><div class="cat-chips">
        <?php $offered = Setup::offered((string) $t['code']); $oldOff = old('offered'); ?>
        <?php foreach (Setup::FEATURES as [$k, $l, $help]): $on = is_array($oldOff) ? in_array($k, $oldOff, true) : in_array($k, $offered, true); ?>
          <label class="chip" title="<?= esc($help . (isset(Setup::FEATURE_MODULE[$k]) ? ' · needs the ' . Setup::FEATURE_MODULE[$k] . ' module' : ''), 'attr') ?>"><input type="checkbox" name="offered[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?>> <?= esc($l) ?></label>
        <?php endforeach ?>
      </div></fieldset>
      <div class="form-grid">
        <label class="field">Minimum billed minutes <small class="muted">hourly rooms</small><input class="input" type="number" min="0" max="600" name="min_minutes" value="<?= old_or('min_minutes', (int) ($f['minMinutes'] ?? 0)) ?>"><?= field_error('min_minutes') ?></label>
        <label class="field">Express / urgent surcharge (%)<input class="input" type="number" min="0" max="500" step="any" name="express_rate" value="<?= old_or('express_rate', $num($f['expressRate'] ?? 50)) ?>"><?= field_error('express_rate') ?></label>
      </div>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('truck', 'sm') ?> Order types &amp; charges</h3></div><div class="card-b">
      <label class="field">One order type per line, with an optional charge: <code>Home Delivery = 70</code> (taka) or <code>Delivery = 5%</code>
        <textarea class="input" name="order_types" rows="5" maxlength="2000"><?= esc((string) $o('order_types', $orderLines)) ?></textarea><?= field_error('order_types') ?></label>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('receipt', 'sm') ?> VAT, payments &amp; receipt</h3></div><div class="card-b">
      <div class="form-grid">
        <label class="field">Tax label<input class="input" name="tax_label" maxlength="16" value="<?= old_or('tax_label', $tax['label'] ?? 'VAT') ?>"></label>
        <label class="field">VAT rate (%)<input class="input" type="number" min="0" max="100" step="any" name="tax_rate" value="<?= old_or('tax_rate', $num($tax['rate'] ?? 0)) ?>"><?= field_error('tax_rate') ?></label>
        <label class="field">Service charge (%)<input class="input" type="number" min="0" max="100" step="any" name="service_charge" value="<?= old_or('service_charge', $num($s['serviceCharge'] ?? 0)) ?>"><?= field_error('service_charge') ?></label>
        <label class="field">Round total to (৳, 0 = off)<input class="input" type="number" min="0" max="1000" step="any" name="round_to" value="<?= old_or('round_to', $num($s['roundTo'] ?? 0)) ?>"><?= field_error('round_to') ?></label>
        <label class="field">Low-stock alert at<input class="input" type="number" min="0" step="any" name="low_stock" value="<?= old_or('low_stock', $num($s['lowStock'] ?? 5)) ?>"><?= field_error('low_stock') ?></label>
        <label class="field">Receipt / invoice<select class="select" name="print_format"><?php foreach (Setup::PRINT_FORMATS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) $o('print_format', $s['printFormat'] ?? 'thermal'), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select><?= field_error('print_format') ?></label>
      </div>
      <label class="check"><input type="hidden" name="tax_inclusive" value="0"><input type="checkbox" name="tax_inclusive" value="1" <?= checked((bool) $o('tax_inclusive', ! empty($tax['inclusive']))) ?>>Prices include VAT (retail MRP); untick to add VAT on top (restaurants, services)</label>
      <fieldset class="field"><legend>Payment methods</legend><div class="cat-chips">
        <?php foreach (Setup::PAYMENTS as $k => $l): $on = is_array($oldPay) ? in_array($k, $oldPay, true) : in_array($k, (array) ($s['payments'] ?? []), true); ?>
          <label class="chip"><input type="checkbox" name="payments[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?>> <?= esc($l) ?></label>
        <?php endforeach ?>
      </div><?= field_error('payments') ?></fieldset>
      <label class="field">Terms printed on the receipt<textarea class="input" name="terms" rows="3" maxlength="600"><?= esc((string) $o('terms', $s['terms'] ?? '')) ?></textarea></label>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('palette', 'sm') ?> Look of the back office</h3><small class="muted">New outlets of this type start with it; empty = the business template's look</small></div><div class="card-b">
      <?php $theme = (array) ($s['theme'] ?? []); ?>
      <div class="form-grid">
        <label class="field">Sidebar<select class="select" name="theme_sidebar"><option value="">Template default</option><?php foreach (Setup::THEME_SIDEBARS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) $o('theme_sidebar', $theme['sidebar'] ?? ''), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
        <label class="field">Font<select class="select" name="theme_font"><option value="">Template default</option><?php foreach (Setup::THEME_FONTS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) $o('theme_font', $theme['font'] ?? ''), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
        <label class="field">Corner radius (px)<input class="input" type="number" min="0" max="24" name="theme_radius" value="<?= old_or('theme_radius', isset($theme['radius']) ? (string) (int) $theme['radius'] : '') ?>" placeholder="Template default"><?= field_error('theme_radius') ?></label>
      </div>
    </div></section>

    <section class="card"><div class="card-h"><h3><?= icon('tag', 'sm') ?> Words, groups &amp; hours</h3><small class="muted">Business words are used everywhere: POS menus, pages, till, receipts and the websites. Outlets may still use their own.</small></div><div class="card-b">
      <div class="form-grid">
        <?php $words = Setup::labels((string) $t['code']); ?>
        <?php foreach (Setup::LABELS as $k => $l): ?>
          <label class="field"><?= esc($l) ?><input class="input" name="labels[<?= esc($k, 'attr') ?>]" maxlength="40" value="<?= esc((string) ($oldLabels[$k] ?? $s['labels'][$k] ?? $words[$k]), 'attr') ?>"></label>
        <?php endforeach ?>
        <label class="field">Opens<input class="input" type="time" name="hours_open" value="<?= old_or('hours_open', $hours['open'] ?? '10:00') ?>"><?= field_error('hours_open') ?></label>
        <label class="field">Closes<input class="input" type="time" name="hours_close" value="<?= old_or('hours_close', $hours['close'] ?? '21:00') ?>"><?= field_error('hours_close') ?></label>
        <label class="field">Booking slot (minutes)<input class="input" type="number" min="5" max="240" name="hours_step" value="<?= old_or('hours_step', (int) ($hours['step'] ?? 30)) ?>"><?= field_error('hours_step') ?></label>
        <label class="field span2">Customer price groups <small class="muted">comma-separated</small><input class="input" name="price_groups" maxlength="400" value="<?= old_or('price_groups', implode(', ', (array) ($s['priceGroups'] ?? ['Retail']))) ?>"></label>
        <label class="field span2">Expense heads <small class="muted">comma-separated</small><textarea class="input" name="expense_cats" rows="2" maxlength="1200"><?= esc((string) $o('expense_cats', implode(', ', (array) ($s['expenseCats'] ?? [])))) ?></textarea></label>
      </div>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save business type</button><a class="btn" href="<?= esc(site_url('business-types'), 'attr') ?>">Cancel</a></div>
    </div></section>
  </form>
  <form method="post" action="<?= esc(site_url('business-types/' . $t['code'] . '/reset'), 'attr') ?>" data-confirm="Put <?= esc($t['name'], 'attr') ?> back to the Bangladesh default setup? Name, method and offered status are kept."><?= csrf_field() ?>
    <div class="row-add"><button class="btn sm" type="submit"><?= icon('refresh', 'sm') ?>Reset setup to Bangladesh defaults</button></div>
  </form>
</div>
