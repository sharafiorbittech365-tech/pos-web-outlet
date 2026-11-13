<?php
/**
 * Business editor (design build's Setup › Edit business): general, location, money & tax,
 * invoice settings, features & lists. One form; tabs are only a view.
 *
 * @var array<string, mixed>  $b
 * @var list<array>           $features
 * @var array<string, string> $payments
 * @var array<string, string> $formats
 * @var array<string, string> $labels
 * @var array<string, string> $typeWords the business type's words (placeholders)
 * @var list<string>          $icons
 * @var string                $sample
 * @var string                $typeCharges
 * @var array                 $outlets
 */
helper('ui');
$s = $b['settings'];
$f = (array) ($s['features'] ?? []);
$inv = (array) ($s['invoice'] ?? []);
$num = (array) ($inv['num'] ?? []);
$hours = (array) ($s['hours'] ?? []);
$o = static fn (string $k, mixed $v = '') => old_or($k, $v);
$listTxt = static fn (mixed $v): string => esc(implode(', ', array_map(static fn ($x): string => is_array($x) ? (string) ($x['name'] ?? '') : (string) $x, (array) $v)), 'attr');
$swatches = ['#1E6FE8', '#4F46E5', '#7C3AED', '#DB2777', '#DC2626', '#EA580C', '#B45309', '#3F7D20', '#0F766E', '#0F172A'];
$primary = (string) ($s['theme']['primary'] ?? '#4F46E5');
$br = $web['brand'] ?? [];
$soc = (array) ($br['social'] ?? []);
$pol = (array) ($br['policy'] ?? []);
$webImg = static function (string $slot, string $label, string $hint, string $cur) use ($o): string {
    $h = '<div class="field web-img"><span>' . esc($label) . '</span>';
    if ($cur !== '') {
        $h .= '<div class="web-img-cur"><img src="' . esc($cur, 'attr') . '" alt="" loading="lazy"><label class="check"><input type="checkbox" name="web_' . $slot . '_remove" value="1">Remove</label></div>';
    }
    $h .= '<input class="input" type="file" name="web_' . $slot . '_file" accept="image/png,image/jpeg,image/webp,image/gif' . ($slot === 'favicon' ? ',image/x-icon' : '') . '">';
    $h .= '<input class="input" name="web_' . $slot . '_url" value="' . esc((string) old('web_' . $slot . '_url'), 'attr') . '" placeholder="…or paste an https:// image link" maxlength="300">';
    $h .= '<small class="muted">' . esc($hint) . '</small>' . field_error('web_' . $slot . '_file') . field_error('web_' . $slot . '_url') . '</div>';

    return $h;
};
?>
<div class="page">
  <div class="page-h">
    <div><h2>Setup</h2><p class="page-sub"><?= esc($b['name']) ?> · <?= esc($b['type_name']) ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('settings/outlets'), 'attr') ?>"><?= icon('store', 'sm') ?>Outlets <span class="tag"><?= (int) $outlets['count'] ?> / <?= $outlets['limit'] > 0 ? (int) $outlets['limit'] : '∞' ?></span></a>
    <?php if (service('posSession')->can('users.manage')): ?><a class="btn" href="<?= esc(site_url('users'), 'attr') ?>"><?= icon('shield', 'sm') ?>Users &amp; roles</a><?php endif ?>
    <?php if (service('posSession')->can('billing.manage')): ?><a class="btn" href="<?= esc(site_url('subscription'), 'attr') ?>"><?= icon('star', 'sm') ?>Subscription</a><?php endif ?>
  </div>

  <form class="card bo-form" method="post" action="<?= esc(site_url('settings'), 'attr') ?>" data-tabs="general" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="_tab" value="general">
    <div class="card-b">
      <div class="tabs">
        <button type="button" data-tab="general">General</button>
        <button type="button" data-tab="location">Location &amp; delivery</button>
        <button type="button" data-tab="money">Currency, tax &amp; stock</button>
        <button type="button" data-tab="invoice">Invoice settings</button>
        <button type="button" data-tab="printing">Printing</button>
        <?php if (service('posSession')->can('pos.sell')): ?><button type="button" data-tab="pos">POS</button><?php endif ?>
        <?php if (service('posSession')->moduleOn('purchases')): ?><button type="button" data-tab="purchasing">Purchasing</button><?php endif ?>
        <button type="button" data-tab="email">Email (SMTP)</button>
        <button type="button" data-tab="features">Features</button>
        <?php if ($web !== null): ?><button type="button" data-tab="website">Website</button><?php endif ?>
      </div>

      <div class="pane" data-pane="general">
        <div class="form-grid">
          <label class="field">Business name *<input class="input" name="name" value="<?= $o('name', $b['name']) ?>" required maxlength="160"><?= field_error('name') ?></label>
          <label class="field">Branch / location<input class="input" name="branch" value="<?= $o('branch', $s['branch'] ?? '') ?>" maxlength="80"></label>
          <label class="field span2">Address (on invoice)<input class="input" name="address" value="<?= $o('address', $b['address']) ?>" maxlength="255"></label>
          <label class="field">Phone<input class="input" name="phone" value="<?= $o('phone', $b['phone']) ?>" maxlength="32"></label>
          <label class="field">Email<input class="input" type="email" name="email" value="<?= $o('email', $b['email']) ?>" maxlength="160"><?= field_error('email') ?></label>
          <label class="field">BIN / VAT registration<input class="input" name="bin" value="<?= $o('bin', $s['bin'] ?? '') ?>" maxlength="40"></label>
          <label class="field">Logo symbol<select class="select" name="logo_icon"><?php foreach ($icons as $ic): ?><option value="<?= esc($ic, 'attr') ?>" <?= selected($s['logoIcon'] ?? $b['type_icon'], $ic) ?>><?= esc(str_replace('-', ' ', $ic)) ?></option><?php endforeach ?></select></label>
          <?php if ($web !== null): ?><div class="field span2 logo-sync"><span>Logo</span><div class="web-img-cur"><?= biz_logo(null, 'lg') ?><small class="muted"><?= ! empty($br['logo']) ? 'Your website logo is used here, on the till and in the online shop.' : 'No website logo yet — the symbol above is used. Upload a logo in the Website tab and it replaces the symbol everywhere (admin, till and online shop).' ?> <button class="link" type="button" data-tab="website">Open Website tab</button></small></div></div><?php endif ?>
          <div class="field span2">Brand colour<div class="swatches">
            <?php foreach ($swatches as $c): ?><button type="button" class="sw <?= strcasecmp($primary, $c) === 0 ? 'on' : '' ?>" style="background:<?= css_color($c) ?>" data-set="theme_primary" data-v="<?= esc($c, 'attr') ?>" aria-label="<?= esc($c, 'attr') ?>"></button><?php endforeach ?>
            <input type="color" class="sw" name="theme_primary" value="<?= esc($primary, 'attr') ?>" title="Custom colour">
          </div></div>
          <?php foreach ($labels as $k => $l): ?>
            <label class="field"><?= esc($l) ?><input class="input" name="labels[<?= esc($k, 'attr') ?>]" value="<?= esc((string) ($s['labels'][$k] ?? ''), 'attr') ?>" placeholder="<?= esc($typeWords[$k] ?? '', 'attr') ?>" maxlength="40"></label>
          <?php endforeach ?>
        </div>
      </div>

      <div class="pane" data-pane="location" hidden>
        <div class="form-grid">
          <div class="field span2 biz-loc"><span><?= icon('map-pin', 'sm') ?> Shop location <small class="muted">— used for delivery distance and to show your shop to nearby customers on the Marketplace</small></span></div>
          <label class="field">Area<input class="input" name="area" value="<?= $o('area', $b['area']) ?>" maxlength="64" placeholder="e.g. Gulshan 2"></label>
          <label class="field">City<input class="input" name="city" value="<?= $o('city', $b['city']) ?>" maxlength="64" placeholder="Dhaka"></label>
          <label class="field">Latitude<input class="input" name="lat" value="<?= $o('lat', $b['lat']) ?>" inputmode="decimal" placeholder="23.7925"><?= field_error('lat') ?></label>
          <label class="field">Longitude<input class="input" name="lng" value="<?= $o('lng', $b['lng']) ?>" inputmode="decimal" placeholder="90.4148"></label>
          <label class="field">Delivers within (km, 0 = no limit)<input class="input" type="number" min="0" max="500" step="0.1" name="delivery_km" value="<?= $o('delivery_km', $b['delivery_km'] ?? 0) ?>"></label>
          <div class="field"><span>&nbsp;</span><button class="btn" type="button" data-geo><?= icon('locate-fixed', 'sm') ?>Use this device's location</button></div>
          <label class="field">Opens at<input class="input" type="time" name="hours_open" value="<?= esc((string) ($hours['open'] ?? '10:00'), 'attr') ?>"></label>
          <label class="field">Closes at<input class="input" type="time" name="hours_close" value="<?= esc((string) ($hours['close'] ?? '21:00'), 'attr') ?>"></label>
          <label class="field">Booking slot (minutes)<input class="input" type="number" min="10" max="240" name="hours_step" value="<?= (int) ($hours['step'] ?? 30) ?>"></label>
        </div>
      </div>

      <div class="pane" data-pane="money" hidden>
        <div class="form-grid">
          <label class="field">Currency symbol<input class="input" name="currency_symbol" value="<?= $o('currency_symbol', $b['currency_symbol']) ?>" maxlength="8"></label>
          <label class="field">Currency code<input class="input" name="currency_code" value="<?= $o('currency_code', $b['currency_code']) ?>" maxlength="3"><?= field_error('currency_code') ?></label>
          <label class="field">Decimals<input class="input" type="number" min="0" max="3" name="currency_decimals" value="<?= $o('currency_decimals', $b['currency_decimals']) ?>"></label>
          <label class="field">Tax label<input class="input" name="tax_label" value="<?= $o('tax_label', $b['tax_label']) ?>" maxlength="16"></label>
          <label class="field">Default tax rate %<input class="input" type="number" step="any" min="0" max="100" name="tax_rate" value="<?= $o('tax_rate', $b['tax_rate']) ?>"><?= field_error('tax_rate') ?></label>
          <label class="field">Other tax rates (for products)<input class="input" name="tax_rates" value="<?= $o('tax_rates', $b['tax_rates']) ?>" placeholder="0, 5, 7.5, 10, 15"><?= field_error('tax_rates') ?></label>
          <label class="check" style="align-self:end;height:40px"><input type="hidden" name="tax_inclusive" value="0"><input type="checkbox" name="tax_inclusive" value="1" <?= checked(old('tax_inclusive') ?? $b['tax_inclusive']) ?>>Prices include tax (default — each product can override)</label>
          <label class="field">Service charge %<input class="input" type="number" step="any" min="0" max="100" name="service_charge_pct" value="<?= $o('service_charge_pct', $b['service_charge_pct']) ?>"></label>
          <label class="field">Round total to (0 = off)<input class="input" type="number" step="any" min="0" name="round_to" value="<?= $o('round_to', $b['round_to']) ?>"></label>
          <label class="field">Low-stock alert at<input class="input" type="number" step="any" min="0" name="low_stock_alert" value="<?= $o('low_stock_alert', $b['low_stock_alert']) ?>"></label>
          <label class="check" style="align-self:end;height:40px"><input type="hidden" name="stock_enabled" value="0"><input type="checkbox" name="stock_enabled" value="1" <?= checked(old('stock_enabled') ?? $b['stock_enabled']) ?>>Stock management ON (sales reduce stock)</label>
          <div class="field span2">Payment methods <?= field_error('payments') ?><div class="cat-chips">
            <?php foreach ($payments as $k => $l): $on = in_array($k, (array) ($s['payments'] ?? []), true); ?>
              <label class="chip <?= $on ? 'on' : '' ?>" data-chip-check><input type="checkbox" hidden name="payments[]" value="<?= esc($k, 'attr') ?>" <?= checked($on) ?>><?= esc($l) ?></label>
            <?php endforeach ?>
          </div></div>
        </div>
      </div>

      <div class="pane" data-pane="invoice" hidden>
        <div class="form-grid">
          <label class="field">Invoice number prefix<input class="input" name="invoice_prefix" value="<?= $o('invoice_prefix', $inv['prefix'] ?? '') ?>" maxlength="12" placeholder="e.g. DZ-"><?= field_error('invoice_prefix') ?></label>
          <label class="field">Pad number to digits (0 = off)<input class="input" type="number" min="0" max="8" name="invoice_pad" value="<?= $o('invoice_pad', $num['pad'] ?? 0) ?>"></label>
          <label class="check" style="align-self:end;height:40px"><input type="hidden" name="invoice_year" value="0"><input type="checkbox" name="invoice_year" value="1" <?= checked(old('invoice_year') ?? ! empty($num['year'])) ?>>Include the year (e.g. DZ-2026-00042)</label>
          <div class="due-box"><small>Next invoices look like</small><b><?= esc($sample) ?></b></div>
          <div class="field span2" style="display:block"><small class="muted">Paper, copies and the printer for each document are set in the <button class="link" type="button" data-tab="printing">Printing</button> tab.</small></div>
          <label class="field span2">Invoice terms / footer<textarea class="input" name="terms" rows="4" maxlength="2000"><?= esc((string) (old('terms') ?? $s['terms'] ?? '')) ?></textarea></label>
        </div>
      </div>

      <div class="pane" data-pane="printing" hidden>
        <?php $prints = \App\Modules\Orders\Services\PrintSettings::all(['settings' => $s]); $pr = \App\Modules\Orders\Services\PrintSettings::printer(['settings' => $s]); ?>
        <input type="hidden" name="printer_present" value="1">
        <div class="form-grid">
          <div class="field span2" style="display:block"><b style="display:block;font-size:15px">Printer — the default for every device</b>
            <small class="muted">Set once here. The till, orders, invoices, kitchen and every phone, tab and laptop print this way until a device changes it from its own <button class="link" type="button" data-print-setup>Printer setup</button> button (it can go back to this default any time). A Bluetooth printer is paired on each device.</small></div>
          <label class="field">How receipts print<select class="select" name="printer_mode"><?php foreach (\App\Modules\Orders\Services\PrintSettings::MODES as $k => $l): ?><option value="<?= $k ?>" <?= selected(old('printer_mode') ?? $pr['mode'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="field">Receipt roll<select class="select" name="printer_paper"><option value="" <?= selected(old('printer_paper') ?? $pr['paper'], '') ?>>As set per document below</option><option value="58" <?= selected(old('printer_paper') ?? $pr['paper'], '58') ?>>58 mm roll</option><option value="80" <?= selected(old('printer_paper') ?? $pr['paper'], '80') ?>>80 mm roll</option></select></label>
          <label class="check"><input type="hidden" name="printer_auto" value="0"><input type="checkbox" name="printer_auto" value="1" <?= checked(old('printer_auto') ?? $pr['auto']) ?>>Print the receipt after every sale</label>
          <label class="check"><input type="hidden" name="printer_kot" value="0"><input type="checkbox" name="printer_kot" value="1" <?= checked(old('printer_kot') ?? $pr['kot']) ?>>Also print the kitchen ticket (kitchen orders)</label>
          <div class="field span2" style="display:block"><b style="display:block;font-size:15px">What prints, and on which paper</b><small class="muted">Paper and copies for each document. Print buttons, "print after every sale" and PDF downloads follow this unless you pick another size on the spot. Invoice PDFs are always A4.</small>
            <div class="pr-docs">
              <?php foreach (\App\Modules\Orders\Services\PrintSettings::DOCS as $dk => $dd): if (isset($dd['module']) && ! service('posSession')->moduleOn($dd['module'])) { continue; } ?>
                <div class="pr-doc"><b><?= esc($dd['label']) ?></b>
                  <select class="select" name="print_<?= $dk ?>_size" aria-label="<?= esc($dd['label'], 'attr') ?> paper"><?php foreach ($dd['sizes'] as $sz): ?><option value="<?= $sz ?>" <?= selected(old('print_' . $dk . '_size') ?? $prints[$dk]['size'], $sz) ?>><?= esc(\App\Modules\Orders\Services\PrintSettings::SIZES[$sz]) ?></option><?php endforeach ?></select>
                  <label class="pr-copies">Copies<input class="input" type="number" min="1" max="<?= \App\Modules\Orders\Services\PrintSettings::MAX_COPIES ?>" name="print_<?= $dk ?>_copies" value="<?= esc((string) (old('print_' . $dk . '_copies') ?? $prints[$dk]['copies']), 'attr') ?>"></label>
                </div>
              <?php endforeach ?>
            </div>
          </div>
        </div>
      </div>

      <?php if (service('posSession')->can('pos.sell')): $ps = \App\Modules\Sales\Services\PosSettings::all(['settings' => $s, 'id' => $b['id']]); ?>
      <div class="pane" data-pane="pos" hidden>
        <input type="hidden" name="pos_present" value="1">
        <div class="form-grid">
          <div class="field span2" style="display:block"><b style="display:block;font-size:15px">POS — what the till allows</b>
            <small class="muted">A switch that is off removes the option from the till for everyone. When it is on, only people whose role allows it (Users &amp; roles) get the option.</small></div>
          <?php foreach (\App\Modules\Sales\Services\PosSettings::SWITCHES as $k => [$l, $help]): ?>
            <label class="feat span2"><input type="hidden" name="pos_<?= $k ?>" value="0"><input type="checkbox" name="pos_<?= $k ?>" value="1" <?= checked(old('pos_' . $k) ?? $ps[$k]) ?>><span><b><?= $l ?></b><small><?= esc($help) ?></small></span></label>
          <?php endforeach ?>
          <?php $pr = (int) (old('pos_per_row') ?? \App\Modules\Sales\Services\PosSettings::perRow(['settings' => $s, 'id' => $b['id']])); ?>
          <div class="field span2 pr-wrap" style="display:block"><b style="display:block;font-size:15px">Products per row</b>
            <small class="muted">How many product cards the till shows in each row. Phones always cap at 3 and tablets at 4 so the cards stay tappable.</small>
            <div class="pr-opts" role="radiogroup" aria-label="Products per row">
              <?php foreach (\App\Modules\Sales\Services\PosSettings::PER_ROW as $n): ?>
                <label class="pr-opt"><input type="radio" name="pos_per_row" value="<?= $n ?>" <?= $pr === $n ? 'checked' : '' ?>><span><b><?= $n === 0 ? 'Auto' : $n ?></b><small><?= $n === 0 ? 'fit' : 'per row' ?></small></span></label>
              <?php endforeach ?>
            </div>
            <div class="pr-prev" id="pr-prev" data-cols="<?= $pr ?>" aria-hidden="true"><?php for ($i = 0; $i < 12; $i++): ?><i><em></em><u></u><s></s></i><?php endfor ?></div>
            <script>(function(){var g=document.getElementById('pr-prev');if(!g)return;document.querySelectorAll('input[name=pos_per_row]').forEach(function(r){r.addEventListener('change',function(){g.setAttribute('data-cols',r.value);});});})();</script>
          </div>
        </div>
      </div>
      <?php endif ?>

      <?php if (service('posSession')->moduleOn('purchases')): $pur = \App\Modules\Purchases\Services\PurchaseSettings::all(['settings' => $s, 'id' => $b['id']]); ?>
      <div class="pane" data-pane="purchasing" hidden>
        <input type="hidden" name="purchasing_present" value="1">
        <div class="form-grid">
          <div class="field span2" style="display:block"><b style="display:block;font-size:15px">Suppliers &amp; purchases — what is switched on</b>
            <small class="muted">Every page, button and stock effect of the module follows these switches. A switch that is off hides its pages and does nothing.</small></div>
          <?php foreach (\App\Modules\Purchases\Services\PurchaseSettings::SWITCHES as $k => [$l, $help]): ?>
            <label class="feat span2"><input type="hidden" name="pur_<?= $k ?>" value="0"><input type="checkbox" name="pur_<?= $k ?>" value="1" <?= checked(old('pur_' . $k) ?? $pur[$k]) ?>><span><b><?= esc($l) ?></b><small><?= esc($help) ?></small></span></label>
          <?php endforeach ?>
          <div class="field span2" style="display:block"><b style="display:block;font-size:15px">With Inventory</b>
            <small class="muted">Stock management is switched in <button class="link" type="button" data-tab="money">Currency, tax &amp; stock</button> (now <b><?= ! empty($b['stock_enabled']) ? 'ON' : 'OFF' ?></b>).</small>
            <table class="tbl compact" style="margin-top:8px"><thead><tr><th>Purchase</th><th>Inventory</th><th>What happens</th></tr></thead><tbody>
              <tr><td>ON</td><td>ON</td><td>Receiving a purchase raises stock automatically</td></tr>
              <tr><td>OFF</td><td>ON</td><td>Stock is entered by hand (Inventory page)</td></tr>
              <tr><td>ON</td><td>OFF</td><td>Purchases and supplier dues run; stock is not updated</td></tr>
              <tr><td>OFF</td><td>OFF</td><td>Purchases and stock are both off</td></tr>
            </tbody></table>
            <small class="muted" style="display:block;margin-top:6px">Right now: <?= esc(\App\Modules\Purchases\Services\PurchaseSettings::behaviour(['settings' => $s, 'id' => $b['id'], 'stock_enabled' => $b['stock_enabled']])) ?></small></div>
        </div>
      </div>
      <?php endif ?>

      <div class="pane" data-pane="email" hidden>
        <?php $sm = \App\Modules\Settings\Services\SmtpSettings::forForm($b); $smOn = \App\Modules\Settings\Services\SmtpSettings::configured($b); ?>
        <input type="hidden" name="smtp_present" value="1">
        <div class="due-box" style="margin-bottom:12px"><small>Email sending</small><b><?= $smOn ? 'Connected — the Email button shows on invoices' : 'Not set up — the Email button stays hidden' ?></b></div>
        <div class="form-grid">
          <label class="field">SMTP host<input class="input" name="smtp_host" value="<?= $o('smtp_host', $sm['host']) ?>" maxlength="190" placeholder="smtp.gmail.com" autocomplete="off"></label>
          <label class="field">Port<input class="input" type="number" min="1" max="65535" name="smtp_port" value="<?= $o('smtp_port', $sm['port']) ?>"></label>
          <label class="field">Security<select class="select" name="smtp_security"><?php foreach (\App\Modules\Settings\Services\SmtpSettings::SECURITY as $k => $l): ?><option value="<?= $k ?>" <?= selected(old('smtp_security') ?? $sm['security'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="field">Username<input class="input" name="smtp_user" value="<?= $o('smtp_user', $sm['user']) ?>" maxlength="190" autocomplete="off" placeholder="you@example.com"></label>
          <label class="field">Password<input class="input" type="password" name="smtp_pass" value="" maxlength="190" autocomplete="new-password" placeholder="<?= $sm['has_pass'] ? '•••••••• (saved — leave empty to keep)' : 'App password' ?>"></label>
          <label class="field">From email<input class="input" type="email" name="smtp_from_email" value="<?= $o('smtp_from_email', $sm['from_email']) ?>" maxlength="160" placeholder="billing@yourshop.com"></label>
          <label class="field">From name<input class="input" name="smtp_from_name" value="<?= $o('smtp_from_name', $sm['from_name']) ?>" maxlength="120" placeholder="<?= esc((string) $b['name'], 'attr') ?>"></label>
          <label class="field">Reply-to (optional)<input class="input" type="email" name="smtp_reply_to" value="<?= $o('smtp_reply_to', $sm['reply_to']) ?>" maxlength="160"></label>
          <div class="field span2" style="display:block"><small class="muted">Gmail: smtp.gmail.com, port 587 STARTTLS, use an App password. Save first, then send a test to check the connection.</small>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;align-items:center">
              <input class="input" type="email" name="smtp_test_to" placeholder="Send a test to…" maxlength="160" style="max-width:260px">
              <button class="btn" type="submit" formaction="<?= esc(site_url('settings/smtp-test'), 'attr') ?>" formnovalidate><?= icon('send', 'sm') ?>Send test email</button>
            </div></div>
        </div>
      </div>

      <div class="pane" data-pane="features" hidden>
        <div class="feat-grid">
          <?php foreach ($features as [$k, $l, $help]): ?>
            <label class="feat"><input type="checkbox" name="features[<?= esc($k, 'attr') ?>]" value="1" <?= checked(! empty($f[$k])) ?>><span><b><?= esc($l) ?></b><small><?= esc($help) ?></small></span></label>
          <?php endforeach ?>
        </div>
        <div class="form-grid">
          <label class="field span2">Types of service (comma separated, empty = none)<input class="input" name="order_types" value="<?= $listTxt($f['orderTypes'] ?? []) ?>"></label>
          <label class="field span2">Charge per type (e.g. Take Away=20, Delivery=5%)<input class="input" name="type_charges" value="<?= esc($typeCharges, 'attr') ?>"></label>
          <label class="field span2">Price groups (first = default retail)<input class="input" name="price_groups" value="<?= $listTxt($s['priceGroups'] ?? ['Retail']) ?>"></label>
          <label class="field span2">Expense categories<input class="input" name="expense_cats" value="<?= $listTxt($s['expenseCats'] ?? ['Other']) ?>"></label>
          <label class="field span2">Service staff / salespeople (order-level)<input class="input" name="service_staff" value="<?= $listTxt($s['serviceStaff'] ?? []) ?>"></label>
          <label class="field span2">Staff per line (stylists etc.)<input class="input" name="staff" value="<?= $listTxt($s['staff'] ?? []) ?>"></label>
          <label class="field">Minimum billable minutes (hourly)<input class="input" type="number" min="0" name="min_minutes" value="<?= (int) ($f['minMinutes'] ?? 0) ?>"></label>
          <label class="field">Express surcharge %<input class="input" type="number" min="0" max="500" name="express_rate" value="<?= esc((string) ($f['expressRate'] ?? 50), 'attr') ?>"></label>
        </div>
        <p class="muted bo-note">Tables / rooms are edited on the floor page. Modules (website, loyalty, bookings …) are switched on under Subscription.</p>
      </div>

      <?php if ($web !== null): ?>
      <div class="pane" data-pane="website" hidden>
        <input type="hidden" name="web_present" value="1">
        <p class="muted bo-note">What customers see on your online shop at <b><?= esc((new \App\Modules\Website\Services\WebsiteService())->host($web)) ?></b>. Theme, colours, home-page sections, checkout, domains and API are on the <a class="link" href="<?= esc(site_url('website'), 'attr') ?>">Website page</a>.</p>
        <div class="form-grid">
          <?= $webImg('logo', 'Logo', 'Square or wide PNG/JPG/WebP, up to 900 KB. Shown in the shop header and footer.', (string) ($br['logo'] ?? '')) ?>
          <?= $webImg('favicon', 'Browser icon (favicon)', 'Small square image, 64×64 or larger. Shown in the browser tab.', (string) ($br['favicon'] ?? '')) ?>
          <?= $webImg('hero', 'Banner / cover image', 'Wide photo (about 1600×700) for the home page and link previews.', (string) ($br['heroImg'] ?? '')) ?>
          <label class="field span2">Tagline<input class="input" name="web_tagline" value="<?= $o('web_tagline', $br['sub'] ?? '') ?>" maxlength="200" placeholder="One line under your shop name"></label>
          <label class="field span2">About your shop<textarea class="input" name="web_about" rows="4" maxlength="1500" placeholder="Who you are and what makes the shop special — shown on the About page"><?= esc((string) (old('web_about') ?? ($br['about'] ?? ''))) ?></textarea></label>

          <div class="field span2"><b>Contact</b><small class="muted">Phone, address and location come from the General and Location tabs.</small></div>
          <label class="field">WhatsApp number<input class="input" name="web_whatsapp" inputmode="tel" value="<?= $o('web_whatsapp', $br['whatsapp'] ?? '') ?>" maxlength="20" placeholder="8801XXXXXXXXX"><small class="muted">With country code. Adds a chat button to the shop.</small></label>
          <label class="field">Contact email<input class="input" type="email" name="web_email" value="<?= $o('web_email', $br['contactEmail'] ?? ($b['email'] ?? '')) ?>" maxlength="160"><?= field_error('web_email') ?></label>
          <label class="field">Opening hours (text)<input class="input" name="web_hours" value="<?= $o('web_hours', $br['hoursText'] ?? '') ?>" maxlength="120" placeholder="Sat–Thu 10 AM – 9 PM · Fri closed"></label>
          <label class="field">Map link<input class="input" name="web_map" value="<?= $o('web_map', $br['mapUrl'] ?? '') ?>" maxlength="300" placeholder="https://maps.google.com/…"><?= field_error('web_map') ?><small class="muted">Empty = a map link is built from your shop location.</small></label>

          <div class="field span2"><b>Social media</b><small class="muted">Only the ones you fill in appear on the shop.</small></div>
          <?php foreach (\App\Modules\Website\Services\WebsiteService::SOCIALS as $k => $l): ?>
            <label class="field"><?= esc($l) ?><input class="input" name="web_social_<?= $k ?>" value="<?= $o('web_social_' . $k, $soc[$k] ?? '') ?>" maxlength="300" placeholder="https://…"><?= field_error('web_social_' . $k) ?></label>
          <?php endforeach ?>

          <label class="field span2">Footer line<input class="input" name="web_footer" value="<?= $o('web_footer', $br['footer'] ?? '') ?>" maxlength="160" placeholder="Shown at the very bottom, e.g. © 2026 Your Shop · All rights reserved"></label>

          <div class="field span2"><b>Delivery &amp; returns, Privacy policy, Terms &amp; conditions, About us</b><small class="muted">These pages are now written in <a class="link" href="<?= esc(site_url('website') . '?tab=pages', 'attr') ?>">Website › Pages</a> — with their own editor, address and footer link.</small></div>
        </div>
      </div>
      <?php endif ?>

      <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save changes</button></div>
    </div>
  </form>
</div>
