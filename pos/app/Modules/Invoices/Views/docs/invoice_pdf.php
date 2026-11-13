<?php
/**
 * The invoice as a PDF page (Dompdf: tables and plain CSS only — no flex / grid).
 *
 * @var array<string, mixed> $d InvoiceDocs::build() + pdfImg (data URIs)
 */
helper(['ui', 'pos']);
$o = $d['order'];
$inv = $d['invoice'];
$pg = $d['page'];
$seller = $d['seller'];
$img = $d['pdfImg'];
$acc = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $d['primary']) ? (string) $d['primary'] : '#4F46E5';
$theme = \App\Modules\Invoices\Services\InvoiceSettings::THEMES[$d['template'] ?? ''] ?? null;
$t2 = $acc;
if ($theme !== null) { [$acc, $t2] = $theme; }
$ink = $theme !== null ? '#ffffff' : '#111827';
$pinned = count($d['lines']) <= 12;
$hi = ! empty($img['header']);
// footer image: full A4 width pinned to the bottom edge; its height (pt) decides where the signatures sit above it
$fh = 0;
if (! empty($img['footer']) && ($raw = base64_decode((string) substr((string) strstr($img['footer'], ','), 1), true)) !== false && ($sz = @getimagesizefromstring($raw)) && $sz[0] > 0) { $fh = (int) round(595.28 * $sz[1] / $sz[0]); }
$F = static fn ($n): string => esc(money($n, $d['cur']));
$extra = round((float) $o['total'] - (float) $o['subtotal'] + (float) $o['discount_total'] - (float) $o['service_charge'] - (float) $o['packing'] - (float) $o['tax_added'] - (float) $o['shipping'] - (float) $o['round_off'], 2);
$status = $d['draft'] ? 'DRAFT' : ($d['void'] ? 'VOID' : strtoupper((string) $d['statusText']));
$no = $d['draft'] ? 'Draft #' . $inv['id'] : (string) ($inv['doc_no'] ?: $o['doc_no']);
$cols = 5 + ($d['vatOn'] ? 1 : 0);
$sg = (array) ($d['sign'] ?? []);
$meta = array_filter([
    'Invoice no.' => $no, 'Date' => date_str($d['invoiceDate']),
    'Due date' => ! empty($inv['due_date']) ? date_str($inv['due_date']) : '', 'Reference' => (string) ($inv['reference'] ?? ''),
]);
?><!doctype html>
<html><head><meta charset="utf-8"><title><?= esc($no) ?></title>
<style>
@page { margin: 0 }
* { box-sizing: border-box }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1f2937; line-height: 1.38 }
.bn { font-family: notobn, 'DejaVu Sans', sans-serif }
table { border-collapse: collapse; width: 100% }
td, th { vertical-align: top }
.top { height: 9px; background: <?= $acc ?> }
.page { padding: <?= $hi ? '0' : '26px' ?> 38px 0 38px }
.brand h1 { margin: 0; font-size: 19pt; color: #111827; letter-spacing: -.3px }
.brand p { margin: 3px 0 0; color: #6b7280; font-size: 8.6pt }
.logo { width: 54px; height: 54px }
.mono { width: 46px; height: 46px; line-height: 46px; text-align: center; border-radius: 10px; background: <?= $theme !== null ? '#ffffff' : $acc ?>; color: <?= $theme !== null ? $acc : '#ffffff' ?>; font-size: 22pt; font-weight: bold }
.title { text-align: right }
.title h2 { margin: 0; font-size: 25pt; color: <?= $acc ?>; letter-spacing: 3px; font-weight: bold }
.title .st { display: inline-block; margin-top: 5px; padding: 2px 10px; border: 1px solid <?= $acc ?>; color: <?= $acc ?>; font-size: 8pt; font-weight: bold; letter-spacing: 1.5px }
.rule { height: 1px; background: #e5e7eb; margin: 16px 0 14px }
.lbl { font-size: 7.4pt; font-weight: bold; letter-spacing: 1.4px; text-transform: uppercase; color: <?= $acc ?> }
.to b { font-size: 12.5pt; color: #111827 }
.to span { display: block; color: #4b5563; font-size: 9pt }
.meta td { padding: 2px 0; font-size: 9pt }
.meta td:first-child { color: #6b7280; padding-right: 16px; text-align: right }
.meta td:last-child { font-weight: bold; text-align: right; color: #111827 }
.items { margin-top: 18px }
.items th { background: <?= $theme !== null ? $acc : '#111827' ?>; color: #fff; font-size: 7.8pt; text-transform: uppercase; letter-spacing: .8px; padding: 7px 8px; text-align: left }
.items th.r, .items td.r { text-align: right }
.items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9.2pt }
.items tr.alt td { background: #f8fafc }
.items small { display: block; color: #6b7280; font-size: 7.8pt }
.items .n { color: <?= $acc ?>; font-weight: bold }
.sum { margin-top: 14px }
.words { background: #f8fafc; border-left: 3px solid <?= $acc ?>; padding: 8px 11px; margin-bottom: 9px }
.words b { display: block; font-size: 9.2pt; color: #111827; margin-top: 2px }
.pays { font-size: 8.6pt; color: #4b5563; margin-top: 8px }
.tot td { padding: 4px 8px; font-size: 9.4pt }
.tot td:last-child { text-align: right; font-weight: bold; white-space: nowrap }
.tot tr.gt td { background: <?= $t2 ?>; color: #fff; font-size: 11.5pt; font-weight: bold; padding: 8px }
.tot tr.due td { color: #b91c1c; font-weight: bold; border-top: 1px solid #e5e7eb }
.text { margin: 10px 0 0; font-size: 8.6pt; color: #4b5563 }
.sign { margin-top: 26px }
.sign .box { width: 190px; text-align: center; font-size: 8.4pt; color: #374151 }
.sign .ln { border-top: 1px solid #1f2937; padding-top: 3px; font-weight: bold }
.thanks { text-align: center; margin: 20px 0 0; font-size: 10pt; font-weight: bold; color: <?= $acc ?> }
.th { background: <?= $acc ?>; border-radius: 8px; padding: 14px 18px; margin: 0 0 4px }
.th .brand h1, .th .brand p, .th .title h2 { color: #fff }
.th .brand p { opacity: .85 }
.th .title h2 { letter-spacing: 2px }
.th .title .st { border-color: #fff; color: #fff }
.th .rule { display: none }
.pin { position: fixed; left: 38px; right: 38px; bottom: <?= $fh > 0 ? ($fh + 6) . 'pt' : '46px' ?> }
.foot { position: fixed; left: 38px; right: 38px; bottom: 16px; <?= $theme !== null ? 'background:' . $acc . ';color:#fff;padding:5px 10px;border-radius:5px' : 'border-top:1px solid #e5e7eb;padding-top:5px;color:#9ca3af' ?>; font-size: 7.4pt }
.wm { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 96pt; font-weight: bold; color: #000; opacity: .05; letter-spacing: 8px }
</style></head>
<body>
<?php if ($d['draft'] || $d['void']): ?><div class="wm"><?= $d['draft'] ? 'DRAFT' : 'VOID' ?></div><?php endif ?>
<?php if (! $hi): ?><div class="top"></div><?php endif ?>
<div class="page">
  <?php if ($img['header']): ?><img src="<?= esc($img['header'], 'attr') ?>" style="display:block;width:595pt;margin:0 0 12px -38px" alt=""><?php endif ?>
  <?php if (! $img['header']): ?>
  <div class="<?= $theme !== null ? 'th' : '' ?>">
  <table><tr>
    <td class="brand" style="width:58%">
      <?php if (! $img['header']): ?>
        <table style="width:auto"><tr>
          <?php if ($img['logo']): ?><td style="padding-right:11px"><img class="logo" width="54" height="54" src="<?= esc($img['logo'], 'attr') ?>" alt=""></td>
          <?php elseif (! isset($d['showLogo']) || $d['showLogo']): ?><td style="padding-right:11px"><div class="mono"><?= esc(mb_strtoupper(mb_substr(trim((string) $seller['name']), 0, 1))) ?></div></td><?php endif ?>
          <td><h1><?= esc($seller['name']) ?></h1>
            <p><?= esc($seller['address']) ?><?php if (! empty($d['business']['phone'])): ?><br>Phone <?= esc($d['business']['phone']) ?><?php endif ?><?php if (! empty($d['business']['email'])): ?> · <?= esc($d['business']['email']) ?><?php endif ?><?php if ($seller['bin']): ?><br>BIN <?= esc($seller['bin']) ?><?php endif ?></p></td>
        </tr></table>
      <?php endif ?>
    </td>
    <td class="title"><h2><?= esc(strtoupper((string) $pg['title'])) ?></h2><span class="st"><?= esc($status) ?></span></td>
  </tr></table>
  </div>
  <div class="rule"></div>
  <?php endif ?>
  <table><tr>
    <td class="to" style="width:56%"><span class="lbl">Bill to</span><br><b><?= esc($inv['bill_name'] ?: $o['customer_name']) ?></b>
      <?php if (! empty($inv['bill_address'])): ?><span><?= esc($inv['bill_address']) ?></span><?php endif ?>
      <?php if (! empty($inv['bill_phone'])): ?><span><?= esc($inv['bill_phone']) ?></span><?php endif ?>
      <?php if (! empty($inv['bill_email'])): ?><span><?= esc($inv['bill_email']) ?></span><?php endif ?>
      <?php if (! empty($inv['bill_bin'])): ?><span>BIN <?= esc($inv['bill_bin']) ?></span><?php endif ?>
      <?php if (! empty($inv['ship_to'])): ?><br><span class="lbl">Ship to</span><span><?= esc($inv['ship_to']) ?></span><?php endif ?></td>
    <td><?php if ($img['header']): ?><div style="text-align:right"><h2 style="margin:0 0 2px;font-size:17pt;letter-spacing:2px;color:<?= $acc ?>"><?= esc(strtoupper((string) $pg['title'])) ?></h2><span class="st"><?= esc($status) ?></span><?php if ($seller['bin']): ?><br><small style="color:#6b7280">BIN <?= esc($seller['bin']) ?></small><?php endif ?></div><?php endif ?>
      <table class="meta" style="width:auto;margin-left:auto"><?php foreach ($meta as $k => $v): ?><tr><td><?= esc($k) ?></td><td><?= esc($v) ?></td></tr><?php endforeach ?></table></td>
  </tr></table>

  <table class="items">
    <thead><tr><th style="width:26px">#</th><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Discount</th><?php if ($d['vatOn']): ?><th class="r"><?= esc($d['taxLabel']) ?></th><?php endif ?><th class="r">Amount</th></tr></thead>
    <tbody>
      <?php foreach ($d['lines'] as $i => $l): ?>
        <tr class="<?= $i % 2 ? 'alt' : '' ?>"><td class="n"><?= $i + 1 ?></td>
          <td><b><?= esc($l['name']) ?></b><?php if (! empty($l['sku'])): ?> <span style="color:#9ca3af;font-size:7.8pt"><?= esc($l['sku']) ?></span><?php endif ?><?php if ($l['details']): ?><small><?= esc(strip_tags(implode(' · ', $l['details']))) ?></small><?php endif ?></td>
          <td class="r"><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></td><td class="r"><?= $F($l['gross_unit']) ?></td><td class="r"><?= $l['disc_total'] > 0 ? $F($l['disc_total']) : '—' ?></td>
          <?php if ($d['vatOn']): ?><td class="r"><?= esc($l['vat_cell']) ?></td><?php endif ?><td class="r"><b><?= $F($l['line_total']) ?></b></td></tr>
      <?php endforeach ?>
    </tbody>
  </table>

  <table class="sum"><tr>
    <td style="width:54%;padding-right:18px">
      <div class="words"><span class="lbl">Amount in words</span><b><?= esc(amount_words((float) $o['total'], $d['code'])) ?></b></div>
      <?php if ($o['payments']): ?><div class="pays"><span class="lbl">Payments</span><?php foreach ($o['payments'] as $p): ?><br><?= esc(date_str($p['created_at'])) ?> · <?= esc(pay_label($p['method'], $d['code'])) ?><?= ! empty($p['reference']) ? ' (' . esc($p['reference']) . ')' : '' ?> — <b><?= $F($p['amount']) ?></b><?php endforeach ?></div><?php endif ?>
      <?php if ($d['bank'] !== ''): ?><div class="pays"><span class="lbl">Payment details</span><br><?= nl2br(esc($d['bank'])) ?></div><?php endif ?>
    </td>
    <td>
      <table class="tot">
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
          <?php if ((float) $o['due_total'] > 0): ?><tr class="due"><td>Balance due</td><td><?= $F($o['due_total']) ?></td></tr><?php endif ?>
        <?php endif ?>
      </table>
    </td>
  </tr></table>

  <?php if ($d['notes'] !== ''): ?><p class="text"><b>Notes:</b> <?= nl2br(esc($d['notes'])) ?></p><?php endif ?>
  <?php if ($d['terms'] !== ''): ?><p class="text"><b>Terms:</b> <?= nl2br(esc($d['terms'])) ?></p><?php endif ?>

  <?php $signs = ! empty($sg['show']) || ! empty($sg['customer']);
  ob_start(); ?>
  <?php if ($signs): ?>
    <table class="sign"><tr>
      <td style="width:190px"><?php if (! empty($sg['customer'])): ?><div class="box"><div style="height:42px"></div><div class="ln">Customer signature</div></div><?php endif ?></td>
      <td></td>
      <td style="width:190px"><?php if (! empty($sg['show'])): ?><div class="box">
        <div style="height:42px;text-align:center"><?php if ($img['signature']): ?><img src="<?= esc($img['signature'], 'attr') ?>" style="max-height:40px" alt=""><?php endif ?></div>
        <div class="ln">Authorized signature</div>
        <?php if (! empty($sg['name'])): ?><div style="font-weight:bold;color:#111827"><?= esc((string) $sg['name']) ?></div><?php endif ?>
        <?php if (! empty($sg['title'])): ?><div style="color:#6b7280"><?= esc((string) $sg['title']) ?></div><?php endif ?>
      </div><?php endif ?></td></tr></table>
  <?php endif ?>
  <?php if ($d['footer'] !== '' && ! $hi): ?><p class="thanks"><?= esc($d['footer']) ?></p><?php endif ?>
  <?php $bottom = (string) ob_get_clean(); ?>
  <?= $pinned ? '<div class="pin">' . $bottom . '</div>' : $bottom ?>
</div>
<?php if ($fh > 0): ?><img src="<?= esc($img['footer'], 'attr') ?>" style="position:fixed;left:0;bottom:0;width:595pt" alt=""><?php endif ?>
<?php if (! $hi && $fh === 0): ?><div class="foot"><table><tr><td><?= $img['footer'] ? '' : esc($seller['name']) ?><?= $seller['bin'] ? ($img['footer'] ? '' : ' · ') . 'BIN ' . esc($seller['bin']) : '' ?></td><td style="text-align:right"><?= esc($no) ?> · Generated <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></td></tr></table></div><?php endif ?>
</body></html>
