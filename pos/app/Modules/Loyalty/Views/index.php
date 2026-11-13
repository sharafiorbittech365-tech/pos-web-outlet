<?php
/**
 * @var string               $members
 * @var string               $history
 * @var array<string, int>   $totals
 * @var array<string, mixed> $rules
 * @var bool                 $manage
 * @var array                $cur
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><div><h2>Loyalty points</h2><p class="page-sub">Customers earn 1 point per <?= esc(money($rules['earnPer'], $cur)) ?> of paid sales and redeem points as a discount.</p></div></div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'users', 'label' => 'Members with points', 'value' => (string) $totals['members']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'star', 'label' => 'Points outstanding', 'value' => number_format($totals['points'])]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Liability (value)', 'value' => money($totals['points'] * $rules['pointValue'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'receipt', 'label' => 'Point value', 'value' => money($rules['pointValue'], $cur)]) ?>
  </div>
  <?php if ($manage): ?>
    <section class="card"><div class="card-h"><h3>Rules</h3></div><div class="card-b">
      <form method="post" action="<?= esc(site_url('loyalty/rules'), 'attr') ?>"><?= csrf_field() ?>
        <div class="form-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
          <label class="field">Spend for 1 point<input class="input" type="number" min="1" step="any" name="earn_per" value="<?= esc((string) $rules['earnPer'], 'attr') ?>"><?= field_error('earn_per') ?></label>
          <label class="field">Value of 1 point<input class="input" type="number" min="0" step="any" name="point_value" value="<?= esc((string) $rules['pointValue'], 'attr') ?>"></label>
          <label class="field">Minimum points to redeem<input class="input" type="number" min="0" name="min_redeem" value="<?= (int) $rules['minRedeem'] ?>"></label>
        </div>
        <div class="bo-actions"><button class="btn primary" type="submit">Save rules</button></div>
      </form>
    </div></section>
  <?php endif ?>
  <div class="card"><?= $members ?></div>
  <div class="card"><div class="card-h"><h3>Points history</h3></div><?= $history ?></div>
  <p class="muted bo-note">Points are earned when a sale to a named customer is fully paid and taken back on returns. Redeem them in the POS as a discount.</p>
</div>
<?php if ($manage): ?>
<dialog class="modal-dlg sm" id="dlg-adjust">
  <form method="post" action="<?= esc(site_url('loyalty/adjust'), 'attr') ?>">
    <?= csrf_field() ?><input type="hidden" name="customer_id" id="adj-cust" value="">
    <div class="modal-h"><h3>Adjust points · <b id="adj-name"></b></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b">
      <label class="field">Points (+ add / − remove)<input class="input" type="number" step="1" name="points" required placeholder="e.g. 50 or -20"></label>
      <label class="field">Reason<input class="input" name="note" maxlength="120" placeholder="e.g. Birthday bonus"></label>
    </div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save</button></div>
  </form>
</dialog>
<?php endif ?>
