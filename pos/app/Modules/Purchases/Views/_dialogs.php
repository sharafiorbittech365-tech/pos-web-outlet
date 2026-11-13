<?php
/**
 * Add-supplier and pay-supplier dialogs (shared by the purchases pages).
 *
 * @var list<array{id:int,name:string,balance_due:float}> $suppliers
 * @var array<string, string>                            $methods
 * @var int|null                                         $payFor  preselected supplier
 */
helper('ui');
$cur = service('posSession')->currency();
$payFor ??= 0;
?>
<dialog class="modal-dlg" id="dlg-supplier">
  <form method="post" action="<?= esc(site_url('purchases/suppliers'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('userplus', 'sm') ?> Add supplier</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Supplier / company name *<input class="input" name="name" required maxlength="120" autofocus></label>
      <label class="field">Phone<input class="input" name="phone" maxlength="32" placeholder="01XXXXXXXXX"></label>
      <label class="field">Email<input class="input" type="email" name="email" maxlength="160"></label>
      <label class="field span2">Address<input class="input" name="address" maxlength="255"></label>
      <label class="field">Opening balance (owed before)<input class="input" type="number" min="0" step="any" name="opening" value="0"></label>
      <label class="check" style="align-self:end"><input type="checkbox" name="next" value="purchase">Record a purchase next</label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save supplier</button></div>
  </form>
</dialog>

<dialog class="modal-dlg" id="dlg-pay">
  <form method="post" action="<?= esc(site_url('purchases/pay'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('wallet', 'sm') ?> Pay supplier</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Supplier<select class="select" name="supplier_id" required>
        <?php foreach ($suppliers as $s): if ($s['balance_due'] <= 0 && $s['id'] !== $payFor) {
            continue;
        } ?>
          <option value="<?= (int) $s['id'] ?>" <?= selected($payFor, $s['id']) ?>><?= esc($s['name']) ?> — owes <?= esc(money($s['balance_due'], $cur)) ?></option>
        <?php endforeach ?>
      </select></label>
      <label class="field">Amount *<input class="input" type="number" min="0" step="any" name="amount" required></label>
      <label class="field">Method<select class="select" name="method"><?php foreach ($methods as $k => $l): ?><option value="<?= esc($k, 'attr') ?>"><?= esc($l) ?></option><?php endforeach ?></select></label>
      <label class="field">Reference / cheque no.<input class="input" name="reference" maxlength="64"></label>
      <label class="field">Date<input class="input" type="date" name="paid_at" value="<?= esc(date('Y-m-d'), 'attr') ?>" max="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
    </div><small class="muted">The payment settles the oldest unpaid purchases first.</small></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Record payment</button></div>
  </form>
</dialog>
