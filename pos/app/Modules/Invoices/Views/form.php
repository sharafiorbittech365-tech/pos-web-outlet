<?php
/**
 * Invoice editor (new / draft / issued).
 *
 * LEFT: the invoice itself, editable in place (WYSIWYG): bill-to, dates, title, every line
 * (details, qty, unit, price, discount, VAT), invoice discount, notes, terms, footer line and
 * RIGHT: the same fields as a plain form (the accessible fallback). Both stay in sync
 * through assets/pos/invoices.js: the form fields are the one store, the document shows and edits
 * them. Totals are calculated in the browser with OrderCalculator's rules and corrected by the
 * server (GET invoices/quote); saving always re-prices on the server.
 *
 * Prices (invoices.edit_price only): per-line custom price with the catalogue price struck through
 * and "Reset to catalogue price", bulk "Adjust prices" (GET invoices/adjust), VAT incl. / excl.
 *
 * @var array<string, mixed>       $inv
 * @var list<array<string, mixed>> $lines    editor lines (InvoiceService::editorLines; catalog = catalogue price)
 * @var bool                       $canPrice invoices.edit_price
 * @var bool                       $canSettings invoices.settings
 * @var array<string, string>      $vat      VAT choices (the outlet's own rates only)
 * @var list<string>               $methods
 * @var list<array<string, string>> $tiles
 * @var ?string                    $nextNo
 * @var array{type: string, value: float} $discount
 * @var array<string, mixed>       $cur
 * @var string                     $code
 * @var array<string, mixed>       $business
 * @var array<string, mixed>       $pageCfg
 * @var array<string, ?string>     $images
 * @var string                     $invoiceDate
 * @var bool                       $canBackdate
 * @var array<string, mixed>       $calc
 * @var array<string, string>      $errors   field errors of the last save
 */
helper(['ui', 'pos', 'invoice']);
$id = (int) $inv['id'];
$issued = $inv['status'] === 'issued';
$action = $id > 0 ? site_url('invoices/' . $id) : site_url('invoices');
$o = static fn (string $k, $v): string => esc((string) (old($k) ?? $v ?? ''), 'attr');
$b = $business;
$pg = array_merge($pageCfg, array_intersect_key((array) ($inv['data']['page'] ?? []), array_flip(['title', 'footer_text', 'sign_name', 'sign_title'])));
$accent = $pageCfg['accent'] !== '' ? $pageCfg['accent'] : (string) ($b['settings']['theme']['primary'] ?? '#1E6FE8');
$vatVal = static function (array $l): string {
    if (! isset($l['tax_mode']) || $l['tax_mode'] === '' || $l['tax_mode'] === 'default') {
        return '';
    }
    if ($l['tax_mode'] === 'exempt') {
        return 'exempt';
    }

    return $l['tax_mode'] . '|' . ($l['tax_rate'] !== null ? rate_str($l['tax_rate']) : '');
};
$vatSelect = static function (string $attr, string $val) use ($vat): string {
    $h = '<select class="select inv-vat" ' . $attr . '>';
    foreach ($vat as $k => $l) {
        $h .= '<option value="' . esc($k, 'attr') . '"' . ($k === $val ? ' selected' : '') . '>' . esc($l) . '</option>';
    }

    return $h . '</select>';
};
$discSelect = static fn (string $attr, string $val, string $cls = 'select ivx-dtype'): string => '<select class="' . $cls . '" ' . $attr . '><option value="percent"' . ($val !== 'fixed' ? ' selected' : '') . '>%</option><option value="fixed"' . ($val === 'fixed' ? ' selected' : '') . '>' . esc($cur['symbol']) . '</option></select>';
$F = static fn ($n): string => esc(money($n, $cur));
$num = static fn ($v): string => $v === null || $v === '' ? '' : esc((string) (float) $v, 'attr');
$payNow = (string) (old('pay_method') ?? ($tiles[0]['code'] ?? 'cash'));
$err = static fn (string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$lineErr = static function (int $n, string $f) use ($errors): string {
    return isset($errors['lines.' . $n . '.' . $f]) ? ' is-invalid' : '';
};
$ro = $issued ? 'readonly' : '';
$template = (string) ($pageCfg['template'] ?? 'modern');
$nbr = (bool) ($nbr ?? false);
$mushakCfg = (array) ($mushakCfg ?? []);
?>
<?= view('App\Modules\Invoices\Views\docs\_ivd_style') ?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/invoices.css') . '?v=' . (is_file(FCPATH . 'assets/pos/invoices.css') ? filemtime(FCPATH . 'assets/pos/invoices.css') : 0), 'attr') ?>">
<div class="page ivx" id="ivx" style="--ia:<?= css_color($accent) ?>">
  <?php if ($errors): ?><div class="sbanner warn" role="alert"><?= icon('alert', 'sm') ?><span>Please check the highlighted fields — your lines are kept.</span></div><?php endif ?>

  <div class="ivx-tabs" role="tablist" aria-label="Invoice editor">
    <button type="button" role="tab" data-view="form" id="ivx-tab-form" aria-controls="ivx-formcol" aria-selected="true">Details</button>
    <button type="button" role="tab" data-view="preview" id="ivx-tab-prev" aria-controls="ivx-preview" aria-selected="false" tabindex="-1"><?= icon('edit', 'sm') ?>Invoice</button>
  </div>

  <form class="ivx-grid inv-form" method="post" action="<?= esc($action, 'attr') ?>" id="inv-form" novalidate
        data-quote="<?= esc(site_url('invoices/quote'), 'attr') ?>" data-products="<?= esc(site_url('invoices/products'), 'attr') ?>" data-customers="<?= esc(site_url('invoices/customers'), 'attr') ?>"
        data-adjust="<?= esc(site_url('invoices/adjust'), 'attr') ?>" data-price="<?= $canPrice ? '1' : '0' ?>" data-id="<?= $id ?>" data-issued="<?= $issued ? '1' : '0' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="invoice_id" value="<?= $id ?>">
    <script type="application/json" id="ivx-cfg"><?= json_encode($calc + ['vat' => $vat, 'errors' => $errors], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <!-- ============================================================ the invoice, editable in place -->
    <section class="ivx-preview" id="ivx-preview" role="tabpanel" aria-labelledby="ivx-tab-prev" aria-label="Invoice (editable)">
      <?php $sbin = $nbr ? $mushakCfg['bin'] : (string) ($b['settings']['bin'] ?? ''); ?>
      <div class="ivx-paper ivx-wys ivd ivd-<?= esc($template, 'attr') ?><?= isset(\App\Modules\Invoices\Services\InvoiceSettings::THEMES[$template]) ? ' ivd-themed' : '' ?><?= ! empty($images['header']) ? ' ivd-hi' : '' ?><?= ! empty($images['footer']) ? ' ivd-fi' : '' ?>" style="--ia:<?= css_color($accent) ?><?= \App\Modules\Invoices\Services\InvoiceSettings::themeVars($template) ?>">
        <?php if (! $issued): ?><div class="ivx-ribbon" aria-hidden="true">DRAFT</div><?php endif ?>
        <?php if ($images['header']): ?><img class="inv-himg" src="<?= esc($images['header'], 'attr') ?>" alt=""><?php endif ?>
        <?php $hi = ! empty($images['header']); ob_start(); ?>
          <div class="ivd-doc">
            <input class="dx dx-title" data-bind="doc_title" aria-label="Invoice title" maxlength="40">
            <table>
              <tr><td>Invoice no.</td><td class="no"><?php if ($issued): ?><?= esc((string) $inv['doc_no']) ?><?php else: ?>Assigned on issue<?php if ($nextNo): ?><br><small style="font-weight:400;color:#9ca3af">next: <?= esc($nextNo) ?></small><?php endif ?><?php endif ?></td></tr>
              <tr><td><label for="dx-date">Date</label></td><td><input class="dx dx-date" id="dx-date" type="date" data-bind="invoice_date" <?= $canBackdate ? '' : 'readonly' ?>></td></tr>
              <?php if ($nbr): ?><tr><td>Time of issue</td><td><?= $issued && ! empty($inv['issued_at']) ? esc(date('h:i A', strtotime((string) $inv['issued_at']))) : 'on issue' ?></td></tr><?php endif ?>
              <tr><td><label for="f-terms-days">Terms</label></td><td><select class="dx dx-sel" id="f-terms-days"><option value="">Custom</option><option value="0">Due on receipt</option><option value="7">Net 7</option><option value="15">Net 15</option><option value="30">Net 30</option><option value="45">Net 45</option></select></td></tr>
              <tr><td><label for="dx-due">Due date</label></td><td><input class="dx dx-date" id="dx-due" type="date" data-bind="due_date"></td></tr>
              <tr><td><label for="dx-ref">Reference</label></td><td><input class="dx dx-r" id="dx-ref" data-bind="reference" placeholder="PO / ref." maxlength="64"></td></tr>
            </table>
          </div>
        <?php $docHtml = ob_get_clean(); ?>
        <?php if (! $hi): ?>
        <header class="ivd-band">
            <div class="ivd-brand ivx-settings-zone"><div class="ivd-logo emo"><?= biz_logo($b, 'lg') ?></div>
              <div><h1><?= esc($b['name']) ?></h1>
                <?php if ($nbr && $mushakCfg['reg_name'] !== '' && $mushakCfg['reg_name'] !== $b['name']): ?><div class="reg">Registered name: <?= esc($mushakCfg['reg_name']) ?></div><?php endif ?>
                <p><?= esc($nbr ? $mushakCfg['address'] : ($b['address'] ?: ($b['settings']['branch'] ?? ''))) ?><?php if (! empty($b['phone']) || ! empty($b['email'])): ?><br><?= esc(implode(' · ', array_filter([(string) ($b['phone'] ?? ''), (string) ($b['email'] ?? '')]))) ?><?php endif ?></p>
                <?php $sbin = $nbr ? $mushakCfg['bin'] : (string) ($b['settings']['bin'] ?? ''); if ($sbin !== ''): ?><span class="bin">BIN <?= esc($sbin) ?></span><?php endif ?></div>
              <?php if ($canSettings): ?><a class="ivx-settings-link" href="<?= esc(site_url('invoices/settings'), 'attr') ?>" target="_blank" rel="noopener"><?= icon('setup', 'sm') ?>Edit in settings</a><?php endif ?>
            </div>
<?= $docHtml ?>
        </header>
        <?php endif ?>
        <section class="ivd-parties<?= $hi ? ' ivd-parties-doc' : '' ?>">
          <div><span class="ivd-lbl">Bill to</span>
            <div class="inv-ac dx-ac"><input class="dx dx-strong" data-bind="bill_name" placeholder="Customer name — type to find an existing customer" autocomplete="off" id="dx-cust" aria-label="Customer name" maxlength="120" <?= $ro ?>><div class="inv-drop" id="dx-cust-drop" role="listbox" hidden></div></div>
            <span class="ivx-picked dx-picked" id="inv-cust-picked" <?= $inv['customer_id'] ? '' : 'hidden' ?>><?= icon('check', 'sm') ?>Existing customer<?php if (! $issued): ?> · <button type="button" class="link" id="inv-cust-clear">new customer instead</button><?php endif ?></span>
            <textarea class="dx" data-bind="bill_address" placeholder="Billing address" aria-label="Billing address" rows="1" maxlength="255"></textarea>
            <input class="dx" data-bind="bill_phone" placeholder="Phone" aria-label="Phone" maxlength="32" inputmode="tel" <?= $ro ?>>
            <input class="dx" data-bind="bill_email" placeholder="Email" aria-label="Email" maxlength="160" type="email">
            <input class="dx" data-bind="bill_bin" placeholder="Buyer BIN (VAT-registered buyers)" aria-label="Buyer BIN" maxlength="20" inputmode="numeric"></div>
          <div><span class="ivd-lbl"><?= $nbr ? 'Ship to / destination of supply' : 'Ship to' ?></span><textarea class="dx" data-bind="ship_to" placeholder="Same as billing" aria-label="Ship to" rows="2" maxlength="255"></textarea></div>
          <?= $hi ? $docHtml : '' ?>
        </section>
        <div class="ivx-p-tblwrap">
          <table class="ivd-items ivx-wtbl">
            <thead><tr><th class="c-h"><span class="sr-only">Order</span></th><th>Item</th><th class="num">Qty</th><th class="num">Price</th><th class="num"><?= esc($calc['taxLabel']) ?></th><th class="num">Amount</th><th class="c-x"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody id="ivx-p-rows"></tbody>
            <tfoot>
              <tr class="ivx-p-add"><td colspan="7">
                <div class="ivx-p-addrow inv-ac">
                  <div class="ivx-search"><?= icon('plus', 'sm') ?><input class="input" id="ivx-p-search" placeholder="Add line — search product or service" autocomplete="off" aria-label="Add a line: search products and services" aria-autocomplete="list" aria-controls="ivx-p-drop"></div>
                  <div class="inv-drop" id="ivx-p-drop" role="listbox" hidden></div>
                  <?php if ($canPrice): ?><button class="btn sm" type="button" data-free-line><?= icon('plus', 'sm') ?>Free line</button><?php endif ?>
                </div>
              </td></tr>
            </tfoot>
          </table>
        </div>
        <section class="ivd-sum">
          <div class="ivd-left">
            <label><span class="ivd-lbl">Notes</span><textarea class="dx" data-bind="notes" rows="2" placeholder="Add a note for the customer" aria-label="Notes" maxlength="2000"></textarea></label>
            <label><span class="ivd-lbl">Terms</span><textarea class="dx" data-bind="terms" rows="2" placeholder="Payment terms, warranty …" aria-label="Terms" maxlength="2000"></textarea></label>
            <?php if ($pageCfg['bank'] !== ''): ?><div class="ivd-pays"><span class="ivd-lbl">Payment details</span><div><?= nl2br(esc($pageCfg['bank'])) ?></div></div><?php endif ?>
            <?php if ($nbr): ?><p class="ivx-hint" style="margin:0">VAT-registered: the printed invoice adds the NBR columns (value without VAT, VAT rate and amount, value with VAT) and the time of issue.</p><?php endif ?>
          </div>
          <dl class="ivx-p-tot">
            <div><dt>Subtotal</dt><dd data-pv="subtotal">—</dd></div>
            <?php if ($canPrice): ?>
              <div class="ivx-p-disc"><dt><label for="ivx-p-dval">Discount</label> <?= $discSelect('data-bind="discount_type" aria-label="Invoice discount type"', (string) $discount['type'], 'dx dx-sel') ?><input class="dx dx-num" id="ivx-p-dval" data-bind="discount_value" type="number" step="any" min="0" placeholder="0" aria-label="Invoice discount"></dt><dd data-pv="discount">—</dd></div>
            <?php else: ?>
              <div data-pv-wrap="discount" hidden><dt>Discount</dt><dd data-pv="discount"></dd></div>
            <?php endif ?>
            <div data-pv-wrap="service" hidden><dt>Service charge</dt><dd data-pv="service"></dd></div>
            <div id="ivx-p-taxrows"></div>
            <div data-pv-wrap="round" hidden><dt>Round off</dt><dd data-pv="round"></dd></div>
            <div class="gt"><dt>Total</dt><dd data-pv="total">—</dd></div>
            <?php if ($issued): ?>
              <div><dt>Paid</dt><dd><?= $F($inv['order']['paid_total']) ?></dd></div>
            <?php else: ?>
              <div data-pv-wrap="paid" hidden><dt>Paid now</dt><dd data-pv="paid"></dd></div>
              <div data-pv-wrap="balance" hidden class="bal"><dt>Balance due</dt><dd data-pv="balance"></dd></div>
            <?php endif ?>
          </dl>
        </section>
        <?php if ($nbr): ?><p class="ivd-nbr">This is a commercial invoice, not the Mushak-6.3 tax invoice.</p><?php endif ?>
        <footer class="ivx-p-foot">
          <?php if (! empty($pageCfg['sign_show']) || ! empty($pageCfg['sign_customer'])): ?>
            <div class="ivx-p-signs"><?php if (! empty($pageCfg['sign_customer'])): ?><div><span class="ln"></span><b>Customer signature</b></div><?php else: ?><div></div><?php endif ?>
              <?php if (! empty($pageCfg['sign_show'])): ?><div><span class="ln"></span><b>Authorized signature</b><?php if (! empty($pageCfg['sign_name'])): ?><i><?= esc((string) $pageCfg['sign_name']) ?></i><?php endif ?><?php if (! empty($pageCfg['sign_title'])): ?><small><?= esc((string) $pageCfg['sign_title']) ?></small><?php endif ?></div><?php endif ?></div>
          <?php endif ?>
          <?php if ($hi): ?><input type="hidden" data-bind="footer_text"><?php else: ?><input class="dx dx-c ivd-thanks" data-bind="footer_text" placeholder="Invoice footer — thank-you line" aria-label="Invoice footer" maxlength="200"><?php endif ?>
          <?php if ($images['footer']): ?><img class="inv-fimg" src="<?= esc($images['footer'], 'attr') ?>" alt=""><?php endif ?>
          <?php if (! $hi): ?><div class="ivx-p-bar"><span><?= $images['footer'] ? '' : esc((string) $b['name']) ?><?= $sbin !== '' ? ($images['footer'] ? '' : ' · ') . 'BIN ' . esc($sbin) : '' ?></span><span><?= esc(implode(' · ', array_filter([(string) ($b['phone'] ?? ''), (string) ($b['email'] ?? '')]))) ?></span></div><?php endif ?>
        </footer>
        <p class="ivx-p-hint" id="q-err" role="status" hidden></p>
      </div>
    </section>

    <!-- ============================================================ the same fields as a form -->
    <div class="ivx-side" id="ivx-formcol" role="tabpanel" aria-labelledby="ivx-tab-form">
      <!-- The customer, dates and payment terms are edited on the invoice paper (left). These inputs only carry the values
           to the server and are kept in step with the paper by invoices.js — nothing here is shown. -->
      <div class="ivx-carrier">
      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('users', 'sm') ?>Customer</h3>
        <input type="hidden" name="customer_id" id="inv-cust-id" value="<?= $o('customer_id', $inv['customer_id']) ?>">
        <div class="ivx-field inv-ac">
          <label for="inv-cust">Customer name</label>
          <div class="ivx-search"><?= icon('search', 'sm') ?><input class="input<?= $err('bill_name') ?>" name="bill_name" id="inv-cust" value="<?= $o('bill_name', $inv['bill_name']) ?>" maxlength="120" autocomplete="off" placeholder="Search customers or type a new name" aria-autocomplete="list" aria-controls="inv-cust-drop" <?= $ro ?>></div>
          <div class="inv-drop" id="inv-cust-drop" role="listbox" hidden></div>
          <?= field_error('bill_name') ?><?= field_error('customer_id') ?>
        </div>
        <div class="ivx-2">
          <div class="ivx-field"><label for="f-phone">Phone</label><input class="input" id="f-phone" name="bill_phone" value="<?= $o('bill_phone', $inv['bill_phone']) ?>" maxlength="32" inputmode="tel" <?= $ro ?>></div>
          <div class="ivx-field"><label for="f-email">Email</label><input class="input<?= $err('bill_email') ?>" id="f-email" type="email" name="bill_email" value="<?= $o('bill_email', $inv['bill_email']) ?>" maxlength="160"><?= field_error('bill_email') ?></div>
        </div>
        <div class="ivx-field"><label for="f-addr">Billing address</label><input class="input" id="f-addr" name="bill_address" value="<?= $o('bill_address', $inv['bill_address']) ?>" maxlength="255"></div>
        <details class="ivx-more" <?= ($inv['bill_bin'] || $inv['ship_to'] || $inv['reference'] || isset($errors['bill_bin'])) ? 'open' : '' ?>>
          <summary>Buyer BIN, ship to &amp; reference</summary>
          <div class="ivx-2">
            <div class="ivx-field"><label for="f-bin">Buyer BIN</label><input class="input<?= $err('bill_bin') ?>" id="f-bin" name="bill_bin" value="<?= $o('bill_bin', $inv['bill_bin']) ?>" maxlength="20" inputmode="numeric" placeholder="VAT-registered buyers"><?= field_error('bill_bin') ?></div>
            <div class="ivx-field"><label for="f-ref">Reference / PO no.</label><input class="input" id="f-ref" name="reference" value="<?= $o('reference', $inv['reference']) ?>" maxlength="64"></div>
          </div>
          <div class="ivx-field"><label for="f-ship">Ship to / destination</label><input class="input" id="f-ship" name="ship_to" value="<?= $o('ship_to', $inv['ship_to']) ?>" maxlength="255"></div>
        </details>
      </section>
      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('calendar', 'sm') ?>Dates &amp; terms</h3>
        <div class="ivx-3">
          <div class="ivx-field"><label for="f-date">Invoice date</label><input class="input<?= $err('invoice_date') ?>" type="date" id="f-date" name="invoice_date" value="<?= $o('invoice_date', $invoiceDate) ?>" max="<?= date('Y-m-d') ?>" <?= $canBackdate ? 'min="' . date('Y-m-d', strtotime('-1 year')) . '"' : 'readonly title="Your role dates invoices today"' ?>><?= field_error('invoice_date') ?></div>
          <div class="ivx-field"><label for="f-due">Due date</label><input class="input<?= $err('due_date') ?>" type="date" id="f-due" name="due_date" value="<?= $o('due_date', $inv['due_date']) ?>"><?= field_error('due_date') ?></div>
        </div>
        <small class="ivx-hint" id="f-terms-hint">Payment terms set the due date from the invoice date.</small>
      </section>
      </div>

      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('box', 'sm') ?>Items</h3>
        <div class="ivx-pick inv-ac">
          <div class="ivx-search"><?= icon('search', 'sm') ?><input class="input" id="inv-prod" placeholder="Add product or service — name, SKU, barcode" autocomplete="off" aria-label="Find a product or service" aria-autocomplete="list" aria-controls="inv-prod-drop"></div>
          <div class="inv-drop" id="inv-prod-drop" role="listbox" hidden></div>
          <?php if ($canPrice): ?><button class="btn" type="button" id="inv-free" data-free-line><?= icon('plus', 'sm') ?>Free line</button><button class="btn icon" type="button" data-modal-open="adj-dlg" id="adj-open" title="Adjust prices" aria-label="Adjust prices"><?= icon('sliders', 'sm') ?></button><?php endif ?>
        </div>
        <div class="ivx-pos" id="ivx-pos" data-catalog="<?= esc(site_url('invoices/catalog'), 'attr') ?>" data-demo="<?= esc(asset_url('img/demo.svg'), 'attr') ?>">
          <div class="ivx-cats" id="ivx-cats" hidden></div>
          <div class="tiles ivx-grid" id="ivx-grid" aria-label="Products — tap to add"><div class="empty"><b>Loading products…</b></div></div>
        </div>
        <?= field_error('lines') ?>
        <div class="ivx-added" id="ivx-added" hidden><span id="ivx-added-t"></span><button class="btn sm" type="button" id="ivx-added-go"><?= icon('edit', 'sm') ?>Edit on the invoice</button></div>
        <div id="inv-rows" class="ivx-lines ivx-lines-hidden" aria-hidden="true">
          <?php foreach ($lines as $n => $l): $custom = ! empty($l['custom']); $p = 'lines[' . $n . ']';
              $mode = (string) ($l['tax_mode'] ?? ($custom ? 'default' : ($l['product']['tax_mode'] ?? 'default')));
              $rate = $l['tax_rate'] ?? ($custom ? null : ($l['product']['tax_rate'] ?? null));
              $catalog = $l['catalog'] ?? null;
              $shown = $l['unit_price'] ?? $catalog ?? 0;
              $keepRo = $custom && ! $canPrice; ?>
            <div class="ivx-line inv-line" data-key="k<?= $n ?>" data-custom="<?= $custom ? '1' : '0' ?>" data-ro="<?= $keepRo ? '1' : '0' ?>" data-name="<?= esc((string) $l['name'], 'attr') ?>"
                 data-unitname="<?= esc((string) ($l['unit'] ?? $l['product']['unit'] ?? ''), 'attr') ?>" data-sku="<?= esc((string) ($l['product']['sku'] ?? ''), 'attr') ?>"
                 data-price="<?= esc((string) (float) $shown, 'attr') ?>" data-catalog="<?= $catalog !== null ? esc((string) (float) $catalog, 'attr') : '' ?>"
                 data-mode="<?= esc($mode, 'attr') ?>" data-rate="<?= $rate !== null ? esc((string) (float) $rate, 'attr') : '' ?>"
                 data-dtype="<?= esc((string) ($l['discount_type'] ?? 'percent'), 'attr') ?>" data-dval="<?= esc((string) (float) ($l['discount_value'] ?? 0), 'attr') ?>">
              <div class="ivx-line-top">
                <?php if ($canPrice): ?><input type="checkbox" class="inv-sel" aria-label="Select <?= esc((string) $l['name'], 'attr') ?> for Adjust prices"><?php endif ?>
                <?php if ($custom && $canPrice): ?>
                  <div class="ivx-line-nm ivx-free"><input type="hidden" name="<?= $p ?>[custom]" value="1"><input class="input<?= $lineErr($n, 'name') ?>" name="<?= $p ?>[name]" value="<?= esc((string) $l['name'], 'attr') ?>" maxlength="200" required aria-label="Description" placeholder="Description"><input class="input ivx-unit" name="<?= $p ?>[unit]" value="<?= esc((string) ($l['unit'] ?? ''), 'attr') ?>" maxlength="16" placeholder="unit" aria-label="Unit"></div>
                <?php elseif ($custom): ?>
                  <div class="ivx-line-nm"><b><?= esc((string) $l['name']) ?></b><small>Free line · price set by a manager</small><input type="hidden" name="<?= $p ?>[custom]" value="1"><input type="hidden" name="<?= $p ?>[keep]" value="<?= (int) ($l['keep'] ?? $n) ?>"></div>
                <?php else: ?>
                  <div class="ivx-line-nm"><b><?= esc((string) $l['name']) ?></b><small><?= esc(implode(' · ', array_filter([(string) ($l['product']['sku'] ?? ''), ! empty($l['serial']) ? 'IMEI/SN ' . $l['serial'] : '']))) ?></small>
                    <?php if (! empty($l['serial'])): ?><input type="hidden" name="<?= $p ?>[serial]" value="<?= esc((string) $l['serial'], 'attr') ?>"><?php endif ?>
                    <input type="hidden" name="<?= $p ?>[product_id]" value="<?= (int) $l['product_id'] ?>"><input type="hidden" name="<?= $p ?>[variant_id]" value="<?= (int) ($l['variant_id'] ?? 0) ?: '' ?>"><input type="hidden" name="<?= $p ?>[name]" value="<?= esc((string) $l['name'], 'attr') ?>"></div>
                <?php endif ?>
                <span class="ivx-line-move"><button class="icon-btn" type="button" data-move="-1" aria-label="Move up"><?= icon('chevron', 'sm') ?></button><button class="icon-btn" type="button" data-move="1" aria-label="Move down"><?= icon('chevron', 'sm') ?></button></span>
                <button class="icon-btn" type="button" data-remove aria-label="Remove <?= esc((string) $l['name'], 'attr') ?>"><?= icon('trash', 'sm') ?></button>
              </div>
              <div class="ivx-line-ctl">
                <label class="ivx-mini">Qty<input class="input inv-qty<?= $lineErr($n, 'qty') ?>" type="number" step="any" min="0.001" name="<?= $p ?>[qty]" value="<?= esc((string) (float) $l['qty'], 'attr') ?>" <?= $keepRo ? 'readonly' : '' ?>></label>
                <?php if ($canPrice): ?>
                  <label class="ivx-mini">Price<input class="input inv-price<?= $lineErr($n, 'unit_price') ?>" type="number" step="any" min="0" name="<?= $p ?>[unit_price]" value="<?= isset($l['unit_price']) ? $num($l['unit_price']) : '' ?>" placeholder="<?= $catalog !== null ? $num($catalog) : '' ?>" <?= $custom ? 'required' : '' ?>></label>
                  <label class="ivx-mini ivx-disc">Discount<span><?= $discSelect('name="' . $p . '[discount_type]" aria-label="Discount type"', (string) ($l['discount_type'] ?? 'percent')) ?><input class="input inv-disc" type="number" step="any" min="0" name="<?= $p ?>[discount_value]" value="<?= ! empty($l['discount_value']) ? $num($l['discount_value']) : '' ?>" aria-label="Discount"></span></label>
                  <label class="ivx-mini"><?= esc($calc['taxLabel']) ?><?= $vatSelect('name="' . $p . '[vat]"', $vatVal($l)) ?></label>
                <?php else: ?>
                  <span class="ivx-mini ivx-ro">Price<b><?= $F($shown) ?></b></span>
                <?php endif ?>
                <span class="ivx-amt inv-amt" aria-label="Line amount">—</span>
              </div>
              <div class="ivx-line-note"><input class="input inv-note" name="<?= $p ?>[note]" value="<?= esc((string) ($l['note'] ?? ''), 'attr') ?>" maxlength="255" placeholder="Line details (printed under the item)" aria-label="Line details" <?= $keepRo ? 'readonly' : '' ?>></div>
              <?php if ($canPrice): ?><div class="ivx-custom" data-custom-badge hidden><span class="ivx-badge">Custom price</span><s data-cat-text></s><button type="button" class="link" data-reset-price>Reset to catalogue price</button></div><?php endif ?>
            </div>
          <?php endforeach ?>
        </div>
        <div class="ivx-empty" id="inv-empty" <?= $lines ? 'hidden' : '' ?>><?= icon('box') ?><span>No items yet — search above<?= $canPrice ? ' or add a free line' : '' ?>.</span></div>
        <?php if ($canPrice): ?>
          <div class="ivx-field ivx-invdisc"><label for="f-disc">Invoice discount</label><span class="ivx-row"><?= $discSelect('name="discount_type" aria-label="Invoice discount type"', (string) (old('discount_type') ?? $discount['type'])) ?><input class="input<?= $err('discount_value') ?>" id="f-disc" type="number" step="any" min="0" name="discount_value" value="<?= $o('discount_value', (float) $discount['value'] > 0 ? (float) $discount['value'] : '') ?>" placeholder="0"></span><?= field_error('discount_value') ?></div>
        <?php endif ?>
      </section>


      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('notebook-pen', 'sm') ?>Notes, terms &amp; page text</h3>
        <div class="ivx-field"><label for="f-notes">Notes (printed)</label><textarea class="input" id="f-notes" name="notes" rows="2" maxlength="2000"><?= esc((string) (old('notes') ?? $inv['notes'] ?? '')) ?></textarea></div>
        <div class="ivx-field"><label for="f-terms">Terms</label><textarea class="input" id="f-terms" name="terms" rows="3" maxlength="2000"><?= esc((string) (old('terms') ?? $inv['terms'] ?? '')) ?></textarea></div>
        <details class="ivx-more">
          <summary>Title &amp; footer line of this invoice</summary>
          <div class="ivx-2">
            <div class="ivx-field"><label for="f-title">Title</label><input class="input" id="f-title" name="doc_title" value="<?= $o('doc_title', $pg['title']) ?>" maxlength="40"></div>
            <div class="ivx-field"><label for="f-foot">Footer line</label><input class="input" id="f-foot" name="footer_text" value="<?= $o('footer_text', $pg['footer_text']) ?>" maxlength="200"></div>
          </div>
        </details>
      </section>

      <div class="ivx-actions">
        <?php if (! $issued): ?>
          <div class="ivx-payrow"><span class="ivx-payic" aria-hidden="true"><?= icon('wallet', 'sm') ?></span>
            <div class="ivx-field"><label for="f-paym">Method</label><select class="select" id="f-paym" name="pay_method" aria-label="Payment method"><?php foreach ($tiles as $t): ?><option value="<?= esc($t['code'], 'attr') ?>" <?= $payNow === $t['code'] ? 'selected' : '' ?>><?= esc($t['label']) ?></option><?php endforeach ?></select></div>
            <div class="ivx-field"><label for="f-pay">Received</label><input class="input" id="f-pay" type="number" step="any" min="0" name="pay_amount" value="<?= $o('pay_amount', '') ?>" placeholder="0 = nothing yet"></div>
            <div class="ivx-field"><label for="f-payref">Reference</label><input class="input" id="f-payref" name="pay_reference" maxlength="64" value="<?= $o('pay_reference', '') ?>" placeholder="Trx ID / cheque no."></div>
          </div>
        <?php endif ?>
        <div class="ivx-act-total"><small>Total</small><b data-pv="total">—</b></div>
        <?php if ($issued): ?>
          <button class="btn primary ivx-cta" type="submit" name="action" value="save"><?= icon('check', 'sm') ?>Save changes</button>
        <?php else: ?>
          <button class="btn" type="submit" name="action" value="draft"><?= icon('draft', 'sm') ?>Save draft</button>
          <button class="btn primary ivx-cta" type="submit" name="action" value="issue"><?= icon('file-text', 'sm') ?>Generate invoice</button>
        <?php endif ?>
      </div>
    </div>
  </form>

  <template id="inv-tpl-product">
    <div class="ivx-line inv-line" data-custom="0" data-ro="0">
      <div class="ivx-line-top">
        <?php if ($canPrice): ?><input type="checkbox" class="inv-sel" aria-label="Select for Adjust prices"><?php endif ?>
        <div class="ivx-line-nm"><b data-f="name"></b><small data-f="sku"></small><span data-f="variants"></span>
          <input type="hidden" data-n="product_id"><input type="hidden" data-n="variant_id"><input type="hidden" data-n="name"></div>
        <span class="ivx-line-move"><button class="icon-btn" type="button" data-move="-1" aria-label="Move up"><?= icon('chevron', 'sm') ?></button><button class="icon-btn" type="button" data-move="1" aria-label="Move down"><?= icon('chevron', 'sm') ?></button></span>
        <button class="icon-btn" type="button" data-remove aria-label="Remove line"><?= icon('trash', 'sm') ?></button>
      </div>
      <div class="ivx-line-ctl">
        <label class="ivx-mini">Qty<input class="input inv-qty" type="number" step="any" min="0.001" data-n="qty" value="1"></label>
        <?php if ($canPrice): ?>
          <label class="ivx-mini">Price<input class="input inv-price" type="number" step="any" min="0" data-n="unit_price"></label>
          <label class="ivx-mini ivx-disc">Discount<span><?= $discSelect('data-n="discount_type" aria-label="Discount type"', 'percent') ?><input class="input inv-disc" type="number" step="any" min="0" data-n="discount_value" aria-label="Discount"></span></label>
          <label class="ivx-mini"><?= esc($calc['taxLabel']) ?><?= $vatSelect('data-n="vat"', '') ?></label>
        <?php else: ?>
          <span class="ivx-mini ivx-ro">Price<b data-f="price"></b></span>
        <?php endif ?>
        <span class="ivx-amt inv-amt" aria-label="Line amount">—</span>
      </div>
      <div class="ivx-line-note"><input class="input inv-note" data-n="note" maxlength="255" placeholder="Line details (printed under the item)" aria-label="Line details"></div>
      <?php if ($canPrice): ?><div class="ivx-custom" data-custom-badge hidden><span class="ivx-badge">Custom price</span><s data-cat-text></s><button type="button" class="link" data-reset-price>Reset to catalogue price</button></div><?php endif ?>
    </div>
  </template>
  <?php if ($canPrice): ?>
    <template id="inv-tpl-free">
      <div class="ivx-line inv-line" data-custom="1" data-ro="0" data-mode="default" data-rate="" data-catalog="">
        <div class="ivx-line-top">
          <input type="checkbox" class="inv-sel" aria-label="Select for Adjust prices">
          <div class="ivx-line-nm ivx-free"><input type="hidden" data-n="custom" value="1"><input class="input" data-n="name" maxlength="200" required placeholder="Description (service, charge …)" aria-label="Description"><input class="input ivx-unit" data-n="unit" maxlength="16" placeholder="unit" aria-label="Unit"></div>
          <span class="ivx-line-move"><button class="icon-btn" type="button" data-move="-1" aria-label="Move up"><?= icon('chevron', 'sm') ?></button><button class="icon-btn" type="button" data-move="1" aria-label="Move down"><?= icon('chevron', 'sm') ?></button></span>
          <button class="icon-btn" type="button" data-remove aria-label="Remove line"><?= icon('trash', 'sm') ?></button>
        </div>
        <div class="ivx-line-ctl">
          <label class="ivx-mini">Qty<input class="input inv-qty" type="number" step="any" min="0.001" data-n="qty" value="1"></label>
          <label class="ivx-mini">Price<input class="input inv-price" type="number" step="any" min="0" data-n="unit_price" required></label>
          <label class="ivx-mini ivx-disc">Discount<span><?= $discSelect('data-n="discount_type" aria-label="Discount type"', 'percent') ?><input class="input inv-disc" type="number" step="any" min="0" data-n="discount_value" aria-label="Discount"></span></label>
          <label class="ivx-mini"><?= esc($calc['taxLabel']) ?><?= $vatSelect('data-n="vat"', '') ?></label>
          <span class="ivx-amt inv-amt" aria-label="Line amount">—</span>
        </div>
        <div class="ivx-line-note"><input class="input inv-note" data-n="note" maxlength="255" placeholder="Line details (printed under the item)" aria-label="Line details"></div>
      </div>
    </template>
    <dialog class="modal-dlg" id="adj-dlg" aria-labelledby="adj-title">
      <div class="modal-h"><h3 id="adj-title">Adjust prices</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
      <div class="modal-b ivx-adj">
        <fieldset class="ivx-seg"><legend>Apply to</legend>
          <label><input type="radio" name="adj_scope" value="sel" checked><span>Selected lines (<b id="adj-nsel">0</b>)</span></label>
          <label><input type="radio" name="adj_scope" value="all"><span>All lines</span></label>
        </fieldset>
        <div class="ivx-2">
          <div class="ivx-field"><label for="adj-mode">Change</label><select class="select" id="adj-mode"><option value="up">Increase price by</option><option value="down">Decrease price by</option><option value="target">Set line total to</option></select></div>
          <div class="ivx-field"><label for="adj-value">Amount</label><span class="ivx-row"><input class="input" id="adj-value" type="number" step="any" min="0" placeholder="0"><select class="select ivx-dtype" id="adj-unit" aria-label="Percent or amount"><option value="pct">%</option><option value="amount"><?= esc($cur['symbol']) ?></option></select></span></div>
        </div>
        <div class="ivx-field"><label for="adj-round">Round the new price</label><select class="select" id="adj-round"><option value="none">No rounding</option><option value="1">To the nearest 1</option><option value="5">To the nearest 5</option><option value="10">To the nearest 10</option></select></div>
        <div class="ivx-adj-prev" id="adj-prev" aria-live="polite"><p class="ivx-hint">Choose the change and press Preview.</p></div>
      </div>
      <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn" type="button" id="adj-preview"><?= icon('eye', 'sm') ?>Preview</button><button class="btn primary" type="button" id="adj-apply" disabled><?= icon('check', 'sm') ?>Apply</button></div>
    </dialog>
  <?php endif ?>
</div>
<script src="<?= esc(base_url('assets/pos/sound.js') . '?v=' . filemtime(FCPATH . 'assets/pos/sound.js'), 'attr') ?>"></script>
<script src="<?= esc(base_url('assets/pos/invoices.js') . '?v=' . (is_file(FCPATH . 'assets/pos/invoices.js') ? filemtime(FCPATH . 'assets/pos/invoices.js') : 0), 'attr') ?>"></script>
