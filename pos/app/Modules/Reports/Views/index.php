<?php
/**
 * Sales report — the design build's reportsHTML (stats, VAT summary & register, day-wise, P&L,
 * products sold, by category / payment method / user / staff). Print-friendly.
 *
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var array<string, float|int>                  $sum
 * @var list<array>                               $vat
 * @var string                                    $vatReg
 * @var string                                    $days
 * @var string                                    $prods
 * @var list<array>                               $cats
 * @var list<array>                               $methods
 * @var list<array>                               $users
 * @var list<array>                               $staff
 * @var list<array>                               $channels
 * @var array                                     $cur
 * @var string                                    $label
 */
helper('ui');
use App\Modules\Reports\Support\Charts;
$b = service('posSession')->business();
$m = static fn (float|int $v): string => esc(money($v, $cur));
$payLabel = ['cash' => 'Cash', 'card' => 'Card', 'mobile' => 'bKash / Nagad / Rocket', 'bank' => 'Bank transfer', 'credit' => 'Credit', 'exchange' => 'Exchange credit (used)', 'cheque' => 'Cheque', 'points' => 'Loyalty points'];
$vatSum = static fn (callable $f): float => array_sum(array_map(static fn (array $x): float => $f($x) ? $x['tax'] : 0.0, $vat));
$exempt = array_sum(array_map(static fn (array $x): float => $x['mode'] === 'exempt' || $x['rate'] == 0 ? $x['net'] : 0.0, $vat));
$taxable = array_sum(array_map(static fn (array $x): float => $x['mode'] !== 'exempt' && $x['rate'] > 0 ? $x['net'] : 0.0, $vat));
$modeTxt = static fn (array $x): string => $x['mode'] === 'exempt' ? 'Exempt' : ($x['mode'] === 'inclusive' ? 'Included in price' : 'Added on top');
$small = static function (string $title, string $head, string $rows): string {
    return '<div class="card"><div class="card-h"><h3>' . esc($title) . '</h3></div><div class="table-wrap"><table class="tbl compact"><thead><tr>' . $head . '</tr></thead><tbody>'
        . ($rows !== '' ? $rows : '<tr><td colspan="4" class="muted">No data in this period</td></tr>') . '</tbody></table></div></div>';
};
$stat = static fn (string $ic, string $l, string $v, ?string $color = null): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'color' => $color]);
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/reports.css') . '?v=' . (is_file(FCPATH . 'assets/pos/reports.css') ? filemtime(FCPATH . 'assets/pos/reports.css') : 0), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <h2>Sales report</h2>
    <?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => site_url('reports')]) ?>
    <div class="spacer"></div>
    <?php if (count(service('posSession')->outlets()) > 1): ?><a class="btn" href="<?= esc(site_url('reports/outlets') . '?' . http_build_query($range->params()), 'attr') ?>"><?= icon('store', 'sm') ?>All outlets</a><?php endif ?>
    <a class="btn" href="<?= esc(site_url('reports/credit-ledger') . '?' . http_build_query($range->params()), 'attr') ?>"><?= icon('book', 'sm') ?>Credit ledger</a>
    <a class="btn" href="<?= esc(site_url('reports/exchange-ledger') . '?' . http_build_query($range->params()), 'attr') ?>"><?= icon('undo', 'sm') ?>Exchange ledger</a>
    <button class="btn" type="button" data-print><?= icon('print', 'sm') ?>Print report</button>
  </div>
  <div class="report">
    <div class="report-head"><b><?= esc($b['name'] ?? '') ?></b> · <?= esc($range->label()) ?> · printed <?= esc(datetime_str(date('Y-m-d H:i:s'))) ?></div>
    <div class="stats">
      <?= $stat('receipt', 'Sales (' . $sum['n'] . ' invoices)', money($sum['sales'], $cur)) ?>
      <?= $stat('cash', 'Collected', money($sum['collected'], $cur), 'var(--ok)') ?>
      <?= $stat('handcoin', 'New due (credit)', money($sum['due_new'], $cur), 'var(--danger)') ?>
      <?= $stat('undo', 'Returns', money($sum['returns'], $cur)) ?>
      <?= $stat('wallet', 'Expenses', money($sum['expenses'], $cur)) ?>
      <?= $stat('tag', $label . ' collected', money($sum['tax_kept'], $cur)) ?>
    </div>

    <?php
    $mf = static fn (float $v): string => $m($v);
    $margin = $sum['revenue'] > 0 ? round($sum['np'] / $sum['revenue'] * 100) : 0;
    $avg = $sum['n'] > 0 ? $sum['sales'] / $sum['n'] : 0.0;
    $profit = max(0.0, (float) $sum['np']);
    $loss = $sum['np'] < 0;
    $cats8 = array_slice($cats, 0, 8);
    $chName = ['pos' => 'Counter (POS)', 'web' => 'Website', 'marketplace' => 'Marketplace', 'api' => 'API'];
    ?>
    <section class="rp-hero <?= $loss ? 'is-loss' : '' ?>">
      <div class="rp-hero-main">
        <small><?= $loss ? 'Net loss' : 'Net profit' ?> · <?= esc($range->label()) ?></small>
        <b><?= $m($sum['np']) ?></b>
        <span class="rp-pill"><?= $margin ?>% of revenue</span>
      </div>
      <div class="rp-hero-facts">
        <div><small>Invoices</small><b><?= (int) $sum['n'] ?></b></div>
        <div><small>Average invoice</small><b><?= $m($avg) ?></b></div>
        <div><small>Still to collect</small><b><?= $m($sum['due_new']) ?></b></div>
        <div><small>Gross profit</small><b><?= $m($sum['gp']) ?></b></div>
      </div>
    </section>

    <div class="rp-grid">
      <section class="card rp-wide"><div class="card-h"><h3><?= icon('chart', 'sm') ?> Where the money went</h3><span class="muted">your sales split into cost, tax, expenses and what you kept</span></div><div class="card-b">
        <?= Charts::stack([
            ['label' => 'Cost of goods', 'value' => (float) $sum['cogs'], 'cls' => 'rp-c3'],
            ['label' => $label, 'value' => (float) $sum['tax_kept'], 'cls' => 'rp-c2'],
            ['label' => 'Shipping', 'value' => (float) $sum['shipping'], 'cls' => 'rp-c4'],
            ['label' => 'Returns', 'value' => (float) $sum['returns'], 'cls' => 'rp-c5'],
            ['label' => 'Expenses', 'value' => (float) $sum['expenses'], 'cls' => 'rp-c1'],
            ['label' => 'Profit', 'value' => $profit, 'cls' => 'rp-c0'],
        ], $mf) ?>
      </div></section>
      <section class="card rp-wide"><div class="card-h"><h3><?= icon('chart', 'sm') ?> <?= esc($trendUnit) ?></h3><span class="muted">hover a bar for the exact figures</span></div><div class="card-b"><?= Charts::trend($trend) ?></div></section>
      <section class="card"><div class="card-h"><h3>Payment methods</h3></div><div class="card-b"><?= Charts::donut(array_map(static fn (array $x): array => ['label' => $payLabel[$x['method']] ?? ucfirst($x['method']), 'value' => $x['amount']], $methods), $mf, 'Collected') ?></div></section>
      <section class="card"><div class="card-h"><h3>Sales channels</h3></div><div class="card-b"><?= Charts::donut(array_map(static fn (array $x): array => ['label' => $chName[$x['name']] ?? $x['name'], 'value' => $x['amount']], $channels), $mf, 'Sales') ?></div></section>
      <section class="card"><div class="card-h"><h3>Top products</h3><span class="muted">by sales</span></div><div class="card-b"><?= Charts::hbars(array_map(static fn (array $x): array => ['label' => $x['name'], 'value' => $x['rev'], 'note' => qty($x['qty']) . ' sold · profit ' . strip_tags(money($x['profit'], $cur))], $top), $mf) ?></div></section>
      <section class="card"><div class="card-h"><h3>Top categories</h3><span class="muted">by sales</span></div><div class="card-b"><?= Charts::hbars(array_map(static fn (array $x): array => ['label' => $x['name'], 'value' => $x['rev'], 'note' => 'profit ' . strip_tags(money($x['rev'] - $x['cost'], $cur))], $cats8), $mf, 'rp-c2') ?></div></section>
    </div>

    <h3 class="rp-sec">Details</h3>

    <div class="card vat-card">
      <div class="card-h"><h3><?= icon('receipt', 'sm') ?> <?= esc($label) ?> summary</h3><span class="muted">net of returns</span></div>
      <div class="vat-stats">
        <div><small><?= esc($label) ?> payable</small><b><?= $m($vatSum(static fn (): bool => true)) ?></b></div>
        <div><small>Included in prices</small><b><?= $m($vatSum(static fn (array $x): bool => $x['mode'] === 'inclusive')) ?></b></div>
        <div><small>Added on top</small><b><?= $m($vatSum(static fn (array $x): bool => $x['mode'] === 'exclusive')) ?></b></div>
        <div><small>Taxable sales (before <?= esc($label) ?>)</small><b><?= $m($taxable) ?></b></div>
        <div><small>Exempt / zero-rated sales</small><b><?= $m($exempt) ?></b></div>
      </div>
      <div class="vat-grid">
        <div><div class="table-wrap"><table class="tbl compact"><thead><tr><th>Rate</th><th>How charged</th><th class="num">Taxable value</th><th class="num"><?= esc($label) ?></th><th class="num">Gross</th></tr></thead><tbody>
          <?php foreach ($vat as $x): ?>
            <tr><td><b><?= $x['mode'] === 'exempt' ? 'Exempt' : esc(qty($x['rate'])) . '%' ?></b></td><td><?= tag_html($modeTxt($x)) ?></td><td class="num"><?= $m($x['net']) ?></td><td class="num"><?= $m($x['tax']) ?></td><td class="num"><?= $m($x['net'] + $x['tax']) ?></td></tr>
          <?php endforeach ?>
          <?php if (! $vat): ?><tr><td colspan="5" class="muted">No sales in this period</td></tr><?php endif ?>
        </tbody></table></div></div>
        <div><?= $vatReg ?></div>
      </div>
    </div>

    <div class="reg-grid">
      <div class="card"><div class="card-h"><h3>Day-wise summary</h3></div><?= $days ?></div>
      <div class="card"><div class="card-h"><h3>Profit &amp; loss</h3></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Item</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ([
            ['Sales (incl. ' . $label . ' & shipping)', $sum['sales'], false], ['(−) Returns', $sum['returns'], false], ['(−) ' . $label . ' (net of returns)', $sum['tax_kept'], false],
            ['(−) Shipping', $sum['shipping'], false], ['Net revenue', $sum['revenue'], true], ['(−) Cost of goods sold', $sum['cogs'], false], ['Gross profit', $sum['gp'], true],
            ['(−) Expenses', $sum['expenses'], false], ['Net profit', $sum['np'], true],
        ] as [$l, $v, $bold]): ?>
          <tr class="<?= $l === 'Net profit' ? 'hl' : '' ?>"><td><?= $bold ? '<b>' . esc($l) . '</b>' : esc($l) ?></td><td class="num"><?= $bold ? '<b>' . $m($v) . '</b>' : $m($v) ?></td></tr>
        <?php endforeach ?>
        <?php if ($sum['discount'] > 0): ?><tr><td class="muted">Discounts given (already in sales)</td><td class="num muted"><?= $m($sum['discount']) ?></td></tr><?php endif ?>
      </tbody></table></div></div>
      <div class="card"><div class="card-h"><h3>Products sold</h3></div><?= $prods ?></div>
      <?= $small('By category', '<th>Category</th><th class="num">Sales</th><th class="num">Profit</th>', implode('', array_map(static fn (array $x): string => '<tr><td>' . esc($x['name']) . '</td><td class="num">' . $m($x['rev']) . '</td><td class="num">' . $m($x['rev'] - $x['cost']) . '</td></tr>', $cats))) ?>
      <?= $small('By payment method', '<th>Method</th><th class="num">Payments</th><th class="num">Collected</th>', implode('', array_map(static fn (array $x): string => '<tr><td>' . esc($payLabel[$x['method']] ?? ucfirst($x['method'])) . '</td><td class="num">' . $x['n'] . '</td><td class="num">' . $m($x['amount']) . '</td></tr>', $methods))) ?>
      <?= $small('By user (who billed)', '<th>User</th><th class="num">Invoices</th><th class="num">Sales</th>', implode('', array_map(static fn (array $x): string => '<tr><td>' . esc($x['name']) . '</td><td class="num">' . $x['n'] . '</td><td class="num">' . $m($x['amount']) . '</td></tr>', $users))) ?>
      <?= $small('By staff / salesperson', '<th>Staff</th><th class="num">Invoices</th><th class="num">Sales</th>', implode('', array_map(static fn (array $x): string => '<tr><td>' . esc($x['name']) . '</td><td class="num">' . $x['n'] . '</td><td class="num">' . $m($x['amount']) . '</td></tr>', $staff))) ?>
      <?= $small('By channel', '<th>Channel</th><th class="num">Invoices</th><th class="num">Sales</th>', implode('', array_map(static fn (array $x): string => '<tr><td>' . esc(['pos' => 'Counter (POS)', 'web' => 'Website', 'marketplace' => 'Marketplace', 'api' => 'API'][$x['name']] ?? $x['name']) . '</td><td class="num">' . $x['n'] . '</td><td class="num">' . $m($x['amount']) . '</td></tr>', $channels))) ?>
    </div>
  </div>
</div>
