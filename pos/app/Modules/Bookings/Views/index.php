<?php
/**
 * @var string                $table
 * @var string                $filter
 * @var array<string, int>    $counts
 * @var string                $kind
 * @var array<string, array>  $kinds
 * @var list<array>           $services
 * @var list<string>          $staff
 * @var bool                  $canAct
 */
helper('ui');
$b = service('posSession')->business();
$label = $kinds[$kind][0];
$seatWord = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []))['seat'];
?>
<div class="page">
  <div class="page-h">
    <div><h2>Bookings</h2><p class="page-sub"><?= esc($label) ?>s from your website, phone and walk-ins. Confirm, then start the sale when the customer arrives.</p></div>
    <div class="spacer"></div>
    <?php if ($canAct): ?><button class="btn primary" type="button" data-modal-open="dlg-booking"><?= icon('plus', 'sm') ?>New booking</button><?php endif ?>
  </div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'calendar', 'label' => 'Today', 'value' => (string) $counts['today']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Waiting to confirm', 'value' => (string) $counts['pending'], 'color' => $counts['pending'] ? 'var(--warn)' : null]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'check', 'label' => 'Upcoming confirmed', 'value' => (string) $counts['upcoming']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'globe', 'label' => 'Online · 7 days', 'value' => (string) $counts['web7']]) ?>
  </div>
  <div class="seg"><?php foreach (['upcoming' => 'Upcoming', 'today' => 'Today', 'pending' => 'New', 'all' => 'All'] as $k => $l): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="<?= esc(site_url('bookings') . ($k === 'upcoming' ? '' : '?f=' . $k), 'attr') ?>"><?= esc($l) ?></a><?php endforeach ?></div>
  <div class="card"><?= $table ?></div>
</div>
<?php if ($canAct): ?>
<dialog class="modal-dlg" id="dlg-booking">
  <form method="post" action="<?= esc(site_url('bookings'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon($kinds[$kind][1], 'sm') ?> New <?= esc(strtolower($label)) ?></h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field">Customer name *<input class="input" name="customer_name" required maxlength="120" autofocus></label>
      <label class="field">Phone *<input class="input" name="customer_phone" required inputmode="tel" maxlength="20"></label>
      <label class="field">Date<input class="input" type="date" name="date" value="<?= esc(date('Y-m-d'), 'attr') ?>" min="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
      <label class="field">Time<input class="input" type="time" name="time" value="<?= esc(date('H:00', strtotime('+2 hours')), 'attr') ?>"></label>
      <?php if (in_array($kind, ['appointment', 'visit', 'repair'], true) && $services): ?>
        <label class="field">Service<select class="select" name="product_id"><option value="">—</option><?php foreach ($services as $sv): ?><option value="<?= (int) $sv['id'] ?>"><?= esc($sv['name']) ?><?= $sv['duration'] ? ' · ' . (int) $sv['duration'] . ' min' : '' ?></option><?php endforeach ?></select></label>
      <?php endif ?>
      <?php if ($staff): ?>
        <label class="field"><?= $kind === 'appointment' ? 'Stylist' : 'Staff' ?><select class="select" name="staff_name"><option value="">Any</option><?php foreach ($staff as $st): ?><option><?= esc($st) ?></option><?php endforeach ?></select></label>
      <?php endif ?>
      <?php if (in_array($kind, ['room', 'table'], true)): ?>
        <label class="field">People<input class="input" type="number" min="1" max="500" name="party_size" value="2"></label>
      <?php endif ?>
      <?php if ($kind === 'pickup'): ?><label class="field span2">Pickup address<input class="input" name="address" maxlength="255"></label><?php endif ?>
      <?php if (in_array($kind, ['quote', 'repair'], true)): ?><label class="field span2">Details<textarea class="input" rows="2" name="details" maxlength="1000"></textarea></label><?php endif ?>
      <label class="field span2">Note<input class="input" name="note" maxlength="500"></label>
    </div><small class="muted">Counter bookings are confirmed straight away. Staff double-bookings are refused.</small></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save booking</button></div>
  </form>
</dialog>
<?php endif ?>
