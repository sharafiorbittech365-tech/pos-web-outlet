<?php
/**
 * Customer account: the due book (বাকির খাতা) for one customer.
 *
 * @var array<string, mixed>      $c
 * @var string                    $table   ledger DataTable
 * @var list<array>               $open    unpaid invoices
 * @var array<string, mixed>      $orders  invoice count / totals
 * @var array<string, mixed>|null $lastPay
 * @var list<string>              $methods
 * @var list<string>              $groups
 */
helper(['ui', 'pos']);
$s = service('posSession');
$cur = $s->currency();
$F = static fn ($n): string => esc(money($n, $cur));
$due = (float) $c['balance_due'];
$limit = (float) $c['credit_limit'];
$used = $limit > 0 ? min(100, (int) round($due / $limit * 100)) : 0;
$oldest = $open ? (int) floor((time() - strtotime($open[0]['created_at'])) / 86400) : 0;
$code = (string) $s->business()['currency_code'];
?>
<div class="page dbk-page">
  <div class="page-h">
    <div><h2><?= icon('book', 'sm') ?> Due book · <?= esc($c['name']) ?></h2><p class="page-sub"><?= $c['phone'] ? esc($c['phone']) . ' · ' : '' ?><?= (int) $orders['n'] ?> invoices<?= $orders['last_at'] ? ' · last purchase ' . esc(date_str($orders['last_at'])) : '' ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('customers'), 'attr') ?>"><?= icon('back', 'sm') ?>Customers</a>
    <a class="btn" href="<?= esc(site_url('customers/' . $c['id'] . '/statement?auto=1'), 'attr') ?>" target="_blank" rel="noopener"><?= icon('print', 'sm') ?>Print statement</a>
    <?php if ($s->can('customers.manage') && ! (bool) $c['is_walk_in']): ?>
      <button class="btn" type="button" data-modal-open="edit-cust"><?= icon('edit', 'sm') ?>Edit</button>
      <button class="btn" type="button" data-modal-open="due-add"><?= icon('plus', 'sm') ?>Add previous due</button>
    <?php endif ?>
    <?php if ($due > 0 && $s->can('customers.receive')): ?><button class="btn primary" type="button" data-modal-open="receive" id="receive-btn"><?= icon('handcoin', 'sm') ?>Receive payment</button><?php endif ?>
  </div>
  <div class="dbk-top">
    <div class="dbk-due <?= $due > 0 ? 'has' : '' ?>"><small>Current due · বর্তমান বাকি</small><b><?= $F($due) ?></b><?php if ($c['phone']): ?><span><?= icon('phone', 'sm') ?><?= esc($c['phone']) ?></span><?php endif ?></div>
    <div class="dbk-kpi"><small>Exchange credit</small><b><?= $F((float) ($c['exchange_balance'] ?? 0)) ?></b><span>pays a new invoice</span></div>
    <div class="dbk-kpi"><small>Invoiced</small><b><?= $F($orders['v']) ?></b><span><?= (int) $orders['n'] ?> invoices · paid <?= $F($orders['paid']) ?></span></div>
    <div class="dbk-kpi"><small>Last payment</small><b><?= $lastPay ? $F(-(float) $lastPay['amount']) : '—' ?></b><span><?= $lastPay ? esc(date_str($lastPay['created_at'])) : 'No collection yet' ?></span></div>
    <div class="dbk-kpi <?= $oldest > 30 ? 'warn' : '' ?>"><small>Oldest unpaid</small><b><?= $open ? $oldest . ' day' . ($oldest === 1 ? '' : 's') : '—' ?></b><span><?= count($open) ?> open invoice<?= count($open) === 1 ? '' : 's' ?></span></div>
    <?php if ($limit > 0): ?><div class="dbk-kpi"><small>Credit limit used</small><b><?= $used ?>%</b><span class="dbk-bar"><i style="width:<?= $used ?>%"></i></span><span>of <?= $F($limit) ?></span></div><?php endif ?>
  </div>
  <?php if ($open): ?>
    <div class="dbk-open"><b>Unpaid invoices</b>
      <?php foreach ($open as $o): ?><a class="dbk-chip inv" href="<?= esc(site_url('orders/' . $o['id']), 'attr') ?>"><em><?= esc($o['doc_no']) ?></em><small><?= esc(date_str($o['created_at'])) ?></small><b><?= $F($o['due_total']) ?></b></a><?php endforeach ?>
    </div>
  <?php endif ?>
  <section class="card"><div class="card-h"><h3>Credit ledger</h3><span class="muted">Every sale on credit, payment, previous due and return</span></div><?= $table ?></section>
  <section class="card"><div class="card-h"><h3>Exchange ledger</h3><span class="muted">Credit from returned items and where it was used</span></div><?= $exTable ?></section>
</div>

<?php if ($due > 0 && $s->can('customers.receive')): ?>
<dialog class="modal-dlg" id="receive">
  <form method="post" action="<?= esc(site_url('customers/' . $c['id'] . '/collect'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Receive due · <?= esc($c['name']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <div class="due-box"><small>Total due</small><b><?= $F($due) ?></b></div>
      <?php if ($open): ?><table class="tbl"><thead><tr><th>Due item</th><th>Date</th><th class="num">Amount</th><th class="num">Unpaid</th></tr></thead><tbody>
        <?php foreach ($open as $o): ?><tr><td><?= esc($o['doc_no']) ?></td><td><?= esc(date_str($o['created_at'])) ?></td><td class="num"><?= $F($o['total']) ?></td><td class="num"><b><?= $F($o['due_total']) ?></b></td></tr><?php endforeach ?>
      </tbody></table><?php endif ?>
      <div class="form-grid">
        <label class="field">Amount received *<input class="input" type="number" name="amount" step="any" min="0.01" max="<?= esc((string) $due, 'attr') ?>" value="<?= esc((string) $due, 'attr') ?>" required autofocus></label>
        <label class="field">Method<select class="select" name="method"><?php foreach ($methods as $m): ?><option value="<?= esc($m, 'attr') ?>"><?= esc(pay_label($m, $code)) ?></option><?php endforeach ?></select></label>
        <label class="field">Reference<input class="input" name="reference" maxlength="64" placeholder="Trx ID / cheque no."></label>
        <label class="field">Note<input class="input" name="note" maxlength="200" placeholder="e.g. part payment"></label>
      </div>
      <div class="muted" style="font-size:12.5px">Part payment is fine — it pays the oldest due first and the rest stays on the customer's due book.</div>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Receive</button></div>
  </form>
</dialog>
<?php endif ?>

<?php if ($s->can('customers.manage') && ! (bool) $c['is_walk_in']): ?>
<dialog class="modal-dlg" id="due-add">
  <form method="post" action="<?= esc(site_url('customers/' . $c['id'] . '/opening'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Add previous due · <?= esc($c['name']) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <p class="muted" style="margin:0">Add a balance the customer already owed before (for example from your paper বাকির খাতা), or any due without an invoice.</p>
      <div class="form-grid">
        <label class="field">Amount *<input class="input" type="number" name="amount" step="any" min="0.01" required autofocus></label>
        <label class="field">Note<input class="input" name="note" maxlength="200" placeholder="e.g. Khata page 12"></label>
      </div>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add due</button></div>
  </form>
</dialog>
<dialog class="modal-dlg" id="edit-cust">
  <form method="post" action="<?= esc(site_url('customers/' . $c['id']), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Edit customer</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Name *<input class="input" name="name" value="<?= old_or('name', $c['name']) ?>" required maxlength="120"></label>
      <label class="field">Mobile<input class="input" name="phone" value="<?= old_or('phone', $c['phone']) ?>" maxlength="20"></label>
      <label class="field">Email<input class="input" type="email" name="email" value="<?= old_or('email', $c['email']) ?>" maxlength="160"></label>
      <label class="field span2">Address<input class="input" name="address" value="<?= old_or('address', $c['address']) ?>" maxlength="255"></label>
      <label class="field">Credit limit<input class="input" type="number" step="any" min="0" name="credit_limit" value="<?= old_or('credit_limit', $c['credit_limit']) ?>"><small class="muted">0 = no limit</small></label>
      <label class="field">Price group<select class="select" name="price_group"><option value="">—</option><?php foreach ($groups as $g): ?><option <?= selected($c['price_group'], $g) ?>><?= esc($g) ?></option><?php endforeach ?></select></label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
  </form>
</dialog>
<?php endif ?>
<script>
  if (location.hash === '#receive') document.addEventListener('DOMContentLoaded', function () { var b = document.getElementById('receive-btn'); if (b) b.click(); });
</script>
