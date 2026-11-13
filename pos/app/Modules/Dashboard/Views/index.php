<?php
/**
 * @var bool                       $scopeAll
 * @var array<string, mixed>       $sum
 * @var list<array>                $days
 * @var list<array>                $top
 * @var list<array>                $recent
 * @var array<string, mixed>       $user
 * @var array<string, mixed>       $business
 * @var array<string, mixed>       $cur
 */
helper(['ui', 'pos']);
$s = service('posSession');
$fmt = static fn ($n): string => money($n, $cur);
$h = (int) date('G');
$greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
$delta = $sum['yesterday'] > 0 ? (int) round(($sum['sales'] - $sum['yesterday']) / $sum['yesterday'] * 100) : null;
$max = max(1.0, ...array_map(static fn ($d) => $d['v'], $days));
// business words of this outlet (Patients, Bill, Appointment …) and its switches (gated by type & modules)
$t = static fn (string $k): string => term($k);
$lc = static fn (string $k): string => mb_strtolower(term($k));
$feat = (array) ($business['settings']['features'] ?? []);
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
// quick actions: the selling basics first, then what this kind of business works with every day
$quick = array_values(array_filter([
    $s->can('pos.sell') && $s->moduleOn('pos') ? ['sell', 'pos', 'New sale'] : null,
    $s->can('pos.sell') && $s->moduleOn('floor') && ! empty($feat['seats']) && ! empty($business['settings']['seats']) ? ['floor', 'floor', $t('floor')] : null,
    $s->can('clinic.appointments') && $s->moduleOn('clinic') && (($business['business_type'] ?? '') === 'clinic' || ! empty($feat['clinic'])) ? ['clinic/appointments', 'stethoscope', 'Appointments'] : null,
    $s->can('orders.view') && $s->moduleOn('bookings') && ! empty($business['booking_kind']) ? ['bookings', 'calendar', 'Bookings'] : null,
    $s->can('kitchen.view') && $s->moduleOn('kitchen') && ! empty($feat['kitchen']) ? ['kitchen', 'kitchen', $t('kitchen')] : null,
    $s->can('invoices.view') && $s->moduleOn('invoicing') ? ['invoices', 'file-text', $t('invoice') . 's'] : null,
    $s->can('customers.receive') && $s->moduleOn('customers') ? ['customers?cust_f=due', 'handcoin', 'Collect due'] : null,
    $s->can('orders.view') ? ['orders', 'orders', $t('orders')] : null,
    $s->can('register.view') && $s->moduleOn('register') ? ['register', 'register', 'Register'] : null,
    $s->can('inventory.view') && $s->moduleOn('pharmacy') ? ['pharmacy', 'pill', 'Expiry & stock'] : null,
    $s->can('reports.view') && $s->moduleOn('reports') ? ['reports', 'chart', 'Reports'] : null,
]));
$alerts = [];
if ($s->can('inventory.view') && $s->moduleOn('inventory') && ! empty($business['stock_enabled'])) {
    if ($sum['out_of_stock']) {
        $alerts[] = ['danger', 'box', $plural((int) $sum['out_of_stock'], $lc('item'), $lc('catalog')) . ' out of stock', 'inventory'];
    }
    if ($sum['low_stock']) {
        $alerts[] = ['warn', 'box', $plural((int) $sum['low_stock'], $lc('item'), $lc('catalog')) . ' running low', 'inventory'];
    }
}
if ($s->can('customers.view') && $s->moduleOn('customers') && $sum['due_customers']) {
    $alerts[] = ['danger', 'handcoin', $plural((int) $sum['due_customers'], $lc('customer'), $lc('customers')) . ' with due · ' . $fmt($sum['due']), 'customers?cust_f=due'];
}
if ($sum['open']) {
    $alerts[] = ['warn', 'receipt', $plural((int) $sum['open'], 'open ' . $lc('order'), 'open ' . $lc('orders')) . ' waiting for payment', 'orders?status=open'];
}
if ($sum['kitchen'] && $s->can('kitchen.view') && $s->moduleOn('kitchen') && ! empty($feat['kitchen'])) {
    $alerts[] = ['primary', 'kitchen', $plural((int) $sum['kitchen'], 'ticket', 'tickets') . ' in the ' . $lc('kitchen'), 'kitchen'];
}
$kpi = static fn (string $ic, string $label, string $value, string $sub = '', string $tone = ''): string => '<div class="kpi ' . esc($tone, 'attr') . '"><span class="kpi-ic">' . icon($ic) . '</span><small>' . esc($label) . '</small><b>' . esc($value) . '</b>' . ($sub !== '' ? '<span class="kpi-sub">' . $sub . '</span>' : '') . '</div>';
?>
<div class="page dash">
  <section class="hero">
    <div class="hero-t">
      <span class="hero-date"><?= esc(date('l, j F Y')) ?></span>
      <h1><?= esc($greet) ?>, <?= esc(strtok((string) ($user['name'] ?? ''), ' ')) ?></h1>
      <p><span class="role-pill" style="--ac:<?= css_color($user['color'] ?? '#fff') ?>"><?= sym_icon($user['icon'] ?? null) ?> <?= esc($user['role'] ?? '') ?></span> at <?= esc($business['name'] ?? '') ?><?= $scopeAll ? '' : ' · showing your own sales' ?></p>
    </div>
    <div class="hero-q">
      <?php foreach ($quick as [$path, $ic, $label]): ?>
        <a class="qa" href="<?= esc(site_url($path), 'attr') ?>"><?= icon($ic) ?><span><?= esc($label) ?></span></a>
      <?php endforeach ?>
    </div>
  </section>
  <div class="kpis">
    <?php $docs = $lc('invoice') . 's' ?>
    <?= $kpi('receipt', $scopeAll ? 'Sales today' : 'My sales today', $fmt($sum['sales']), $delta === null ? esc($sum['orders'] . ' ' . $docs) : '<span class="' . ($delta >= 0 ? 'pos' : 'neg') . '">' . ($delta >= 0 ? '+' : '-') . abs($delta) . '%</span> vs yesterday · ' . esc($sum['orders'] . ' ' . $docs)) ?>
    <?= $kpi('cash', 'Collected today', $fmt($sum['collected']), 'Cash, card &amp; mobile') ?>
    <?= $s->can('customers.view') && $s->moduleOn('customers') ? $kpi('handcoin', 'Receivable (due)', $fmt($sum['due']), esc($sum['due_customers'] . ' ' . $lc('customers')), $sum['due'] > 0 ? 'warnk' : '') : $kpi('orders', $t('orders') . ' today', (string) $sum['orders'], esc($t('invoice') . 's')) ?>
    <?= $s->can('reports.view') && $s->moduleOn('reports') ? $kpi('chart', 'Gross profit today', $fmt($sum['profit']), 'After cost &amp; VAT', $sum['profit'] >= 0 ? 'okk' : 'warnk') : $kpi('orders', 'Avg. ticket', $fmt($sum['avg']), 'Today') ?>
  </div>
  <div class="dash-grid">
    <section class="card dcard">
      <div class="card-h"><h3><?= $scopeAll ? 'Sales' : 'My sales' ?> · last 7 days</h3><span class="muted"><?= esc($fmt(array_sum(array_column($days, 'v')))) ?></span></div>
      <div class="bars" role="img" aria-label="Sales for the last 7 days">
        <?php foreach ($days as $i => $d): ?>
          <div class="bar-c <?= $i === 6 ? 'today' : '' ?>" tabindex="0" data-tip="<?= esc(date_str($d['at']) . ' · ' . $fmt($d['v']) . ' · ' . $d['n'] . ' ' . $docs, 'attr') ?>">
            <?php if ($i === 6 && $d['v'] > 0): ?><em><?= esc($fmt($d['v'])) ?></em><?php endif ?>
            <i style="height:<?= (string) max(2, round($d['v'] / $max * 100, 1)) ?>%"></i><span><?= esc(date('D', strtotime($d['at']))) ?></span>
          </div>
        <?php endforeach ?>
      </div>
    </section>
    <section class="card dcard">
      <div class="card-h"><h3>Needs attention</h3><span class="tag <?= $alerts ? 'danger' : 'ok' ?>"><?= $alerts ? count($alerts) : 'All good' ?></span></div>
      <div class="dlist">
        <?php foreach ($alerts as [$tone, $ic, $text, $path]): ?>
          <a class="alert-row <?= esc($tone, 'attr') ?>" href="<?= esc(site_url($path), 'attr') ?>"><span class="ar-ic"><?= icon($ic, 'sm') ?></span><span class="grow"><?= esc($text) ?></span><?= icon('chev', 'sm') ?></a>
        <?php endforeach ?>
        <?php if (! $alerts): ?><div class="kcol-empty"><?= icon('circle-check-big', 'sm') ?> Nothing needs attention</div><?php endif ?>
      </div>
    </section>
    <section class="card dcard">
      <div class="card-h"><h3>Top <?= esc($lc('catalog')) ?> · 7 days</h3><?php if ($s->can('reports.view') && $s->moduleOn('reports')): ?><a class="link" href="<?= esc(site_url('reports'), 'attr') ?>">Reports</a><?php endif ?></div>
      <div class="dlist">
        <?php foreach ($top as $i => $p): ?>
          <div class="ro-row"><span class="lthumb"><b><?= $i + 1 ?></b></span><span class="grow"><b><?= esc($p['name']) ?></b><small class="muted"><?= esc(qty($p['qty'], $p['unit'] ?: null)) ?> sold</small></span><span class="ro-r"><b><?= esc($fmt($p['amount'])) ?></b></span></div>
        <?php endforeach ?>
        <?php if (! $top): ?><div class="kcol-empty">No sales in the last 7 days</div><?php endif ?>
      </div>
    </section>
    <section class="card dcard">
      <div class="card-h"><h3>Recent <?= $scopeAll ? '' : 'of my ' ?><?= esc($lc('orders')) ?></h3><?php if ($s->can('orders.view')): ?><a class="link" href="<?= esc(site_url('orders'), 'attr') ?>">All <?= esc($lc('orders')) ?></a><?php endif ?></div>
      <div class="dlist">
        <?php foreach ($recent as $o): ?>
          <a class="ro-row" href="<?= esc(site_url('orders/' . $o['id']), 'attr') ?>"><span class="lthumb has-img"><img class="demo-img" src="<?= esc(asset_url('img/demo.svg'), 'attr') ?>" alt="" loading="lazy"></span>
            <span class="grow"><b><?= esc($o['doc_no']) ?> · <?= esc($o['customer_name']) ?></b><small class="muted"><?= esc(time_str($o['created_at'])) ?> · <?= esc($o['user_name'] ?: '—') ?><?= $o['channel'] !== 'pos' ? ' · ' . esc(ucfirst($o['channel'])) : '' ?></small></span>
            <span class="ro-r"><b><?= esc($fmt($o['total'])) ?></b><?= order_status_tag($o) ?></span></a>
        <?php endforeach ?>
        <?php if (! $recent): ?><div class="kcol-empty">No <?= esc($lc('orders')) ?> yet</div><?php endif ?>
      </div>
    </section>
  </div>
</div>
