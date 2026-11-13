<?php
/**
 * @var string                                    $table
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var array<string, mixed>                      $sum
 * @var list<string>                              $cats
 * @var string                                    $cat
 * @var string                                    $method
 * @var array<string, string>                     $methods
 */
helper('ui');
$cur = service('posSession')->currency();
$keep = array_filter(['cat' => $cat, 'method' => $method]);
?>
<div class="page">
  <div class="page-h">
    <h2>Expenses</h2>
    <?= view('App\Modules\BackOffice\Views\range_chips', ['range' => $range, 'base' => site_url('expenses'), 'keep' => $keep]) ?>
    <div class="spacer"></div>
    <button class="btn primary" type="button" data-modal-open="dlg-expense"><?= icon('plus', 'sm') ?>Add expense</button>
  </div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'wallet', 'label' => 'Total expenses', 'value' => money($sum['total'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'cash', 'label' => 'Paid in cash', 'value' => money($sum['cash'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'bank', 'label' => 'Bank / mobile / cheque', 'value' => money($sum['other'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'receipt', 'label' => 'Entries', 'value' => (string) $sum['n']]) ?>
  </div>
  <div class="reg-grid">
    <div class="card" style="grid-column:1/-1">
      <form class="prod-bar inline-form" method="get" action="<?= esc(site_url('expenses'), 'attr') ?>">
        <?php foreach ($range->params() as $k => $v): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endforeach ?>
        <select class="select" name="cat" data-auto-submit aria-label="Category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= esc($c, 'attr') ?>" <?= selected($cat, $c) ?>><?= esc($c) ?></option><?php endforeach ?></select>
        <select class="select" name="method" data-auto-submit aria-label="Paid from"><option value="">Any method</option><?php foreach ($methods as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($method, $k) ?>><?= esc($l) ?></option><?php endforeach ?></select>
      </form>
      <?= $table ?>
    </div>
    <div class="card"><div class="card-h"><h3>By category</h3><span class="muted"><?= esc($range->label()) ?></span></div>
      <table class="tbl"><tbody>
        <?php foreach ($sum['byCat'] as $r): ?>
          <tr><td><a class="link" href="<?= esc(site_url('expenses') . '?' . http_build_query(array_merge($range->params(), ['cat' => $r['category']])), 'attr') ?>"><?= esc($r['category']) ?></a> <small class="muted">· <?= (int) $r['n'] ?></small></td><td class="num"><?= esc(money($r['amount'], $cur)) ?></td><td class="num muted"><?= $sum['total'] > 0 ? (int) round($r['amount'] / $sum['total'] * 100) : 0 ?>%</td></tr>
        <?php endforeach ?>
        <?php if (! $sum['byCat']): ?><tr><td class="muted">—</td></tr><?php endif ?>
      </tbody></table>
    </div>
  </div>
</div>
<dialog class="modal-dlg" id="dlg-expense">
  <form method="post" action="<?= esc(site_url('expenses'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3><?= icon('wallet', 'sm') ?> Add expense</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field">Category<select class="select" name="category" required><?php foreach ($cats as $c): ?><option value="<?= esc($c, 'attr') ?>"><?= esc($c) ?></option><?php endforeach ?></select></label>
      <label class="field">Amount (<?= esc($cur['symbol']) ?>) *<input class="input" type="number" min="0" step="any" name="amount" required autofocus></label>
      <label class="field">Paid from<select class="select" name="method"><?php foreach ($methods as $k => $l): ?><option value="<?= esc($k, 'attr') ?>"><?= esc($l) ?></option><?php endforeach ?></select></label>
      <label class="field">Date<input class="input" type="date" name="spent_at" value="<?= esc(date('Y-m-d'), 'attr') ?>" max="<?= esc(date('Y-m-d'), 'attr') ?>"></label>
      <label class="field span2">Note<input class="input" name="note" maxlength="255" placeholder="e.g. Electricity bill — October"></label>
    </div><small class="muted">Cash expenses today come out of the open register's expected cash. Categories are set in Setup › Features.</small></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save expense</button></div>
  </form>
</dialog>
