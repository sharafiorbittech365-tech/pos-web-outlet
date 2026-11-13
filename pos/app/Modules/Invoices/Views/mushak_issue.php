<?php
/**
 * Issue the Mushak-6.3 of a sale (from the Orders page).
 *
 * @var array<string, mixed>      $o   OrderService::find()
 * @var ?array<string, mixed>     $inv invoice of the sale, if any
 * @var bool                      $on
 * @var string                    $fy
 */
helper(['ui', 'pos']);
$cur = service('posSession')->currency();
?>
<div class="page">
  <div class="page-h">
    <div><h2>Issue Mushak-6.3 · <?= esc($o['doc_no']) ?></h2><p class="page-sub"><?= esc($o['customer_name']) ?> · <?= esc(money($o['total'], $cur)) ?> · <?= esc(datetime_str($o['created_at'])) ?></p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('orders/' . $o['id']), 'attr') ?>"><?= icon('back', 'sm') ?>Order</a>
  </div>
  <?php if (! $on): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>Mushak-6.3 is not set up for this outlet (registered name, BIN and address).</span><?php if (service('posSession')->can('mushak.settings')): ?><a class="btn sm" href="<?= esc(site_url('mushak/settings'), 'attr') ?>">Settings</a><?php endif ?></div>
  <?php elseif (! in_array($o['status'], ['paid', 'partial', 'due'], true)): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>A Mushak-6.3 is issued for a completed sale only.</span></div>
  <?php else: ?>
    <form class="card bo-form" method="post" action="<?= esc(site_url('mushak/order/' . $o['id']), 'attr') ?>"><?= csrf_field() ?>
      <input type="hidden" name="back" value="orders/<?= (int) $o['id'] ?>">
      <div class="card-h"><h3>Buyer &amp; transport</h3><span class="muted">Serial: next of FY <?= esc($fy) ?></span></div>
      <div class="card-b form-grid">
        <label class="field">ক্রেতার নাম — Buyer name<input class="input" name="buyer_name" value="<?= esc((string) ($inv['bill_name'] ?? $o['customer_name']), 'attr') ?>" maxlength="160"></label>
        <label class="field">ক্রেতার বিআইএন — Buyer BIN<input class="input" name="buyer_bin" value="<?= esc((string) ($inv['bill_bin'] ?? ''), 'attr') ?>" maxlength="20" inputmode="numeric"><?= field_error('buyer_bin') ?></label>
        <label class="field span2">Buyer address<input class="input" name="buyer_address" value="<?= esc((string) ($inv['bill_address'] ?? ''), 'attr') ?>" maxlength="255"></label>
        <label class="field span2">সরবরাহের গন্তব্যস্থল — Destination of supply<input class="input" name="destination" value="<?= esc((string) ($inv['ship_to'] ?? $o['ship_address'] ?? ''), 'attr') ?>" maxlength="255"></label>
        <label class="field">Vehicle type (optional)<input class="input" name="vehicle_type" maxlength="60"></label>
        <label class="field">Vehicle no. (optional)<input class="input" name="vehicle_no" maxlength="40"></label>
      </div>
      <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Issue Mushak-6.3</button></div>
    </form>
  <?php endif ?>
</div>
