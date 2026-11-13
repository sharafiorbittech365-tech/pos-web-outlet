<?php
/**
 * The invoice on a 58 / 80 mm roll. Receipt markup (.receipt .c / .r / hr) so Bluetooth / RawBT
 * thermal printers print it as text (assets/pos/print.js). VAT-registered outlets also print the
 * NBR details: seller and buyer BIN, issue date and time, and per line the value without VAT, VAT
 * rate / amount and value with VAT (same figures as the Mushak-6.3).
 *
 * @var array<string, mixed> $d     InvoiceDocs::build()
 * @var int                  $width 58 | 80
 */
helper(['ui', 'pos']);
$o = $d['order'];
$b = $d['business'];
$inv = $d['invoice'];
$pg = $d['page'];
$seller = $d['seller'];
$nbr = $d['nbr'] && $d['nbrRows'] !== null;
$F = static fn ($n): string => esc(money($n, $d['cur']));
$N = static fn ($n): string => esc(number_format((float) $n, (int) $d['cur']['decimals']));
$Q = static fn ($n): string => esc(rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.'));
$time = $d['issuedAt'] ? date('h:i A', strtotime((string) $d['issuedAt'])) : null;
?>
<div class="receipt w<?= (int) $width ?> ivr">
  <div class="c">
    <?php if (($d['showLogo'] ?? true) && ! empty($d['logoUrl'])): ?><img class="rc-logo" src="<?= esc((string) $d['logoUrl'], 'attr') ?>" alt=""><?php endif ?>
    <div class="big"><?= esc($seller['name']) ?></div>
    <?php if ($seller['reg_name']): ?><div><?= esc($seller['reg_name']) ?></div><?php endif ?>
    <?php if ($seller['address'] !== ''): ?><div><?= esc($seller['address']) ?></div><?php endif ?>
    <?php if (! empty($b['phone'])): ?><div>Phone: <?= esc($b['phone']) ?></div><?php endif ?>
    <?php if ($seller['bin']): ?><div>BIN: <?= esc($seller['bin']) ?></div><?php endif ?>
  </div>
  <hr>
  <div class="c rc-title"><?= esc(mb_strtoupper((string) $pg['title'])) ?><?= $d['draft'] ? ' (DRAFT)' : ($d['void'] ? ' (VOID)' : '') ?></div>
  <div class="r"><span>Invoice</span><span><?= esc($d['draft'] ? 'Draft #' . $inv['id'] : ($inv['doc_no'] ?: $o['doc_no'])) ?></span></div>
  <?php if (! $d['draft'] && ! empty($inv['doc_no']) && (string) $inv['doc_no'] !== (string) $o['doc_no']): ?><div class="r"><span>Sale no.</span><span><?= esc($o['doc_no']) ?></span></div><?php endif ?>
  <div class="r"><span>Date</span><span><?= esc(date_str($d['invoiceDate'])) ?><?= $time !== null ? ' ' . esc($time) : '' ?></span></div>
  <?php if (! empty($inv['due_date'])): ?><div class="r"><span>Due</span><span><?= esc(date_str($inv['due_date'])) ?></span></div><?php endif ?>
  <?php if (! empty($d['mushakDoc'])): ?><div class="r"><span>Mushak-6.3</span><span><?= esc($d['mushakDoc']['doc_no']) ?></span></div><?php endif ?>
  <div class="r"><span>Customer</span><span><?= esc($inv['bill_name'] ?: $o['customer_name']) ?></span></div>
  <?php if (! empty($inv['bill_phone'])): ?><div class="r"><span>Phone</span><span><?= esc($inv['bill_phone']) ?></span></div><?php endif ?>
  <?php if (! empty($inv['bill_bin'])): ?><div class="r"><span>Buyer BIN</span><span><?= esc($inv['bill_bin']) ?></span></div><?php endif ?>
  <?php if (! empty($inv['ship_to'])): ?><div class="r"><span>Ship to</span><span><?= esc($inv['ship_to']) ?></span></div><?php endif ?>
  <hr>
  <?php if ($nbr): ?>
    <?php foreach ($d['nbrRows']['lines'] as $i => $l): ?>
      <div><?= $i + 1 ?>. <?= esc($l['description']) ?></div>
      <div class="r"><span><?= $Q($l['qty']) ?><?= ! empty($l['unit']) ? ' ' . esc($l['unit']) : '' ?> x <?= $N($l['unit_price']) ?></span><span><?= $N($l['value']) ?></span></div>
      <div class="r"><span>VAT <?= $l['vat_rate'] === null ? 'exempt' : esc(rate_str($l['vat_rate'])) . '%' ?></span><span><?= $N($l['vat']) ?></span></div>
      <div class="r"><span>Incl. VAT</span><span><?= $N($l['total']) ?></span></div>
    <?php endforeach ?>
    <hr>
    <?php if ((float) $o['discount_total'] > 0): ?><div class="r"><span>Discount (in values)</span><span><?= $N($o['discount_total']) ?></span></div><?php endif ?>
    <div class="r"><span>Value excl. VAT</span><span><?= $N($d['nbrRows']['totals']['value']) ?></span></div>
    <?php if (! empty($d['nbrSd'])): ?><div class="r"><span>SD</span><span><?= $N($d['nbrRows']['totals']['sd']) ?></span></div><?php endif ?>
    <div class="r"><span>VAT</span><span><?= $N($d['nbrRows']['totals']['vat']) ?></span></div>
  <?php else: ?>
    <?php foreach ($d['lines'] as $l): ?>
      <div><?= esc($l['name']) ?></div>
      <div class="r"><span><?= esc(qty($l['qty'], $l['unit'] ?: null)) ?> x <?= $F($l['net_unit']) ?></span><span><?= $F($l['line_total']) ?></span></div>
    <?php endforeach ?>
    <hr>
    <div class="r"><span>Subtotal</span><span><?= $F($o['subtotal']) ?></span></div>
    <?php if ((float) $o['discount_total'] > 0): ?><div class="r"><span>Discount</span><span>-<?= $F($o['discount_total']) ?></span></div><?php endif ?>
    <?php foreach ($d['taxRows'] as [$label, $v]): ?><div class="r"><span><?= esc($label) ?></span><span><?= $F($v) ?></span></div><?php endforeach ?>
  <?php endif ?>
  <?php if ((float) $o['shipping'] > 0): ?><div class="r"><span>Delivery</span><span><?= $F($o['shipping']) ?></span></div><?php endif ?>
  <?php if ((float) $o['round_off'] != 0): ?><div class="r"><span>Round off</span><span><?= $F($o['round_off']) ?></span></div><?php endif ?>
  <div class="r tot"><span>TOTAL</span><span><?= $F($o['total']) ?></span></div>
  <?php if (! $d['draft']): ?>
    <div class="r"><span>Paid</span><span><?= $F($o['paid_total']) ?></span></div>
    <?php if ((float) $o['due_total'] > 0): ?><div class="r"><span>Balance due</span><span><?= $F($o['due_total']) ?></span></div><?php endif ?>
  <?php endif ?>
  <div><small><?= esc(amount_words((float) $o['total'], $d['code'])) ?></small></div>
  <?php if ($d['notes'] !== ''): ?><hr><div><small><?= esc($d['notes']) ?></small></div><?php endif ?>
  <?php if ($d['terms'] !== ''): ?><div><small><?= esc($d['terms']) ?></small></div><?php endif ?>
  <?php if ($nbr && empty($d['mushakDoc'])): ?><hr><div class="c"><small>This is a commercial invoice, not the Mushak-6.3 tax invoice.</small></div><?php endif ?>
  <?php if ($d['footer'] !== ''): ?><hr><div class="c"><?= esc($d['footer']) ?></div><?php endif ?>
</div>
