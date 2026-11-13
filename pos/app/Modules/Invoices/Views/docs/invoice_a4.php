<?php
/**
 * The printed invoice, A4 (A5 = the same page scaled by the print layout). Designs: classic,
 * modern, compact (Invoice page settings) — see _ivd_style.php.
 *
 * VAT-registered outlets (Mushak-6.3 set up: registered name, BIN, issuing address) also print
 * the NBR details of a supply, with the same figures as the Mushak-6.3 (MushakService::compute):
 * seller registered name / address / BIN, buyer BIN when given, issue date and time, and per line
 * unit, unit price and value without VAT, SD (only when any), VAT rate and amount, value with VAT.
 * Prices printed are always the prices charged.
 *
 * @var array<string, mixed> $d InvoiceDocs::build()
 */
helper(['ui', 'pos']);
$o = $d['order'];
$b = $d['business'];
$inv = $d['invoice'];
$pg = $d['page'];
$img = $d['images'];
$seller = $d['seller'];
$nbr = $d['nbr'] && $d['nbrRows'] !== null;
$F = static fn ($n): string => esc(money($n, $d['cur']));
$N = static fn ($n): string => esc(number_format((float) $n, (int) $d['cur']['decimals']));
$Q = static fn ($n): string => esc(rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.'));
$extra = round((float) $o['total'] - (float) $o['subtotal'] + (float) $o['discount_total'] - (float) $o['service_charge'] - (float) $o['packing'] - (float) $o['tax_added'] - (float) $o['shipping'] - (float) $o['round_off'], 2);
$time = $d['issuedAt'] ? date('h:i A', strtotime((string) $d['issuedAt'])) : null;
$status = $d['draft'] ? 'Draft' : ($d['void'] ? 'Void' : $d['statusText']);
?>
<?= view('App\Modules\Invoices\Views\docs\_style') ?>
<?= view('App\Modules\Invoices\Views\docs\_ivd_style') ?>
<div class="a4 inv-page ivd ivd-<?= esc($d['template'], 'attr') ?><?= isset(\App\Modules\Invoices\Services\InvoiceSettings::THEMES[$d['template']]) ? ' ivd-themed' : '' ?><?= ! empty($img['header']) ? ' ivd-hi' : '' ?><?= ! empty($img['footer']) ? ' ivd-fi' : '' ?><?= $nbr ? ' ivd-nbr-on' : '' ?>" style="--ia:<?= css_color($d['primary']) ?><?= \App\Modules\Invoices\Services\InvoiceSettings::themeVars($d['template']) ?>">
  <?php if ($d['draft'] || $d['void']): ?><div class="inv-wm" aria-hidden="true"><?= $d['draft'] ? 'DRAFT' : 'VOID' ?></div><?php endif ?>
  <?php if ($img['header']): ?><img class="inv-himg" src="<?= esc($img['header'], 'attr') ?>" alt=""><?php endif ?>
  <?php $hi = ! empty($img['header']); ob_start(); ?>
    <div class="ivd-doc"><h2><?= esc($pg['title']) ?></h2>
      <table>
        <tr><td>Invoice no.</td><td class="no"><?= esc($d['draft'] ? 'Draft #' . $inv['id'] : ($inv['doc_no'] ?: $o['doc_no'])) ?></td></tr>
        <?php if (! $d['draft'] && ! empty($inv['doc_no']) && (string) $inv['doc_no'] !== (string) $o['doc_no']): ?><tr><td>Sale no.</td><td><?= esc($o['doc_no']) ?></td></tr><?php endif ?>
        <tr><td>Date</td><td><?= esc(date_str($d['invoiceDate'])) ?></td></tr>
        <?php if ($nbr || $time !== null): ?><tr><td>Time of issue</td><td><?= esc($time ?? 'on issue') ?></td></tr><?php endif ?>
        <?php if (! empty($inv['due_date'])): ?><tr><td>Due date</td><td><?= esc(date_str($inv['due_date'])) ?></td></tr><?php endif ?>
        <?php if (! empty($inv['reference'])): ?><tr><td>Reference</td><td><?= esc($inv['reference']) ?></td></tr><?php endif ?>
        <?php if (! empty($d['mushakDoc'])): ?><tr><td>Mushak-6.3</td><td><?= esc($d['mushakDoc']['doc_no']) ?></td></tr><?php endif ?>
        <tr><td>Status</td><td><b><?= esc($status) ?></b></td></tr>
        <?php if ($hi && $seller['bin']): ?><tr><td>BIN</td><td><?= esc($seller['bin']) ?></td></tr><?php endif ?>
      </table>
    </div>
  <?php $docHtml = ob_get_clean(); ?>
  <?php if (! $hi): ?>
  <header class="ivd-band">
      <div class="ivd-brand"><?php $lg = doc_logo($d); ?><?php if ($lg !== ''): ?><div class="ivd-logo emo"><?= $lg ?></div><?php endif ?>
        <div><h1><?= esc($seller['name']) ?></h1>
          <?php if ($seller['reg_name']): ?><div class="reg">Registered name: <?= esc($seller['reg_name']) ?></div><?php endif ?>
          <p><?= esc($seller['address']) ?><?php if (! empty($b['phone']) || ! empty($b['email'])): ?><br><?= esc(implode(' · ', array_filter(['Phone ' . ($b['phone'] ?? ''), (string) ($b['email'] ?? '')], static fn ($x) => $x !== '' && $x !== 'Phone '))) ?><?php endif ?></p>
          <?php if ($seller['bin']): ?><span class="bin">BIN <?= esc($seller['bin']) ?></span><?php endif ?></div></div>
<?= $docHtml ?>
  </header>
  <?php endif ?>
  <section class="ivd-parties<?= $hi ? ' ivd-parties-doc' : '' ?>">
    <div><span class="ivd-lbl">Bill to</span><b><?= esc($inv['bill_name'] ?: $o['customer_name']) ?></b>
      <?php if (! empty($inv['bill_address'])): ?><span><?= esc($inv['bill_address']) ?></span><?php endif ?>
      <?php if (! empty($inv['bill_phone']) || ! empty($inv['bill_email'])): ?><span><?= esc(implode(' · ', array_filter([(string) $inv['bill_phone'], (string) $inv['bill_email']]))) ?></span><?php endif ?>
      <?php if (! empty($inv['bill_bin'])): ?><span><b style="font-size:inherit">BIN</b> <?= esc($inv['bill_bin']) ?></span><?php endif ?></div>
    <?php if (! empty($inv['ship_to'])): ?><div><span class="ivd-lbl"><?= $nbr ? 'Ship to / destination of supply' : 'Ship to' ?></span><span><?= esc($inv['ship_to']) ?></span></div><?php endif ?>
    <?= $hi ? $docHtml : '' ?>
  </section>

  <?php if ($nbr): $r = $d['nbrRows']; $sd = ! empty($d['nbrSd']); ?>
    <table class="ivd-items">
      <thead><tr><th>#</th><th>Description</th><th>Unit</th><th class="num">Qty</th><th class="num">Unit price<br>excl. VAT</th><th class="num">Value<br>excl. VAT</th><?php if ($sd): ?><th class="num">SD</th><?php endif ?><th class="num">VAT<br>rate</th><th class="num">VAT</th><th class="num">Value<br>incl. VAT</th></tr></thead>
      <tbody>
        <?php foreach ($r['lines'] as $i => $l): $src = $d['lines'][$i] ?? null; ?>
          <tr><td><?= $i + 1 ?></td><td><b><?= esc($l['description']) ?></b><?php if ($src && $src['details']): ?><small><?= implode(' · ', $src['details']) ?></small><?php endif ?></td>
            <td><?= esc((string) ($l['unit'] ?? '')) ?: '—' ?></td><td class="num"><?= $Q($l['qty']) ?></td><td class="num"><?= $N($l['unit_price']) ?></td><td class="num"><?= $N($l['value']) ?></td>
            <?php if ($sd): ?><td class="num"><?= $N($l['sd']) ?></td><?php endif ?>
            <td class="num"><?= $l['vat_rate'] === null ? 'Exempt' : esc(rate_str($l['vat_rate'])) . '%' ?></td><td class="num"><?= $N($l['vat']) ?></td><td class="num"><?= $N($l['total']) ?></td></tr>
        <?php endforeach ?>
      </tbody>
      <tfoot><tr><td colspan="5">Total</td><td class="num"><?= $N($r['totals']['value']) ?></td><?php if ($sd): ?><td class="num"><?= $N($r['totals']['sd']) ?></td><?php endif ?><td></td><td class="num"><?= $N($r['totals']['vat']) ?></td><td class="num"><?= $N($r['totals']['total']) ?></td></tr></tfoot>
    </table>
  <?php else: ?>
    <table class="ivd-items">
      <thead><tr><th>#</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Discount</th><?php if ($d['vatOn']): ?><th class="num"><?= esc($d['taxLabel']) ?></th><?php endif ?><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($d['lines'] as $i => $l): ?>
          <tr><td><?= $i + 1 ?></td><td><b><?= esc($l['name']) ?></b><?php if (! empty($l['sku'])): ?> <span style="color:#6b7280;font-size:10.5px"><?= esc($l['sku']) ?></span><?php endif ?><?php if ($l['details']): ?><small><?= implode(' · ', $l['details']) ?></small><?php endif ?></td>
            <td class="num"><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></td><td class="num"><?= $F($l['gross_unit']) ?></td><td class="num"><?= $l['disc_total'] > 0 ? $F($l['disc_total']) : '—' ?></td>
            <?php if ($d['vatOn']): ?><td class="num"><?= esc($l['vat_cell']) ?></td><?php endif ?><td class="num"><?= $F($l['line_total']) ?></td></tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>

  <section class="ivd-sum">
    <div class="ivd-left">
      <?php if ($d['stamp']): ?><span class="ivd-stamp"><?= esc($d['stamp'][0]) ?></span><?php endif ?>
      <div class="ivd-words"><span class="ivd-lbl">Amount in words</span><b><?= esc(amount_words((float) $o['total'], $d['code'])) ?></b></div>
      <?php if ($d['vatTable']): ?>
        <table class="ivd-vat"><thead><tr><th><?= esc($d['taxLabel']) ?> by rate</th><th class="num">Taxable value</th><th class="num"><?= esc($d['taxLabel']) ?></th></tr></thead><tbody>
          <?php foreach ($d['vatTable'] as $x): ?><tr><td><?= esc($x['label']) ?></td><td class="num"><?= $F($x['net']) ?></td><td class="num"><?= $F($x['tax']) ?></td></tr><?php endforeach ?>
        </tbody></table>
      <?php endif ?>
      <?php if ($o['payments']): ?><div class="ivd-pays"><span class="ivd-lbl">Payments</span><?php foreach ($o['payments'] as $p): ?><div><?= esc(date_str($p['created_at'])) ?> · <?= esc(pay_label($p['method'], $d['code'])) ?><?= ! empty($p['reference']) ? ' (' . esc($p['reference']) . ')' : '' ?> — <b><?= $F($p['amount']) ?></b></div><?php endforeach ?></div><?php endif ?>
      <?php if ($d['bank'] !== ''): ?><div class="ivd-pays"><span class="ivd-lbl">Payment details</span><div><?= nl2br(esc($d['bank'])) ?></div></div><?php endif ?>
    </div>
    <table class="ivd-tot">
      <tr><td>Subtotal</td><td><?= $F($o['subtotal']) ?></td></tr>
      <?php if (abs($extra) >= 0.01): ?><tr><td>Other charges</td><td><?= $F($extra) ?></td></tr><?php endif ?>
      <?php if ((float) $o['discount_total'] > 0): ?><tr><td>Discount</td><td>-<?= $F($o['discount_total']) ?></td></tr><?php endif ?>
      <?php if ((float) $o['packing'] > 0): ?><tr><td>Charges</td><td><?= $F($o['packing']) ?></td></tr><?php endif ?>
      <?php if ((float) $o['service_charge'] > 0): ?><tr><td>Service charge</td><td><?= $F($o['service_charge']) ?></td></tr><?php endif ?>
      <?php foreach ($d['taxRows'] as [$label, $v]): ?><tr><td><?= esc($label) ?></td><td><?= $F($v) ?></td></tr><?php endforeach ?>
      <?php if ((float) $o['shipping'] > 0): ?><tr><td>Delivery charge</td><td><?= $F($o['shipping']) ?></td></tr><?php endif ?>
      <?php if ((float) $o['round_off'] != 0): ?><tr><td>Round off</td><td><?= $F($o['round_off']) ?></td></tr><?php endif ?>
      <tr class="gt"><td>Total</td><td><?= $F($o['total']) ?></td></tr>
      <?php if (! $d['draft']): ?>
        <tr><td>Paid</td><td><?= $F($o['paid_total']) ?></td></tr>
        <?php if ($d['returned'] > 0): ?><tr><td>Returned</td><td>-<?= $F($d['returned']) ?></td></tr><?php endif ?>
        <?php if ((float) $o['due_total'] > 0): ?><tr class="ivd-due"><td>Balance due</td><td><?= $F($o['due_total']) ?></td></tr><?php endif ?>
      <?php endif ?>
    </table>
  </section>
  <?php if ($d['notes'] !== ''): ?><p class="ivd-text"><b>Notes:</b> <?= nl2br(esc($d['notes'])) ?></p><?php endif ?>
  <?php if ($d['terms'] !== ''): ?><p class="ivd-text"><b>Terms:</b> <?= nl2br(esc($d['terms'])) ?></p><?php endif ?>
  <?php if ($nbr): ?>
    <p class="ivd-nbr"><?php if (! empty($d['mushakDoc'])): ?>Mushak-6.3 tax invoice no. <b><?= esc($d['mushakDoc']['doc_no']) ?></b> was issued for this sale.<?php else: ?>This is a commercial invoice, not the Mushak-6.3 tax invoice.<?php endif ?></p>
  <?php endif ?>
  <div class="ivd-bottom">
  <?= view('App\Modules\Invoices\Views\docs\_sign', ['d' => $d, 'customer' => ! empty($d['sign']['customer'])]) ?>
  <?php if ($d['footer'] !== '' && ! $hi): ?><p class="ivd-thanks"><?= esc($d['footer']) ?></p><?php endif ?>
  <?php if ($img['footer']): ?><img class="inv-fimg" src="<?= esc($img['footer'], 'attr') ?>" alt=""><?php endif ?>
  <?php if (! $hi): ?><div class="ivd-foot"><span><?= $img['footer'] ? '' : esc($seller['name']) ?><?= $seller['bin'] ? ($img['footer'] ? '' : ' · ') . 'BIN ' . esc($seller['bin']) : '' ?></span><span>Printed <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?> · <?= esc(brand_name()) ?></span></div><?php endif ?>
  </div>
</div>
