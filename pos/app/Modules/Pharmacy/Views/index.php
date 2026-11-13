<?php
/**
 * Pharmacy › Alerts: near-expiry and expired batches, low stock; expiry settings.
 *
 * @var string                                 $view       near | expired | low
 * @var string                                 $table
 * @var array<string, mixed>                   $stats
 * @var array{nearDays: int, expiredSale: string} $settings
 * @var bool                                   $canSettings
 * @var int                                    $medicines  rows in the shared medicine database
 * @var array                                  $cur
 */
use App\Modules\Pharmacy\Services\PharmacySettings;

helper('ui');
$chips = [['near', 'Near expiry (' . $settings['nearDays'] . ' days)', $stats['near']], ['expired', 'Expired', $stats['expired']], ['low', 'Low / out of stock', $stats['low'] + $stats['out']]];
?>
<div class="page">
  <div class="page-h">
    <div><h2>Pharmacy</h2><p class="page-sub">Expiry and stock alerts. Sales always take the batch that expires first (FEFO).</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('pharmacy/medicines'), 'attr') ?>"><?= icon('pill', 'sm') ?>Medicine database</a>
    <?php if (service('posSession')->moduleOn('purchases')): ?><a class="btn" href="<?= esc(site_url('purchases'), 'attr') ?>"><?= icon('truck', 'sm') ?>Purchases</a><?php endif ?>
    <?php if ($canSettings): ?><button class="btn" type="button" data-modal-open="ph-settings"><?= icon('setup', 'sm') ?>Settings</button><?php endif ?>
  </div>

  <?php if ($stats['expired'] > 0): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span class="grow"><b><?= (int) $stats['expired'] ?> expired batch<?= $stats['expired'] === 1 ? '' : 'es' ?></b> still on file (<?= esc(qty($stats['expired_qty'])) ?> units, <?= esc(money($stats['expired_value'], $cur)) ?> at cost).
      <?= $settings['expiredSale'] === 'block' ? 'They are never sold — take them off the shelf and write them off.' : 'The till may sell them once unexpired stock runs out (Settings: warn).' ?></span>
      <a class="btn sm" href="<?= esc(site_url('pharmacy?view=expired'), 'attr') ?>">Review</a></div>
  <?php endif ?>

  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Expired batches', 'value' => (string) $stats['expired'], 'color' => 'var(--danger)']) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'calendar', 'label' => 'Expiring in ' . $settings['nearDays'] . ' days', 'value' => (string) $stats['near'], 'color' => 'var(--warn)']) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'box', 'label' => 'Low / out of stock', 'value' => $stats['low'] . ' / ' . $stats['out']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'pill', 'label' => 'Linked to medicine database', 'value' => (string) $stats['linked']]) ?>
  </div>

  <div class="page-h" style="margin-top:4px">
    <?php foreach ($chips as [$k, $l, $n]): ?>
      <a class="chip <?= $view === $k ? 'on' : '' ?>" href="<?= esc(site_url('pharmacy') . '?view=' . $k, 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) $n ?></span></a>
    <?php endforeach ?>
  </div>
  <div class="card"><?= $table ?></div>
  <p class="muted bo-note">Receive stock with batch number and expiry through Purchases (or Inventory › product › Receive batch). Returns go back to the batch they were sold from. Expired-batch policy: <b><?= esc($settings['expiredSale'] === 'block' ? 'block' : 'warn') ?></b>. Shared medicine database: <?= number_format($medicines) ?> medicines.</p>
</div>

<?php if ($canSettings): ?>
<dialog class="modal-dlg" id="ph-settings" aria-labelledby="ph-settings-h">
  <form method="post" action="<?= esc(site_url('pharmacy/settings'), 'attr') ?>"><?= csrf_field() ?>
    <div class="modal-h"><h3 id="ph-settings-h">Pharmacy settings</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Near-expiry window (days)<input class="input" type="number" name="near_days" min="1" max="365" required value="<?= esc((string) (old('near_days') ?? $settings['nearDays']), 'attr') ?>"><?= field_error('near_days') ?></label>
      <div class="field span2">Expired batches at the till<?= field_error('expired_sale') ?>
        <?php foreach (PharmacySettings::EXPIRED as $k => $l): ?>
          <label class="check"><input type="radio" name="expired_sale" value="<?= esc($k, 'attr') ?>" <?= checked((old('expired_sale') ?? $settings['expiredSale']) === $k) ?>><?= esc($l) ?></label>
        <?php endforeach ?>
        <small class="muted">Online orders never take expired batches. Low-stock alerts use each product's level or the outlet default (Setup).</small>
      </div>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
  </form>
</dialog>
<?php endif ?>
