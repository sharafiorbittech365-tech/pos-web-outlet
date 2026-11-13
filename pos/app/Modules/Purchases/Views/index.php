<?php
/**
 * @var string                                    $table
 * @var string                                    $tab
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var string                                    $status
 * @var array<string, mixed>                      $stats
 * @var list<array>                               $suppliers
 * @var array<string, string>                     $methods
 * @var array<string, array>                      $states
 * @var array<string, bool>                        $on
 * @var list<string>                               $tabs
 */
helper('ui');
$tabs_on = $tabs;
$cur = service('posSession')->currency();
$tabs = array_values(array_filter([['purchases', 'Purchases', 'receipt'], ['suppliers', 'Suppliers', 'users'], ['payments', 'Supplier payments', 'wallet']], static fn (array $t): bool => in_array($t[0], $tabs_on, true)));
?>
<div class="page pu-page">
  <div class="page-h">
    <div><h2>Purchases &amp; suppliers</h2><p class="page-sub">What you bought, from whom, what you paid and what you still owe.</p></div>
    <div class="spacer"></div>
    <?php if ($on['supplier']): ?><button class="btn" type="button" data-modal-open="dlg-supplier"><?= icon('userplus', 'sm') ?>Add supplier</button><?php endif ?>
    <?php if ($on['payment'] && $on['supplier']): ?><button class="btn" type="button" data-modal-open="dlg-pay" <?= $stats['payable'] > 0 ? '' : 'disabled' ?>><?= icon('wallet', 'sm') ?>Pay supplier</button><?php endif ?>
    <?php if ($on['purchase']): ?><a class="btn primary" href="<?= esc(site_url('purchases/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>New purchase</a><?php endif ?>
  </div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'receipt', 'label' => 'Purchases · ' . $range->label(), 'value' => money($stats['purchased'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Paid to suppliers', 'value' => money($stats['paid'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Outstanding payable', 'value' => money($stats['payable'], $cur), 'color' => $stats['payable'] > 0 ? 'var(--danger)' : null]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'users', 'label' => 'Suppliers', 'value' => (string) $stats['suppliers']]) ?>
  </div>
  <div class="pu-bar">
    <div class="seg"><?php foreach ($tabs as [$k, $l, $ic]): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= esc(site_url('purchases' . ($k === 'purchases' ? '' : '?tab=' . $k)), 'attr') ?>"><?= icon($ic, 'sm') ?><?= esc($l) ?></a><?php endforeach ?></div>
    <?php if ($tab !== 'suppliers'): ?>
      <div class="pu-per"><?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => site_url('purchases'), 'keep' => array_filter(['tab' => $tab === 'purchases' ? '' : $tab, 'status' => $status])]) ?></div>
    <?php endif ?>
  </div>
  <?php if ($tab === 'purchases'): ?>
    <div class="pu-filters">
      <?php $chips = ['' => ['All', $stats['counts']['all']]];
      foreach ($states as $sk => $st) {
          $chips[$sk] = [$st[0], $stats['counts'][$sk] ?? 0];
      } ?>
      <?php foreach ($chips as $k => [$l, $n]): ?>
        <a class="chip <?= $status === (string) $k ? 'on' : '' ?>" href="<?= esc(site_url('purchases') . '?' . http_build_query(array_filter(array_merge($range->params(), ['status' => $k]))), 'attr') ?>"><?= esc($l) ?> <span class="n"><?= (int) $n ?></span></a>
      <?php endforeach ?>
    </div>
  <?php endif ?>
  <div class="card"><?= $table ?></div>
</div>
<?= view('App\Modules\Purchases\Views\_dialogs', ['suppliers' => $suppliers, 'methods' => $methods]) ?>
