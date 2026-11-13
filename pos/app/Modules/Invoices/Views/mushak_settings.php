<?php
/**
 * Mushak-6.3 settings (permission mushak.settings; every change audited).
 *
 * @var array<string, mixed>       $cfg
 * @var list<array<string, mixed>> $history
 */
helper(['ui', 'form']);
$o = static fn (string $k): string => esc((string) (old($k) ?? $cfg[$k] ?? ''), 'attr');
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/invoices.css'), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <div><h2>Mushak-6.3 VAT invoice</h2><p class="page-sub">For VAT-registered outlets (VAT &amp; SD Act 2012, VAT &amp; SD Rules 2016, rule 40). Enter the details exactly as on your BIN registration certificate.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('invoices'), 'attr') ?>"><?= icon('back', 'sm') ?>Invoices</a>
  </div>
  <div class="sbanner"><?= icon('info', 'sm') ?><span><?= esc(brand_name()) ?> never fills in a BIN or a VAT rate for you: the BIN comes from this page, VAT rates from each product's VAT rule. Check the current NBR requirements with your VAT consultant.</span></div>
  <form class="card bo-form" method="post" action="<?= esc(site_url('mushak/settings'), 'attr') ?>"><?= csrf_field() ?>
    <div class="card-h"><h3>Registered person</h3></div>
    <div class="card-b form-grid">
      <label class="check span2"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" <?= checked(old('enabled') ?? $cfg['enabled']) ?>>This outlet is VAT-registered and issues Mushak-6.3</label>
      <label class="field span2">নিবন্ধিত ব্যক্তির নাম — Registered name<input class="input" name="reg_name" value="<?= $o('reg_name') ?>" maxlength="160"><?= field_error('reg_name') ?></label>
      <label class="field">বিআইএন — BIN<input class="input" name="bin" value="<?= $o('bin') ?>" maxlength="20" inputmode="numeric" placeholder="9 digits (or 13 with branch code)"><?= field_error('bin') ?></label>
      <div></div>
      <label class="field span2">চালানপত্র ইস্যুর ঠিকানা — Address of the issuing premises<input class="input" name="address" value="<?= $o('address') ?>" maxlength="255"><?= field_error('address') ?></label>
      <label class="field">দায়িত্বপ্রাপ্ত ব্যক্তির নাম — Responsible person<input class="input" name="responsible_name" value="<?= $o('responsible_name') ?>" maxlength="100"></label>
      <label class="field">পদবী — Designation<input class="input" name="responsible_title" value="<?= $o('responsible_title') ?>" maxlength="100"></label>
      <label class="check span2"><input type="hidden" name="auto_issue" value="0"><input type="checkbox" name="auto_issue" value="1" <?= checked(old('auto_issue') ?? $cfg['auto_issue']) ?>>Issue the Mushak-6.3 automatically when an invoice is issued</label>
      <p class="muted span2" style="margin:0">Serials run per outlet and fiscal year (1 July – 30 June), e.g. 2026-27/000001. Paper and copies: Setup › Invoice settings › Printing ("Mushak-6.3 VAT invoice", A4 or 80 mm, 2 copies by default — original for the buyer, duplicate kept by you).</p>
    </div>
    <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
  </form>
  <section class="card">
    <div class="card-h"><h3>Change history</h3></div>
    <div class="card-b">
      <ul class="inv-hist">
        <?php foreach ($history as $h): ?>
          <li><div class="meta"><?= esc(datetime_str($h['created_at'])) ?> · <?= esc($h['user_name'] ?? '—') ?></div>
            <?php foreach ($h['changes'] as [$k, $was, $now]): ?><div><b><?= esc($k) ?></b>: <?= esc(is_bool($was) ? ($was ? 'on' : 'off') : (string) $was) ?: '—' ?> → <?= esc(is_bool($now) ? ($now ? 'on' : 'off') : (string) $now) ?: '—' ?></div><?php endforeach ?></li>
        <?php endforeach ?>
        <?php if (! $history): ?><li class="muted">No changes yet.</li><?php endif ?>
      </ul>
    </div>
  </section>
</div>
