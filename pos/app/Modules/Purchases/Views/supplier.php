<?php
/**
 * Supplier profile & ledger.
 *
 * @var array<string, mixed>  $s
 * @var list<array>           $rows
 * @var array                 $cur
 * @var array<string, string> $methods
 */
helper('ui');
$b = service('posSession')->business();
$purchased = array_sum(array_map(static fn (array $r): float => $r['kind'] === 'pu' ? $r['debit'] : 0.0, $rows));
$paid = array_sum(array_column($rows, 'credit'));
$n = count(array_filter($rows, static fn (array $r): bool => $r['kind'] === 'pu'));
?>
<div class="page">
  <div class="page-h">
    <div><h2><?= icon('truck', 'sm') ?> <?= esc($s['name']) ?></h2><p class="page-sub"><a class="link" href="<?= esc(site_url('purchases?tab=suppliers'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Suppliers</a></p></div>
    <div class="spacer"></div>
    <button class="btn" type="button" data-modal-open="dlg-edit-supplier"><?= icon('edit', 'sm') ?>Edit</button>
    <?php if ($canBuy): ?><a class="btn" href="<?= esc(site_url('purchases/new?supplier=' . $s['id']), 'attr') ?>"><?= icon('plus', 'sm') ?>New purchase</a><?php endif ?>
    <?php if ((float) $s['balance_due'] > 0 && $canPay): ?><button class="btn primary" type="button" data-modal-open="dlg-pay"><?= icon('wallet', 'sm') ?>Pay supplier</button><?php endif ?>
    <button class="btn" type="button" data-print><?= icon('print', 'sm') ?>Print</button>
  </div>
  <div class="stats pu-kpi">
    <div class="stat"><small>Total purchased</small><b><?= esc(money($purchased, $cur)) ?></b></div>
    <div class="stat"><small>Paid</small><b><?= esc(money($paid, $cur)) ?></b></div>
    <div class="stat"><small>Due now</small><b style="color:<?= (float) $s['balance_due'] > 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= esc(money($s['balance_due'], $cur)) ?></b></div>
    <div class="stat"><small>Purchases</small><b><?= $n ?></b></div>
  </div>
  <?php if ($s['phone'] || $s['address'] || $s['email']): ?><p class="muted" style="margin:0"><?= esc(implode(' · ', array_filter([$s['phone'], $s['address'], $s['email']]))) ?></p><?php endif ?>
  <section class="card report">
    <div class="report-head"><b><?= esc($b['name'] ?? '') ?></b> · Supplier ledger · <?= esc($s['name']) ?> · as of <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></div>
    <div class="table-wrap"><table class="tbl pu-ledger"><thead><tr><th>Date</th><th>Particulars</th><th class="num">Purchase</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= esc($r['kind'], 'attr') ?>"><td><?= esc(date_str($r['at'])) ?></td>
          <td><?= $r['kind'] === 'pu' ? '<a class="link" href="' . esc(site_url('purchases/' . $r['id']), 'attr') . '">' . esc($r['ref']) . '</a>' : esc($r['ref']) ?></td>
          <td class="num"><?= $r['debit'] ? esc(money($r['debit'], $cur)) : '' ?></td><td class="num"><?= $r['credit'] ? esc(money($r['credit'], $cur)) : '' ?></td><td class="num"><b><?= esc(money($r['bal'], $cur)) ?></b></td></tr>
      <?php endforeach ?>
      <?php if (! $rows): ?><tr><td colspan="5" class="muted">No purchases or payments yet.</td></tr><?php endif ?>
      <tr class="pu-gt"><td></td><td><b>Payable to <?= esc($s['name']) ?></b></td><td></td><td></td><td class="num"><b><?= esc(money($s['balance_due'], $cur)) ?></b></td></tr>
    </tbody></table></div>
  </section>
  <?php if ($n === 0 && (float) $s['balance_due'] <= 0): ?>
    <form method="post" action="<?= esc(site_url('purchases/suppliers/' . $s['id'] . '/delete'), 'attr') ?>" data-confirm="Delete <?= esc($s['name'], 'attr') ?>?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Delete supplier</button></form>
  <?php endif ?>
</div>
<dialog class="modal-dlg" id="dlg-edit-supplier">
  <form method="post" action="<?= esc(site_url('purchases/suppliers/' . $s['id']), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Edit supplier</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Name *<input class="input" name="name" value="<?= esc($s['name'], 'attr') ?>" required maxlength="120"></label>
      <label class="field">Phone<input class="input" name="phone" value="<?= esc((string) $s['phone'], 'attr') ?>" maxlength="32"></label>
      <label class="field">Email<input class="input" type="email" name="email" value="<?= esc((string) $s['email'], 'attr') ?>" maxlength="160"></label>
      <label class="field span2">Address<input class="input" name="address" value="<?= esc((string) $s['address'], 'attr') ?>" maxlength="255"></label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save</button></div>
  </form>
</dialog>
<?= view('App\Modules\Purchases\Views\_dialogs', ['suppliers' => [['id' => (int) $s['id'], 'name' => $s['name'], 'balance_due' => (float) $s['balance_due']]], 'methods' => $methods, 'payFor' => (int) $s['id']]) ?>
