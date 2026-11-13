<?php
/**
 * Consolidated report across outlets (Reports › All outlets).
 *
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var array<int, array>                         $outlets outlets the user may report on
 * @var int                                       $pick    0 = all
 * @var list<array>                               $rows
 * @var array<string, float|int>|null             $total   null when outlets use different currencies
 */
helper('ui');
$keep = $range->params() + ($pick > 0 ? ['outlet' => (string) $pick] : []);
$cur = static fn (array $o): array => ['symbol' => $o['currency_symbol'] ?: '৳', 'decimals' => $o['currency_decimals'], 'position' => 'before'];
$first = $rows !== [] ? $outlets[$rows[0]['id']] : null;
$stat = static fn (string $ic, string $l, string $v, ?string $color = null): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'color' => $color]);
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/reports.css') . '?v=' . (is_file(FCPATH . 'assets/pos/reports.css') ? filemtime(FCPATH . 'assets/pos/reports.css') : 0), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <div><h2>All outlets</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('reports'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Sales report</a> · sales and stock of every outlet you can report on.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('reports/outlets') . '?' . http_build_query($keep + ['csv' => '1']), 'attr') ?>"><?= icon('download', 'sm') ?>CSV</a>
    <button class="btn" type="button" data-print><?= icon('print', 'sm') ?>Print</button>
  </div>
  <div class="cat-chips">
    <?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => site_url('reports/outlets'), 'keep' => $pick > 0 ? ['outlet' => (string) $pick] : []]) ?>
  </div>
  <form class="cat-chips" method="get" action="<?= esc(site_url('reports/outlets'), 'attr') ?>">
    <?php foreach ($range->params() as $k => $v): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endforeach ?>
    <label class="field inline">Outlet
      <select class="select" name="outlet" data-auto-submit aria-label="Outlet">
        <option value="0">All outlets (<?= count($outlets) ?>)</option>
        <?php foreach ($outlets as $o): ?><option value="<?= (int) $o['id'] ?>" <?= selected((string) $pick, (string) $o['id']) ?>><?= esc($o['name']) ?><?= $o['is_active'] ? '' : ' (closed)' ?></option><?php endforeach ?>
      </select></label>
    <noscript><button class="btn sm" type="submit">Show</button></noscript>
  </form>

  <div class="report">
    <div class="report-head"><b><?= $pick > 0 ? esc($outlets[$pick]['name']) : 'All outlets' ?></b> · <?= esc($range->label()) ?> · printed <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></div>
    <?php if ($total !== null && $first !== null): $c = $cur($first); ?>
      <div class="stats">
        <?= $stat('receipt', 'Sales (' . (int) $total['n'] . ' invoices)', money($total['sales'], $c)) ?>
        <?= $stat('cash', 'Collected', money($total['collected'], $c), 'var(--ok)') ?>
        <?= $stat('undo', 'Returns', money($total['returns'], $c)) ?>
        <?= $stat('wallet', 'Expenses', money($total['expenses'], $c)) ?>
        <?= $stat('box', 'Stock value (cost)', money($total['stock_value'], $c)) ?>
        <?= $stat('alert', 'Low-stock items', (string) (int) $total['low'], (int) $total['low'] > 0 ? 'var(--danger)' : null) ?>
      </div>
    <?php elseif ($rows !== []): ?>
      <div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Your outlets use different currencies, so there is no combined total.</span></div>
    <?php endif ?>

    <div class="card"><div class="card-h"><h3>By outlet</h3></div>
      <div class="table-wrap"><table class="tbl">
        <thead><tr><th>Outlet</th><th class="num">Invoices</th><th class="num">Sales</th><th class="num">Collected</th><th class="num">New due</th><th class="num">Returns</th><th class="num">Expenses</th><th class="num">Products</th><th class="num">Stock value</th><th class="num">Low stock</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $x): $o = $outlets[$x['id']]; $c = $cur($o); ?>
            <tr><td data-l="Outlet"><b><?= esc($o['name']) ?></b><?= $o['is_active'] ? '' : ' ' . tag_html('Closed') ?><br><small class="muted"><?= esc($o['type_name']) ?><?= $o['area'] ? ' · ' . esc($o['area']) : '' ?></small></td>
              <td class="num" data-l="Invoices"><?= (int) $x['n'] ?></td>
              <td class="num" data-l="Sales"><b><?= esc(money($x['sales'], $c)) ?></b></td>
              <td class="num" data-l="Collected"><?= esc(money($x['collected'], $c)) ?></td>
              <td class="num" data-l="New due"><?= esc(money($x['due_new'], $c)) ?></td>
              <td class="num" data-l="Returns"><?= esc(money($x['returns'], $c)) ?></td>
              <td class="num" data-l="Expenses"><?= esc(money($x['expenses'], $c)) ?></td>
              <td class="num" data-l="Products"><?= (int) $x['products'] ?></td>
              <td class="num" data-l="Stock value"><?= esc(money($x['stock_value'], $c)) ?></td>
              <td class="num" data-l="Low stock"><?= (int) $x['low'] > 0 ? '<span class="neg">' . (int) $x['low'] . '</span>' : '0' ?></td></tr>
          <?php endforeach ?>
          <?php if ($rows === []): ?><tr><td colspan="10" class="muted">No outlets to report on.</td></tr><?php endif ?>
        </tbody>
        <?php if ($total !== null && $first !== null && count($rows) > 1): $c = $cur($first); ?>
          <tfoot><tr><th>Total</th><th class="num"><?= (int) $total['n'] ?></th><th class="num"><?= esc(money($total['sales'], $c)) ?></th><th class="num"><?= esc(money($total['collected'], $c)) ?></th>
            <th class="num"><?= esc(money($total['due_new'], $c)) ?></th><th class="num"><?= esc(money($total['returns'], $c)) ?></th><th class="num"><?= esc(money($total['expenses'], $c)) ?></th>
            <th class="num"><?= (int) $total['products'] ?></th><th class="num"><?= esc(money($total['stock_value'], $c)) ?></th><th class="num"><?= (int) $total['low'] ?></th></tr></tfoot>
        <?php endif ?>
      </table></div>
      <div class="card-b"><small class="muted">Sales = paid, partial, due and returned invoices of the period. Stock value = current stock × cost (stock is per outlet). Only outlets where your role can view reports are listed.</small></div>
    </div>
  </div>
</div>
