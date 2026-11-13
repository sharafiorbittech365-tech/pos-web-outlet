<?php
/**
 * Mushak-6.3 (কর চালানপত্র) on A4, laid out as the NBR form (nbr.gov.bd/uploads/form/Mushak_6.3_.pdf):
 * registered person, BIN, issuing address; buyer, buyer BIN, destination; invoice no., issue date and
 * time; the 10-column table and total; name, designation, signature and seal of the responsible
 * person; the withholding note and footnote. Everything printed comes from the frozen copy taken
 * when it was issued. Below the table (outside the form's own fields): discount, net payable,
 * payment method, transport.
 *
 * @var array<string, mixed> $m mushak_invoices row (data decoded)
 * @var array<string, mixed> $b business
 */
helper(['ui', 'pos', 'invoice']);
$x = $m['data'];
$N = static fn ($n): string => esc(mushak_num($n));
$lines = (array) ($x['lines'] ?? []);
$t = (array) ($x['totals'] ?? []);
$en = static fn (string $s): string => '<small class="en">' . esc($s) . '</small>';
$at = strtotime((string) $x['issued_at']);
?>
<?= view('App\Modules\Invoices\Views\docs\_style') ?>
<div class="mk a4">
  <div class="mk-top">
    <div></div>
    <div class="mk-gov"><div>গণপ্রজাতন্ত্রী বাংলাদেশ সরকার</div><b>জাতীয় রাজস্ব বোর্ড</b></div>
    <div class="mk-form">মূসক-৬.৩</div>
  </div>
  <div class="mk-title">কর চালানপত্র <small class="en" style="display:inline">(Tax Invoice)</small></div>
  <div class="mk-rule">[ <?= esc((string) ($x['rule'] ?? 'বিধি ৪০ এর উপ-বিধি (১) এর দফা (গ) ও দফা (চ)')) ?> দ্রষ্টব্য ]</div>

  <div class="mk-sup">
    <span>নিবন্ধিত ব্যক্তির নাম: <?= $en('Name of registered person') ?></span><b><?= esc($x['supplier']['name'] ?? '') ?></b>
    <span>নিবন্ধিত ব্যক্তির বিআইএন: <?= $en('BIN of registered person') ?></span><b><?= esc($x['supplier']['bin'] ?? '') ?></b>
    <span>চালানপত্র ইস্যুর ঠিকানা: <?= $en('Address of issuing premises') ?></span><span><?= esc($x['supplier']['address'] ?? '') ?></span>
  </div>

  <div class="mk-two">
    <div class="mk-kv">
      <span>ক্রেতার নাম: <?= $en("Buyer's name") ?></span><b><?= esc($x['buyer']['name'] ?? '') ?></b>
      <span>ক্রেতার বিআইএন: <?= $en("Buyer's BIN") ?></span><span><?= esc($x['buyer']['bin'] ?? '') ?: '—' ?></span>
      <?php if (! empty($x['buyer']['address'])): ?><span>ক্রেতার ঠিকানা: <?= $en("Buyer's address") ?></span><span><?= esc($x['buyer']['address']) ?></span><?php endif ?>
      <span>সরবরাহের গন্তব্যস্থল: <?= $en('Destination of supply') ?></span><span><?= esc((string) ($x['destination'] ?? '')) ?: '—' ?></span>
    </div>
    <div class="mk-kv">
      <span>চালানপত্র নম্বর: <?= $en('Invoice no.') ?></span><b><?= esc($x['doc_no']) ?></b>
      <span>ইস্যুর তারিখ: <?= $en('Date of issue') ?></span><span><?= esc(date('d/m/Y', $at)) ?></span>
      <span>ইস্যুর সময়: <?= $en('Time of issue') ?></span><span><?= esc(date('h:i A', $at)) ?></span>
    </div>
  </div>

  <table class="mk-tbl">
    <thead><tr>
      <th>ক্রমিক<?= $en('Sl.') ?></th>
      <th>পণ্য বা সেবার বর্ণনা (প্রযোজ্য ক্ষেত্রে ব্র্যান্ড নামসহ)<?= $en('Description of goods / services (with brand name where applicable)') ?></th>
      <th>সরবরাহের একক<?= $en('Unit of supply') ?></th>
      <th>পরিমাণ<?= $en('Quantity') ?></th>
      <th>একক মূল্য¹ (টাকায়)<?= $en('Unit price¹ (Tk)') ?></th>
      <th>মোট মূল্য¹ (টাকায়)<?= $en('Total value¹ (Tk)') ?></th>
      <th>সম্পূরক শুল্কের পরিমাণ (টাকায়)<?= $en('Supplementary duty (Tk)') ?></th>
      <th>মূল্য সংযোজন করের হার/ সুনির্দিষ্ট কর<?= $en('VAT rate / specific tax') ?></th>
      <th>মূল্য সংযোজন কর/ সুনির্দিষ্ট কর এর পরিমান (টাকায়)<?= $en('VAT / specific tax amount (Tk)') ?></th>
      <th>সকল প্রকার শুল্ক ও করসহ মূল্য<?= $en('Value including all duties and taxes') ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($lines as $i => $l): ?>
        <tr>
          <td class="n"><?= $i + 1 ?></td><td><?= esc($l['description']) ?></td><td><?= esc((string) ($l['unit'] ?? '')) ?: '—' ?></td>
          <td class="n"><?= esc(rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ','), '0'), '.')) ?></td><td class="n"><?= $N($l['unit_price']) ?></td><td class="n"><?= $N($l['value']) ?></td>
          <td class="n"><?= $N($l['sd']) ?></td><td class="n"><?= $l['vat_rate'] === null ? 'অব্যাহতি <small class="en">Exempt</small>' : esc(rate_str($l['vat_rate'])) . '%' ?></td>
          <td class="n"><?= $N($l['vat']) ?></td><td class="n"><?= $N($l['total']) ?></td>
        </tr>
      <?php endforeach ?>
    </tbody>
    <tfoot><tr><td colspan="5" style="text-align:right">সর্বমোট <small class="en" style="display:inline">Total</small></td><td class="n"><?= $N($t['value'] ?? 0) ?></td><td class="n"><?= $N($t['sd'] ?? 0) ?></td><td></td><td class="n"><?= $N($t['vat'] ?? 0) ?></td><td class="n"><?= $N($t['total'] ?? 0) ?></td></tr></tfoot>
  </table>

  <div class="mk-extra">
    <div>Sale / invoice no.: <b><?= esc((string) ($x['sale_doc_no'] ?? '')) ?></b></div>
    <div>Discount allowed (included in the values above): <b><?= $N($x['discount'] ?? 0) ?></b></div>
    <?php if ((float) ($x['shipping'] ?? 0) > 0): ?><div>Delivery charge: <b><?= $N($x['shipping']) ?></b></div><?php endif ?>
    <?php if ((float) ($x['round_off'] ?? 0) != 0): ?><div>Rounding: <b><?= $N($x['round_off']) ?></b></div><?php endif ?>
    <div>Net payable: <b><?= $N($x['net_payable'] ?? 0) ?></b> <?= esc((string) ($x['currency'] ?? 'BDT')) ?></div>
    <div>Payment: <b><?= esc(implode(', ', array_map(static fn ($p) => pay_label((string) $p, (string) ($x['currency'] ?? 'BDT')), (array) ($x['payments'] ?? []))) ?: ((float) ($x['due'] ?? 0) > 0 ? 'Credit (due)' : '—')) ?></b><?= (float) ($x['due'] ?? 0) > 0 ? ' · due ' . $N($x['due']) : '' ?></div>
    <?php if (! empty($x['vehicle']['type']) || ! empty($x['vehicle']['no'])): ?><div>যানবাহনের প্রকৃতি ও নম্বর / Vehicle: <b><?= esc(trim(($x['vehicle']['type'] ?? '') . ' ' . ($x['vehicle']['no'] ?? ''))) ?></b></div><?php endif ?>
  </div>

  <div class="mk-sign">
    <span>প্রতিষ্ঠান কর্তৃপক্ষের দায়িত্বপ্রাপ্ত ব্যক্তির নাম: <?= $en('Name of the responsible person') ?></span><span class="ln"><?= esc((string) ($x['responsible']['name'] ?? '')) ?></span>
    <span>পদবী: <?= $en('Designation') ?></span><span class="ln"><?= esc((string) ($x['responsible']['title'] ?? '')) ?></span>
    <span>স্বাক্ষর: <?= $en('Signature') ?></span><span class="ln"></span>
    <span>সীল: <?= $en('Seal') ?></span><span class="ln"></span>
  </div>

  <p class="mk-note">* উৎসে কর্তনযোগ্য সরবরাহের ক্ষেত্রে ফরমটি সমন্বিত কর চালানপত্র ও উৎসে কর কর্তন সনদপত্র হিসেবে বিবেচিত হইবে এবং উহা উৎসে কর কর্তনযোগ্য সরবরাহের ক্ষেত্রে প্রযোজ্য হবে।</p>
  <div class="mk-foot">¹ সকল প্রকার কর ব্যতীত মূল্য <small class="en" style="display:inline">(value excluding all taxes)</small> · <?= esc(brand_name()) ?></div>
</div>
