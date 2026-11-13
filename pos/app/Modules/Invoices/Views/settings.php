<?php
/**
 * Invoices › Invoice page: title, colour, header / footer images, texts, numbering.
 *
 * @var array<string, mixed>          $cfg
 * @var string                        $next    number of the next issued invoice
 * @var array<string, ?string>        $images  kind => URL
 * @var array{size: string, copies: int} $print
 * @var list<array<string, mixed>>    $history
 */
use App\Modules\Invoices\Services\InvoiceSettings;

helper(['ui', 'form']);
$o = static fn (string $k): string => esc((string) (old($k) ?? $cfg[$k] ?? ''), 'attr');
$sizes = ['58' => '58 mm', '80' => '80 mm', 'a4' => 'A4', 'a5' => 'A5'];
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/invoices.css'), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <div><h2>Invoice page</h2><p class="page-sub">How invoices look and are numbered. Changes are kept in the history below.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('invoices'), 'attr') ?>"><?= icon('back', 'sm') ?>Invoices</a>
  </div>

  <section class="card" id="images">
    <div class="card-h"><h3>Images</h3><span class="muted">JPEG, PNG or WebP · up to 2 MB · at most <?= InvoiceSettings::MAX_SIDE ?> px a side</span></div>
    <div class="card-b inv-imgs">
      <?php foreach (InvoiceSettings::IMAGES as $k => $label): ?>
        <div class="inv-img">
          <b><?= esc($label) ?></b>
          <div class="prev"><?php if ($images[$k]): ?><img src="<?= esc($images[$k], 'attr') ?>" alt="<?= esc($label, 'attr') ?>"><?php else: ?>None<?php endif ?></div>
          <form method="post" enctype="multipart/form-data" action="<?= esc(site_url('invoices/settings/image/' . $k), 'attr') ?>"><?= csrf_field() ?>
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required aria-label="<?= esc($label, 'attr') ?>">
            <button class="btn sm" type="submit"><?= icon('download', 'sm') ?>Upload</button>
          </form>
          <?php if ($images[$k]): ?>
            <form method="post" action="<?= esc(site_url('invoices/settings/image/' . $k . '/remove'), 'attr') ?>" data-confirm="Remove the <?= esc(strtolower($label), 'attr') ?>?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Remove</button></form>
          <?php endif ?>
        </div>
      <?php endforeach ?>
    </div>
    <?= field_error('image') ?>
  </section>

  <form class="card bo-form" method="post" action="<?= esc(site_url('invoices/settings'), 'attr') ?>"><?= csrf_field() ?>
    <div class="card-h"><h3>Page &amp; numbering</h3></div>
    <div class="card-b form-grid">
      <label class="field">Heading<input class="input" name="title" value="<?= $o('title') ?>" maxlength="40" required><?= field_error('title') ?></label>
      <label class="field">Accent colour <small class="muted">(empty = theme colour)</small><input class="input" name="accent" value="<?= $o('accent') ?>" maxlength="7" placeholder="#4F46E5"><?= field_error('accent') ?></label>
      <fieldset class="field span2 inv-tpls"><legend>Design</legend>
        <?php foreach (InvoiceSettings::TEMPLATES as $k => $l): [$name, $hint] = explode(' — ', $l . ' — '); ?>
          <label class="inv-tpl"><input type="radio" name="template" value="<?= esc($k, 'attr') ?>" <?= (string) (old('template') ?? $cfg['template']) === $k ? 'checked' : '' ?>><span class="tpl-thumb tpl-<?= esc($k, 'attr') ?>" aria-hidden="true"<?php if (isset(InvoiceSettings::THEMES[$k])): [$c1, $c2] = InvoiceSettings::THEMES[$k]; ?> style="--c1:<?= $c1 ?>;--c2:<?= $c2 ?>"<?php endif ?>><i></i><i></i><i></i><i></i></span><b><?= esc($name) ?></b><small><?= esc(rtrim($hint, ' —')) ?></small></label>
        <?php endforeach ?>
        <?= field_error('template') ?>
      </fieldset>
      <label class="check span2"><input type="hidden" name="show_logo" value="0"><input type="checkbox" name="show_logo" value="1" <?= checked(old('show_logo') ?? $cfg['show_logo']) ?>>Print the logo on invoices, receipts and challans <small class="muted">(your website logo from Setup › Website, else the Setup symbol)</small></label>
      <fieldset class="field span2 inv-hmode"><legend>Header style</legend>
        <?php $hm = (string) (old('header_mode') ?? $cfg['header_mode'] ?? 'image') === 'logo' ? 'logo' : 'image'; ?>
        <div class="hm-opts">
          <label class="hm" title="Uses the header &amp; footer images below. Shows only the middle content; falls back to the logo template when no header image is set."><input type="radio" name="header_mode" value="image" <?= $hm === 'image' ? 'checked' : '' ?>><span><?= icon('image', 'sm') ?>Image template</span></label>
          <label class="hm" title="Prints your logo, business name and address. Uploaded images are kept but not used."><input type="radio" name="header_mode" value="logo" <?= $hm === 'logo' ? 'checked' : '' ?>><span><?= icon('tag', 'sm') ?>Logo template</span></label>
        </div>
        <small class="muted">Image template: header &amp; footer images only · Logo template: logo, name and address.</small>
      </fieldset>
      <style>
        .inv-hmode .hm-opts{display:inline-flex;gap:0;border:1px solid var(--line,#e5e7eb);border-radius:10px;overflow:hidden;background:var(--surface,#fff);width:max-content;max-width:100%}
        .inv-hmode .hm{position:relative;cursor:pointer;margin:0}
        .inv-hmode .hm input{position:absolute;opacity:0;inset:0;cursor:pointer}
        .inv-hmode .hm span{display:flex;align-items:center;gap:6px;padding:7px 14px;font-size:13px;font-weight:600;color:var(--muted,#64748b);white-space:nowrap}
        .inv-hmode .hm + .hm span{border-left:1px solid var(--line,#e5e7eb)}
        .inv-hmode .hm input:checked + span{background:var(--primary,#4F46E5);color:#fff}
        .inv-hmode .hm input:focus-visible + span{outline:2px solid var(--primary,#4F46E5);outline-offset:-2px}
        .inv-hmode small{display:block;margin-top:6px;font-size:12px}
      </style>
      <fieldset class="field span2 inv-signset"><legend>Signatures</legend>
        <label class="check"><input type="hidden" name="sign_customer" value="0"><input type="checkbox" name="sign_customer" value="1" <?= checked(old('sign_customer') ?? $cfg['sign_customer']) ?>>Print a customer signature line on invoices</label>
        <label class="check"><input type="hidden" name="sign_show" value="0"><input type="checkbox" name="sign_show" value="1" <?= checked(old('sign_show') ?? $cfg['sign_show']) ?>>Print an authorized signature block on invoices and challans</label>
        <div class="form-grid">
          <label class="field">Name under the signature <small class="muted">(optional)</small><input class="input" name="sign_name" value="<?= $o('sign_name') ?>" maxlength="80" placeholder="e.g. Arif Hossain"></label>
          <label class="field">Title <small class="muted">(optional)</small><input class="input" name="sign_title" value="<?= $o('sign_title') ?>" maxlength="80" placeholder="e.g. Managing Director"></label>
        </div>
        <small class="muted">Upload a signature or stamp image under Images above — it prints over the line. Without one, the line is left blank to sign by hand.</small>
      </fieldset>
      <label class="field">Number prefix<input class="input" name="prefix" value="<?= $o('prefix') ?>" maxlength="12" placeholder="INV-"><?= field_error('prefix') ?></label>
      <label class="field">Digits (0 = no padding)<input class="input" type="number" min="0" max="8" name="pad" value="<?= $o('pad') ?>"><?= field_error('pad') ?></label>
      <label class="field">Start numbering again<select class="select" name="reset"><?php foreach (InvoiceSettings::RESETS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected((string) (old('reset') ?? $cfg['reset']), $k) ?>><?= esc($l) ?></option><?php endforeach ?></select><?= field_error('reset') ?></label>
      <div class="due-box"><small>Next invoice</small><b><?= esc($next) ?></b></div>
      <label class="field span2">Default terms<textarea class="input" name="terms" rows="3" maxlength="2000"><?= esc((string) (old('terms') ?? $cfg['terms'])) ?></textarea></label>
      <label class="field span2">Default notes<textarea class="input" name="notes" rows="2" maxlength="2000"><?= esc((string) (old('notes') ?? $cfg['notes'])) ?></textarea></label>
      <label class="field span2">Payment details (bank, mobile wallet)<textarea class="input" name="bank" rows="2" maxlength="500"><?= esc((string) (old('bank') ?? $cfg['bank'])) ?></textarea></label>
      <label class="field span2">Thank-you line<input class="input" name="footer_text" value="<?= $o('footer_text') ?>" maxlength="200"></label>
      <p class="muted span2" style="margin:0">Paper: invoices print on <b><?= esc($sizes[$print['size']] ?? $print['size']) ?></b><?= $print['copies'] > 1 ? ' × ' . (int) $print['copies'] : '' ?> — change it in Setup › Invoice settings › Printing.</p>
    </div>
    <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
  </form>

  <section class="card">
    <div class="card-h"><h3>Change history</h3></div>
    <div class="card-b">
      <ul class="inv-hist">
        <?php foreach ($history as $h): ?>
          <li><div class="meta"><?= esc(datetime_str($h['created_at'])) ?> · <?= esc($h['user_name'] ?? '—') ?></div>
            <?php foreach ($h['changes'] as [$k, $was, $now]): ?><div><b><?= esc($k) ?></b>: <?= esc(is_scalar($was) ? (string) $was : json_encode($was)) ?: '—' ?> → <?= esc(is_scalar($now) ? (string) $now : json_encode($now)) ?: '—' ?></div><?php endforeach ?></li>
        <?php endforeach ?>
        <?php if (! $history): ?><li class="muted">No changes yet.</li><?php endif ?>
      </ul>
    </div>
  </section>
</div>
