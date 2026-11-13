<?php
/**
 * @var array<string, mixed>|null $reg   open session
 * @var array<string, mixed>|null $R     its summary
 * @var string                    $table closings history
 */
helper('ui');
$s = service('posSession');
$cur = $s->currency();
?>
<div class="page">
  <?php if ($reg === null): ?>
    <div class="page-h"><h2>Register</h2><span class="tag">Closed</span></div>
    <section class="card reg-open">
      <div class="card-h"><h3><?= icon('register', 'sm') ?> Open the register</h3></div>
      <form method="post" action="<?= esc(site_url('register/open'), 'attr') ?>" class="form-grid" style="padding:14px">
        <?= csrf_field() ?>
        <label class="field">Opening cash in the drawer *<input class="input" type="number" name="opening_cash" step="any" min="0" value="<?= old_or('opening_cash', '0') ?>" required autofocus><?= field_error('opening_cash') ?></label>
        <label class="field">Note<input class="input" name="note" maxlength="200" placeholder="e.g. Morning shift"></label>
        <button class="btn primary span2" type="submit"><?= icon('check', 'sm') ?>Open register</button>
      </form>
    </section>
  <?php else: ?>
    <div class="page-h"><h2>Register</h2><span class="tag ok">Open since <?= esc(datetime_str($reg['opened_at'])) ?></span><div class="spacer"></div>
      <?php if ($s->can('expenses.manage') && $s->moduleOn('expenses')): ?><a class="btn" href="<?= esc(site_url('expenses'), 'attr') ?>"><?= icon('expense', 'sm') ?>Add expense</a><?php endif ?>
      <a class="btn" href="<?= esc(site_url('register/' . $reg['id']), 'attr') ?>"><?= icon('print', 'sm') ?>Report</a>
      <?php if ($s->can('register.close')): ?><button class="btn primary" type="button" data-modal-open="close-reg"><?= icon('register', 'sm') ?>Close register</button><?php endif ?>
    </div>
    <div class="stats">
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Expected cash in drawer', 'value' => money($R['expected'], $cur)]) ?>
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'receipt', 'label' => 'Net sales', 'value' => money($R['net'], $cur)]) ?>
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'orders', 'label' => 'Invoices', 'value' => (string) $R['invoices']]) ?>
      <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'clock', 'label' => 'Pending from customers', 'value' => money($R['outstanding'], $cur)]) ?>
    </div>
    <div class="reg-grid"><?= view('App\Modules\Register\Views\_tables', ['R' => $R]) ?></div>
  <?php endif ?>
  <section class="card"><div class="card-h"><h3>Recent closings</h3></div><?= $table ?></section>
</div>
<?php if ($reg !== null && $s->can('register.close')): ?>
<dialog class="modal-dlg" id="close-reg">
  <form method="post" action="<?= esc(site_url('register/close'), 'attr') ?>" data-confirm="Close the register now?">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>Close register <small class="muted">(<?= esc(datetime_str($reg['opened_at'])) ?> – now)</small></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b">
      <div class="due-box"><small>Expected cash in drawer</small><b><?= esc(money($R['expected'], $cur)) ?></b></div>
      <div class="form-grid">
        <label class="field">Counted cash *<input class="input" type="number" name="counted_cash" id="count-cash" step="any" min="0" value="<?= esc((string) $R['expected'], 'attr') ?>" required autofocus data-expected="<?= esc((string) $R['expected'], 'attr') ?>"></label>
        <div class="field"><span>Card slips / cheques</span><b><?= (int) $R['card_slips'] ?> / <?= (int) $R['cheques'] ?></b></div>
      </div>
      <div class="change-box" id="reg-diff"><span>Difference</span><b><?= esc(money(0, $cur)) ?></b></div>
      <label class="field">Closing note<textarea class="input" name="note" maxlength="200" placeholder="Closing note"></textarea></label>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Close register</button></div>
  </form>
</dialog>
<script>
  (function () {
    var i = document.getElementById('count-cash'), box = document.getElementById('reg-diff'); if (!i) return;
    var sym = <?= json_encode($cur['symbol'] ?? '৳') ?>, dec = <?= (int) ($cur['decimals'] ?? 2) ?>;
    i.addEventListener('input', function () {
      var d = (parseFloat(i.value) || 0) - parseFloat(i.dataset.expected);
      box.className = 'change-box ' + (d < 0 ? 'neg' : d > 0 ? 'pos' : '');
      box.querySelector('b').textContent = (d < 0 ? '-' : '') + sym + Math.abs(d).toFixed(dec);
    });
  })();
</script>
<?php endif ?>
