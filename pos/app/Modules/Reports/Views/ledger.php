<?php
/**
 * Credit ledger / Exchange ledger report.
 *
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var string                                    $kind  credit|exchange
 * @var array                                     $cur
 * @var float                                     $in    given in the period (credit sales / exchange credit issued)
 * @var float                                     $out   settled in the period (payments + returns / credit used)
 * @var array{n: int, v: float}                   $hold  customers still holding a balance and the sum
 * @var list<array>                               $top   biggest balances
 * @var string                                    $table
 */
helper('ui');
$isEx = $kind === 'exchange';
$m = static fn (float|int $v): string => esc(money($v, $cur));
$base = site_url('reports/' . $kind . '-ledger');
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/reports.css') . '?v=' . (is_file(FCPATH . 'assets/pos/reports.css') ? filemtime(FCPATH . 'assets/pos/reports.css') : 0), 'attr') ?>">
<div class="page">
  <div class="page-h">
    <h2><?= $isEx ? 'Exchange ledger' : 'Credit ledger' ?></h2>
    <?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => $base]) ?>
    <div class="spacer"></div>
    <a class="btn <?= $isEx ? '' : 'primary' ?>" href="<?= esc(site_url('reports/credit-ledger') . '?' . http_build_query($range->params()), 'attr') ?>">Credit ledger</a>
    <a class="btn <?= $isEx ? 'primary' : '' ?>" href="<?= esc(site_url('reports/exchange-ledger') . '?' . http_build_query($range->params()), 'attr') ?>">Exchange ledger</a>
    <a class="btn" href="<?= esc(site_url('reports') . '?' . http_build_query($range->params()), 'attr') ?>"><?= icon('back', 'sm') ?>Sales report</a>
  </div>
  <div class="report">
    <p class="muted" style="margin:0 0 10px"><?= $isEx
        ? 'Exchange credit is the value of items a customer returned and kept for their next invoice. It is separate from the credit (due) book.'
        : 'The credit ledger is what customers owe you: credit sales and previous dues, payments received and returns that reduced the due.' ?></p>
    <div class="stats">
      <?php $stat = static fn (string $ic, string $l, string $v, ?string $color = null): string => view('OmniPOS\Ui\Views\components\stat', ['icon' => $ic, 'label' => $l, 'value' => $v, 'color' => $color]); ?>
      <?= $stat($isEx ? 'undo' : 'receipt', $isEx ? 'Credit given (returns kept)' : 'Credit given (new due)', money($in, $cur), 'var(--warn, #d97706)') ?>
      <?= $stat($isEx ? 'cart' : 'cash', $isEx ? 'Credit used on invoices' : 'Paid back / returned', money($out, $cur), 'var(--ok)') ?>
      <?= $stat('wallet', $isEx ? 'Credit held by customers' : 'Total due now', money((float) $hold['v'], $cur), $isEx ? null : 'var(--danger)') ?>
      <?= $stat('users', 'Customers with a balance', (string) (int) $hold['n']) ?>
    </div>
    <div class="rp-grid">
      <section class="card"><div class="card-h"><h3><?= $isEx ? 'Biggest exchange balances' : 'Biggest dues' ?></h3></div><div class="card-b">
        <?= \App\Modules\Reports\Support\Charts::hbars(array_map(static fn (array $x): array => ['label' => $x['name'], 'value' => (float) $x['bal'], 'note' => (string) ($x['phone'] ?? '')], $top), static fn (float $v): string => $m($v), $isEx ? 'rp-c2' : 'rp-c3') ?>
      </div></section>
      <section class="card"><div class="card-h"><h3>In this period</h3></div><div class="card-b">
        <?= \App\Modules\Reports\Support\Charts::stack([
            ['label' => $isEx ? 'Credit given' : 'New due', 'value' => $in, 'cls' => 'rp-c1'],
            ['label' => $isEx ? 'Credit used' : 'Paid back', 'value' => $out, 'cls' => 'rp-c0'],
        ], static fn (float $v): string => $m($v)) ?>
      </div></section>
    </div>
    <section class="card"><div class="card-h"><h3>Entries</h3><span class="muted"><?= esc($range->label()) ?></span></div><?= $table ?></section>
  </div>
</div>
