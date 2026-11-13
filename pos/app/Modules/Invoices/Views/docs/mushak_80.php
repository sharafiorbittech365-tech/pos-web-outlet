<?php
/**
 * Mushak-6.3 on an 80 mm roll: every field of the NBR form, one line item per block (the 10 table
 * columns as labelled rows), totals, responsible person, the withholding note and footnote.
 * Built from the receipt markup (.receipt .c / .r / hr) so thermal printers print it as text.
 *
 * @var array<string, mixed> $m mushak_invoices row (data decoded)
 * @var array<string, mixed> $b business
 */
helper(['ui', 'pos', 'invoice']);
$x = $m['data'];
$N = static fn ($n): string => esc(mushak_num($n));
$t = (array) ($x['totals'] ?? []);
$at = strtotime((string) $x['issued_at']);
?>
<?= view('App\Modules\Invoices\Views\docs\_style') ?>
<div class="receipt w80 mk">
  <div class="c">
    <div>গণপ্রজাতন্ত্রী বাংলাদেশ সরকার</div>
    <div><b>জাতীয় রাজস্ব বোর্ড</b></div>
    <div class="big">মূসক-৬.৩ · কর চালানপত্র</div>
    <div>[বিধি ৪০ এর উপ-বিধি (১) এর দফা (গ) ও দফা (চ) দ্রষ্টব্য]</div>
  </div>
  <hr>
  <div class="r"><span>নিবন্ধিত ব্যক্তির নাম</span><span><?= esc($x['supplier']['name'] ?? '') ?></span></div>
  <div class="r"><span>নিবন্ধিত ব্যক্তির বিআইএন</span><span><?= esc($x['supplier']['bin'] ?? '') ?></span></div>
  <div class="r"><span>চালানপত্র ইস্যুর ঠিকানা</span><span><?= esc($x['supplier']['address'] ?? '') ?></span></div>
  <hr>
  <div class="r"><span>ক্রেতার নাম</span><span><?= esc($x['buyer']['name'] ?? '') ?></span></div>
  <div class="r"><span>ক্রেতার বিআইএন</span><span><?= esc((string) ($x['buyer']['bin'] ?? '')) ?: '—' ?></span></div>
  <?php if (! empty($x['buyer']['address'])): ?><div class="r"><span>ক্রেতার ঠিকানা</span><span><?= esc($x['buyer']['address']) ?></span></div><?php endif ?>
  <div class="r"><span>সরবরাহের গন্তব্যস্থল</span><span><?= esc((string) ($x['destination'] ?? '')) ?: '—' ?></span></div>
  <div class="r"><span>চালানপত্র নম্বর</span><span><?= esc($x['doc_no']) ?></span></div>
  <div class="r"><span>ইস্যুর তারিখ</span><span><?= esc(date('d/m/Y', $at)) ?></span></div>
  <div class="r"><span>ইস্যুর সময়</span><span><?= esc(date('h:i A', $at)) ?></span></div>
  <hr>
  <?php foreach ((array) ($x['lines'] ?? []) as $i => $l): ?>
    <div class="mk-line">
      <div><?= $i + 1 ?>. <?= esc($l['description']) ?></div>
      <div class="r"><span>একক / পরিমাণ</span><span><?= esc((string) ($l['unit'] ?? '—')) ?> × <?= esc(rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ','), '0'), '.')) ?></span></div>
      <div class="r"><span>একক মূল্য¹</span><span><?= $N($l['unit_price']) ?></span></div>
      <div class="r"><span>মোট মূল্য¹</span><span><?= $N($l['value']) ?></span></div>
      <div class="r"><span>সম্পূরক শুল্ক</span><span><?= $N($l['sd']) ?></span></div>
      <div class="r"><span>মূসক হার</span><span><?= $l['vat_rate'] === null ? 'অব্যাহতি' : esc(rate_str($l['vat_rate'])) . '%' ?></span></div>
      <div class="r"><span>মূসক</span><span><?= $N($l['vat']) ?></span></div>
      <div class="r"><span>সকল শুল্ক ও করসহ মূল্য</span><span><?= $N($l['total']) ?></span></div>
    </div>
  <?php endforeach ?>
  <hr>
  <div class="r"><span>সর্বমোট মূল্য¹</span><span><?= $N($t['value'] ?? 0) ?></span></div>
  <div class="r"><span>সর্বমোট সম্পূরক শুল্ক</span><span><?= $N($t['sd'] ?? 0) ?></span></div>
  <div class="r"><span>সর্বমোট মূসক</span><span><?= $N($t['vat'] ?? 0) ?></span></div>
  <div class="r tot"><span>সর্বমোট</span><span><?= $N($t['total'] ?? 0) ?></span></div>
  <hr>
  <div class="r"><span>Sale no.</span><span><?= esc((string) ($x['sale_doc_no'] ?? '')) ?></span></div>
  <div class="r"><span>Discount (in values)</span><span><?= $N($x['discount'] ?? 0) ?></span></div>
  <?php if ((float) ($x['shipping'] ?? 0) > 0): ?><div class="r"><span>Delivery</span><span><?= $N($x['shipping']) ?></span></div><?php endif ?>
  <?php if ((float) ($x['round_off'] ?? 0) != 0): ?><div class="r"><span>Rounding</span><span><?= $N($x['round_off']) ?></span></div><?php endif ?>
  <div class="r tot"><span>Net payable</span><span><?= $N($x['net_payable'] ?? 0) ?></span></div>
  <div class="r"><span>Payment</span><span><?= esc(implode(', ', array_map(static fn ($p) => pay_label((string) $p, (string) ($x['currency'] ?? 'BDT')), (array) ($x['payments'] ?? []))) ?: '—') ?></span></div>
  <?php if (! empty($x['vehicle']['type']) || ! empty($x['vehicle']['no'])): ?><div class="r"><span>যানবাহন</span><span><?= esc(trim(($x['vehicle']['type'] ?? '') . ' ' . ($x['vehicle']['no'] ?? ''))) ?></span></div><?php endif ?>
  <hr>
  <div>দায়িত্বপ্রাপ্ত ব্যক্তির নাম: <?= esc((string) ($x['responsible']['name'] ?? '')) ?></div>
  <div>পদবী: <?= esc((string) ($x['responsible']['title'] ?? '')) ?></div>
  <div>স্বাক্ষর:</div>
  <div>সীল:</div>
  <hr>
  <div><small>* উৎসে কর্তনযোগ্য সরবরাহের ক্ষেত্রে ফরমটি সমন্বিত কর চালানপত্র ও উৎসে কর কর্তন সনদপত্র হিসেবে বিবেচিত হইবে।</small></div>
  <div><small>¹ সকল প্রকার কর ব্যতীত মূল্য</small></div>
</div>
