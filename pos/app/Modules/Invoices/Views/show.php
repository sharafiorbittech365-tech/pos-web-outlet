<?php
/**
 * Invoice page: the print document in a viewer on the left (Print / Download PDF through the print
 * pipeline), payment collection, payment history and the activity timeline on the right. Phones
 * and tablets: payment & activity first, the document is a tab.
 *
 * @var array<string, mixed>       $inv       InvoiceService::find()
 * @var array<string, mixed>       $d         InvoiceDocs::build()
 * @var list<array<string, mixed>> $revisions
 * @var list<string>               $methods
 * @var list<array<string, string>> $tiles    payment method tiles
 * @var list<array<string, mixed>> $payments  payment history
 * @var list<array<string, mixed>> $timeline
 * @var array<string, array{size: string, copies: int}> $print
 * @var bool                       $mushakOn
 * @var bool                       $canBackdate
 */
helper(['ui', 'pos', 'invoice']);
$s = service('posSession');
$o = $inv['order'];
$id = (int) $inv['id'];
$cur = $d['cur'];
$F = static fn ($n): string => esc(money($n, $cur));
$state = $inv['state'];
$doc = $d['order'];
$due = $o !== null ? (float) $o['due_total'] : (float) $doc['total'];
$canManage = $s->can('invoices.manage');
$canEdit = $canManage && in_array($state, ['draft', 'due', 'partial', 'paid'], true) && $inv['mushak'] === null;
$canPay = $canManage && $o !== null && (float) $o['due_total'] > 0 && in_array($state, ['due', 'partial'], true) && $tiles !== [];
$canVoid = $s->can('invoices.void') && $inv['mushak'] === null && ($state === 'draft' || ($state === 'due' && (float) $o['paid_total'] <= 0));
$canMushak = $mushakOn && $s->can('mushak.issue') && $o !== null && in_array($o['status'], ['paid', 'partial', 'due'], true);
$paper = ['58' => '58 mm', '80' => '80 mm', 'a4' => 'A4', 'a5' => 'A5'][$print['invoice']['size']] ?? '';
$printUrl = site_url('invoices/' . $id . '/print');
$embed = $printUrl . '?embed=1&size=' . (in_array($print['invoice']['size'], ['a4', 'a5'], true) ? $print['invoice']['size'] : 'a4');
$kindIcon = ['created' => 'draft', 'edited' => 'edit', 'issued' => 'file-text', 'revised' => 'edit', 'payment' => 'cash', 'voided' => 'ban', 'mushak' => 'shield', 'return' => 'undo'];
$code = (string) $d['code'];
$smtpOn = \App\Modules\Settings\Services\SmtpSettings::configured();
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/invoices.css') . '?v=' . (is_file(FCPATH . 'assets/pos/invoices.css') ? filemtime(FCPATH . 'assets/pos/invoices.css') : 0), 'attr') ?>">
<div class="page ivx ivx-show" id="ivx">
  <header class="ivx-head">
    <a class="ivx-back" href="<?= esc(site_url('invoices'), 'attr') ?>" aria-label="All invoices"><?= icon('back', 'sm') ?></a>
    <div class="ivx-head-t">
      <h2><?= esc($inv['doc_no'] ?? 'Draft #' . $id) ?> <?= invoice_state_tag($state) ?></h2>
      <p><?= esc($inv['bill_name']) ?> · <?= esc(date_str($d['invoiceDate'])) ?><?php if ($inv['due_date']): ?> · due <?= esc(date_str($inv['due_date'])) ?><?php endif ?><?php if ($inv['mushak']): ?> · Mushak-6.3 <b><?= esc($inv['mushak']['doc_no']) ?></b><?php endif ?></p>
    </div>
    <div class="ivx-head-acts">
      <?php if ($canEdit): ?><a class="btn" href="<?= esc(site_url('invoices/' . $id . '/edit'), 'attr') ?>"><?= icon('edit', 'sm') ?>Edit</a><?php endif ?>
      <?php if ($inv['mushak'] && $mushakOn): ?>
        <a class="btn" href="<?= esc(site_url('mushak/' . $inv['mushak']['id'] . '/print?auto=1'), 'attr') ?>" target="_blank" rel="noopener"><?= icon('shield', 'sm') ?>Print as Mushak-6.3</a>
      <?php elseif ($canMushak): ?>
        <button class="btn" type="button" data-modal-open="mk-dlg"><?= icon('shield', 'sm') ?>Print as Mushak-6.3</button>
      <?php endif ?>
      <?php if ($canVoid): ?><button class="btn danger" type="button" data-modal-open="void-dlg"><?= icon('ban', 'sm') ?>Void</button><?php endif ?>
      <?php if ($state === 'draft' && $canManage): ?><button class="btn primary" type="button" data-modal-open="issue-dlg"><?= icon('file-text', 'sm') ?>Generate invoice</button><?php endif ?>
    </div>
  </header>
  <?php if ($state === 'void' && $inv['void_reason']): ?><div class="sbanner"><?= icon('ban', 'sm') ?><span>Voided <?= esc(datetime_str((string) $inv['voided_at'])) ?> — <?= esc($inv['void_reason']) ?></span></div><?php endif ?>

  <div class="ivx-stats" role="list">
    <div role="listitem"><small>Customer</small><b><?= esc($inv['bill_name']) ?></b><span><?= esc((string) ($inv['bill_phone'] ?: $inv['bill_email'] ?: '')) ?></span></div>
    <div role="listitem"><small>Total</small><b><?= $F($doc['total']) ?></b><span><?= esc((string) $d['taxLabel']) ?> <?= $F($doc['tax_total']) ?></span></div>
    <div role="listitem"><small>Paid</small><b><?= $F($o['paid_total'] ?? 0) ?></b><span><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?></span></div>
    <div role="listitem" class="<?= $o !== null && (float) $o['due_total'] > 0 ? 'is-due' : '' ?>"><small>Balance due</small><b><?= $F($o !== null ? $o['due_total'] : 0) ?></b><span><?= $o === null ? 'Not issued yet' : ((float) $o['due_total'] > 0 ? ($inv['due_date'] ? 'Due ' . esc(date_str($inv['due_date'])) : 'On receipt') : 'Settled') ?></span></div>
  </div>

  <div class="ivx-tabs" role="tablist" aria-label="Invoice page">
    <button type="button" role="tab" data-view="side" id="ivx-tab-side" aria-controls="ivx-side" aria-selected="true">Payment &amp; activity</button>
    <button type="button" role="tab" data-view="doc" id="ivx-tab-doc" aria-controls="ivx-doc" aria-selected="false" tabindex="-1"><?= icon('file-text', 'sm') ?>Invoice</button>
  </div>

  <div class="ivx-grid">
    <section class="ivx-doc" id="ivx-doc" role="tabpanel" aria-labelledby="ivx-tab-doc">
      <div class="ivx-viewer">
        <div class="ivx-viewer-bar">
          <span class="ivx-viewer-t"><?= icon('file-text', 'sm') ?><?= esc(($inv['doc_no'] ?? 'Draft-' . $id) . '.pdf') ?></span>
          <div class="ivx-viewer-acts">
            <a class="btn sm" href="<?= esc($printUrl . '?size=a4&copies=1&auto=1', 'attr') ?>" target="_blank" rel="noopener" title="Print on A4"><?= icon('print', 'sm') ?>Print</a>
            <a class="btn sm" href="<?= esc(site_url('invoices/' . $id . '/pdf'), 'attr') ?>" download title="Download <?= esc(($inv['doc_no'] ?? 'Draft-' . $id) . '.pdf', 'attr') ?>"><?= icon('download', 'sm') ?>PDF</a>
            <?php if ($smtpOn): ?><button class="btn sm" type="button" data-modal-open="mail-dlg" title="Email this invoice as a PDF"><?= icon('mail', 'sm') ?>Email</button><?php endif ?>
            <a class="btn sm" href="<?= esc($printUrl, 'attr') ?>" target="_blank" rel="noopener" data-preview aria-label="Open the document in a new tab"><?= icon('external', 'sm') ?></a>
          </div>
        </div>
        <iframe class="ivx-frame" src="<?= esc($embed, 'attr') ?>" title="Invoice <?= esc($inv['doc_no'] ?? 'draft', 'attr') ?> document" loading="lazy"></iframe>
      </div>
      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('receipt', 'sm') ?>Summary</h3>
        <div class="ivx-sum">
          <div class="ivx-sum-party"><small>Bill to</small><b><?= esc($inv['bill_name']) ?></b>
            <?php foreach (['bill_phone', 'bill_email', 'bill_address'] as $k): ?><?php if (! empty($inv[$k])): ?><span><?= esc($inv[$k]) ?></span><?php endif ?><?php endforeach ?>
            <?php if (! empty($inv['bill_bin'])): ?><span>BIN <?= esc($inv['bill_bin']) ?></span><?php endif ?>
            <?php if (! empty($inv['ship_to'])): ?><small>Ship to</small><span><?= esc($inv['ship_to']) ?></span><?php endif ?></div>
          <ul class="ivx-sum-items">
            <?php foreach ($d['lines'] as $l): ?><li><span><?= esc($l['name']) ?> <small>× <?= esc(qty($l['qty'], $l['unit'] ?: null)) ?></small></span><b><?= $F($l['line_total']) ?></b></li><?php endforeach ?>
          </ul>
          <dl class="ivx-sum-tot">
            <div><dt>Subtotal</dt><dd><?= $F($doc['subtotal']) ?></dd></div>
            <?php if ((float) $doc['discount_total'] > 0): ?><div><dt>Discount</dt><dd>-<?= $F($doc['discount_total']) ?></dd></div><?php endif ?>
            <?php if ((float) $doc['service_charge'] + (float) $doc['packing'] > 0): ?><div><dt>Charges</dt><dd><?= $F((float) $doc['service_charge'] + (float) $doc['packing']) ?></dd></div><?php endif ?>
            <?php foreach ($d['taxRows'] as [$label, $v]): ?><div><dt><?= esc($label) ?></dt><dd><?= $F($v) ?></dd></div><?php endforeach ?>
            <?php if ((float) $doc['round_off'] != 0): ?><div><dt>Round off</dt><dd><?= $F($doc['round_off']) ?></dd></div><?php endif ?>
            <div class="gt"><dt>Total</dt><dd><?= $F($doc['total']) ?></dd></div>
            <?php if ($o !== null): ?>
              <div><dt>Paid</dt><dd><?= $F($o['paid_total']) ?></dd></div>
              <?php if ((float) $o['returned_total'] > 0): ?><div><dt>Returned</dt><dd>-<?= $F($o['returned_total']) ?></dd></div><?php endif ?>
              <div class="bal"><dt>Balance due</dt><dd><?= $F($o['due_total']) ?></dd></div>
            <?php endif ?>
          </dl>
        </div>
      </section>
    </section>

    <aside class="ivx-side" id="ivx-side" role="tabpanel" aria-labelledby="ivx-tab-side">
      <?php if ($canPay): ?>
        <section class="ivx-card ivx-paycard" id="pay">
          <h3 class="ivx-card-h"><?= icon('wallet', 'sm') ?>Collect payment</h3>
          <form method="post" action="<?= esc(site_url('invoices/' . $id . '/pay'), 'attr') ?>"><?= csrf_field() ?>
            <div class="ivx-due"><small>Balance due</small><b><?= $F($o['due_total']) ?></b></div>
            <div class="ivx-2">
              <div class="ivx-field"><label for="p-amt">Amount</label><input class="input" id="p-amt" type="number" name="amount" step="any" min="0.01" value="<?= esc((string) (float) $o['due_total'], 'attr') ?>" required><?= field_error('amount') ?></div>
              <div class="ivx-field"><label for="p-date">Payment date</label><input class="input" id="p-date" type="date" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" <?= $canBackdate ? '' : 'readonly title="Your role records payments for today"' ?>><?= field_error('date') ?></div>
            </div>
            <fieldset class="ivx-tiles"><legend>Payment method</legend>
              <?php foreach ($tiles as $i => $t): ?>
                <label class="ivx-tile"><input type="radio" name="method" value="<?= esc($t['code'], 'attr') ?>" <?= $i === 0 ? 'checked' : '' ?>><span><?= icon($t['icon'], 'sm') ?><b><?= esc($t['label']) ?></b><small><?= esc($t['hint']) ?></small></span></label>
              <?php endforeach ?>
            </fieldset>
            <div class="ivx-field"><label for="p-ref">Reference</label><input class="input" id="p-ref" name="reference" maxlength="64" placeholder="Trx ID / cheque no. / bank slip"></div>
            <button class="btn primary ivx-cta" type="submit"><?= icon('check', 'sm') ?>Record payment</button>
            <small class="ivx-hint">Cash above the balance is given back as change.</small>
          </form>
        </section>
      <?php else: ?>
        <section class="ivx-card ivx-statecard">
          <?php if ($state === 'draft'): ?><b>Draft</b><span>Generate the invoice to number it and take payments.</span>
          <?php elseif ($state === 'paid'): ?><b class="ok"><?= icon('check', 'sm') ?>Paid in full</b><span><?= $o['paid_at'] ? 'Settled ' . esc(datetime_str((string) $o['paid_at'])) : '' ?></span>
          <?php elseif ($state === 'void'): ?><b>Void</b><span>No payments can be taken.</span>
          <?php elseif ($state === 'returned'): ?><b>Refunded</b><span>The items were returned on the Orders page.</span>
          <?php else: ?><b>Balance <?= $F($due) ?></b><span>Your role cannot take payments here.</span><?php endif ?>
        </section>
      <?php endif ?>

      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('cash', 'sm') ?>Payment history</h3>
        <?php if ($payments): ?>
          <ul class="ivx-pays">
            <?php foreach ($payments as $p): ?>
              <li><span class="ivx-pay-ic"><?= icon(pay_icon($p['method']), 'sm') ?></span><div><b><?= esc(pay_label((string) $p['method'], $code)) ?></b><small><?= esc(datetime_str((string) $p['created_at'])) ?><?= ! empty($p['reference']) ? ' · ' . esc($p['reference']) : '' ?><?= ! empty($p['user_name']) ? ' · ' . esc($p['user_name']) : '' ?></small></div><b class="amt"><?= $F($p['amount']) ?></b></li>
            <?php endforeach ?>
          </ul>
        <?php else: ?><p class="ivx-none">No payments yet.</p><?php endif ?>
      </section>

      <section class="ivx-card">
        <h3 class="ivx-card-h"><?= icon('clock', 'sm') ?>Activity</h3>
        <ol class="ivx-tl">
          <?php foreach ($timeline as $t): ?>
            <li class="k-<?= esc($t['kind'], 'attr') ?>"><span class="ivx-tl-dot"><?= icon($kindIcon[$t['kind']] ?? 'info', 'sm') ?></span>
              <div><b><?= esc($t['title']) ?></b><?php if ($t['detail'] !== ''): ?><p><?= esc($t['detail']) ?></p><?php endif ?>
                <small><?= esc(datetime_str($t['at'])) ?><?= $t['user'] ? ' · ' . esc($t['user']) : '' ?><?= $t['rev'] !== null ? ' · rev. ' . (int) $t['rev'] : '' ?></small>
                <?php if ($t['before'] !== null || $t['after'] !== null): ?>
                  <details><summary>Before / after</summary>
                    <?php if ($t['before'] !== null): ?><small>Before</small><pre><?= esc((string) json_encode($t['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif ?>
                    <?php if ($t['after'] !== null): ?><small>After</small><pre><?= esc((string) json_encode($t['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif ?>
                  </details>
                <?php endif ?>
              </div></li>
          <?php endforeach ?>
          <?php if (! $timeline): ?><li class="ivx-none">No activity yet.</li><?php endif ?>
        </ol>
      </section>
    </aside>
  </div>
</div>

<?php if ($state === 'draft' && $canManage): ?>
<dialog class="modal-dlg" id="issue-dlg">
  <form method="post" action="<?= esc(site_url('invoices/' . $id . '/issue'), 'attr') ?>"><?= csrf_field() ?>
    <div class="modal-h"><h3>Generate invoice</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <div class="due-box"><small>Total</small><b><?= $F($doc['total']) ?></b></div>
      <div class="form-grid">
        <label class="field">Amount received now<input class="input" type="number" name="pay_amount" step="any" min="0" value="0"></label>
        <label class="field">Method<select class="select" name="pay_method"><?php foreach ($methods as $m): ?><option value="<?= esc($m, 'attr') ?>"><?= esc(pay_label($m, $code)) ?></option><?php endforeach ?></select></label>
        <label class="field span2">Reference<input class="input" name="pay_reference" maxlength="64"></label>
      </div>
      <div class="muted" style="font-size:12.5px">The invoice gets its number, the stock is taken and what is not paid goes on the customer's due book.</div>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Generate</button></div>
  </form>
</dialog>
<?php endif ?>

<?php if ($smtpOn): ?>
<dialog class="modal-dlg" id="mail-dlg">
  <form method="post" action="<?= esc(site_url('invoices/' . $id . '/email'), 'attr') ?>"><?= csrf_field() ?>
    <div class="modal-h"><h3>Email <?= esc($inv['doc_no'] ?? 'draft') ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <label class="field">To<input class="input" type="email" name="to" required maxlength="160" value="<?= esc((string) ($inv['bill_email'] ?? ''), 'attr') ?>" placeholder="customer@example.com"></label>
      <label class="field">Subject<input class="input" name="subject" maxlength="190" value="<?= esc('Invoice ' . ($inv['doc_no'] ?? 'Draft #' . $id) . ' from ' . $s->business()['name'], 'attr') ?>"></label>
      <label class="field">Message (optional)<textarea class="input" name="message" rows="3" maxlength="2000" placeholder="Please find your invoice attached."></textarea></label>
      <p class="muted" style="margin:0;font-size:12.5px"><?= esc(($inv['doc_no'] ?? 'Draft-' . $id) . '.pdf') ?> is attached automatically.</p>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('send', 'sm') ?>Send email</button></div>
  </form>
</dialog>
<?php endif ?>

<?php if ($canVoid): ?>
<dialog class="modal-dlg" id="void-dlg">
  <form method="post" action="<?= esc(site_url('invoices/' . $id . '/void'), 'attr') ?>"><?= csrf_field() ?>
    <div class="modal-h"><h3>Void <?= esc($inv['doc_no'] ?? 'draft') ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <p><?= $state === 'draft' ? 'The draft is kept in the list as void.' : 'The stock goes back and the due is removed. The number stays used (shown as void).' ?></p>
      <label class="field">Reason<input class="input" name="reason" maxlength="255" <?= $state === 'draft' ? '' : 'required minlength="3"' ?>></label>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn danger" type="submit"><?= icon('ban', 'sm') ?>Void</button></div>
  </form>
</dialog>
<?php endif ?>

<?php if ($canMushak && ! $inv['mushak']): ?>
<dialog class="modal-dlg" id="mk-dlg">
  <form method="post" action="<?= esc(site_url('mushak/order/' . $o['id']), 'attr') ?>"><?= csrf_field() ?>
    <input type="hidden" name="back" value="print">
    <div class="modal-h"><h3>Issue Mushak-6.3</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <p class="muted" style="font-size:12.5px">Gets the next serial of this fiscal year. After issuing, the invoice can no longer be edited or voided.</p>
      <div class="form-grid">
        <label class="field">Buyer BIN<input class="input" name="buyer_bin" value="<?= esc((string) $inv['bill_bin'], 'attr') ?>" maxlength="20" inputmode="numeric"></label>
        <label class="field">Buyer name<input class="input" name="buyer_name" value="<?= esc((string) $inv['bill_name'], 'attr') ?>" maxlength="160"></label>
        <label class="field span2">Buyer address<input class="input" name="buyer_address" value="<?= esc((string) $inv['bill_address'], 'attr') ?>" maxlength="255"></label>
        <label class="field span2">Destination of supply<input class="input" name="destination" value="<?= esc((string) $inv['ship_to'], 'attr') ?>" maxlength="255"></label>
        <label class="field">Vehicle type <small class="muted">(optional)</small><input class="input" name="vehicle_type" maxlength="60"></label>
        <label class="field">Vehicle no.<input class="input" name="vehicle_no" maxlength="40"></label>
      </div>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Issue &amp; print Mushak-6.3</button></div>
  </form>
</dialog>
<?php endif ?>
<script src="<?= esc(base_url('assets/pos/invoices.js') . '?v=' . (is_file(FCPATH . 'assets/pos/invoices.js') ? filemtime(FCPATH . 'assets/pos/invoices.js') : 0), 'attr') ?>"></script>
