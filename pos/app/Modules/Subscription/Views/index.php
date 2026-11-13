<?php
/**
 * Subscription — the design build's billingHTML: current plan & price, usage, plans, module shop,
 * invoices, payment method, cancel.
 *
 * @var array<string, mixed> $acc
 * @var array<string, mixed> $price
 * @var list<array>          $plans
 * @var string               $cycle
 * @var list<array>          $modules
 * @var list<array>          $open
 * @var string               $invoices
 * @var array<string, int>   $usage
 * @var array<string, int>   $limits
 * @var string               $typeName
 * @var list<string>         $methods
 * @var bool                 $canAddons
 * @var array<string, mixed> $sel       ModuleSelection::state() (modules, features, lines, monthly)
 */
helper('ui');
$tk = static fn (float|int|string|null $n): string => esc(money($n, ['symbol' => '৳', 'decimals' => 0]));
$p = $acc['plan'];
$fresh = in_array($acc['status'], ['trial', 'expired', 'cancelled'], true);
$ym = (float) $price['yearlyMonths'];
$openTotal = array_sum(array_map(static fn (array $i): float => (float) $i['total'], $open));
$meter = static function (string $label, string $ic, int $n, int $lim): string {
    $pct = $lim > 0 ? min(100, (int) round($n / $lim * 100)) : 0;

    return '<div class="meter"><div class="meter-h">' . icon($ic, 'sm') . '<span class="grow">' . esc($label) . '</span><b>' . number_format($n) . ($lim > 0 ? ' / ' . number_format($lim) : '') . '</b></div>'
        . ($lim > 0 ? '<div class="meter-bar"><i style="width:' . $pct . '%"></i></div>' : '<small class="muted">Unlimited</small>') . '</div>';
};
$engine = ! empty($price['rows']);
/** the price lines every customer sees (PricingEngine::summaryRows) */
$rowsHtml = static function (array $rows) use ($tk): string {
    $h = '';
    foreach ($rows as $r) {
        $amt = $r['type'] === 'minus' ? ($r['amount'] > 0 ? '−' . $tk($r['amount']) : '—') : ($r['amount'] > 0 || $r['type'] === 'total' ? $tk($r['amount']) : '—');
        $h .= '<div class="bs-r ' . ($r['type'] === 'total' ? 'tot' : (($r['type'] === 'separate' || $r['amount'] <= 0) && $r['type'] !== 'total' ? 'muted' : '')) . '"><span>' . esc($r['label']) . ($r['note'] !== '' ? ' <small class="muted">' . esc($r['note']) . '</small>' : '') . '</span><b>' . $amt . '</b></div>';
    }

    return $h;
};
$catMap = ['website' => 'Website & online store', 'multisite' => 'Website & online store', 'devapi' => 'Website & online store', 'marketplace' => 'Website & online store', 'bookings' => 'Website & online store', 'tableqr' => 'Website & online store',
    'kitchen' => 'Kitchen & production', 'floor' => 'Kitchen & production', 'purchases' => 'Purchases & stock', 'inventory' => 'Purchases & stock', 'pharmacy' => 'Purchases & stock',
    'pos' => 'Sales & billing', 'invoicing' => 'Sales & billing', 'register' => 'Sales & billing', 'customers' => 'Customers & loyalty', 'loyalty' => 'Customers & loyalty', 'expenses' => 'Reports & team',
    'reports' => 'Reports & team', 'tasks' => 'Reports & team', 'clinic' => 'Clinic'];
$featLabels = ['addons' => 'Add-on modules', 'invoiceDesigns' => 'Invoice designs', 'csv' => 'CSV import & export', 'multiOutlet' => 'Multi-outlet', 'prioritySupport' => 'Priority support'];
?>
<div class="page">
  <div class="page-h"><div><h2>Subscription</h2><p class="page-sub"><?= esc($acc['name']) ?> · account owner <?= esc($acc['owner_name']) ?></p></div></div>

  <div class="sub-top">
    <section class="card sub-cur" style="--pc:<?= css_color($p['color']) ?>"><div class="card-b">
      <div class="sub-plan"><span class="sub-badge"><?= icon('star') ?></span><div class="grow"><small class="muted">Current plan</small><h3><?= esc($p['name']) ?> <?= status_tag($acc['status']) ?></h3></div>
        <div class="sub-price"><b><?= $tk($price['cycle']) ?></b><small>/<?= $acc['billing_cycle'] === 'yearly' ? 'year' : 'month' ?></small></div></div>
      <div class="sub-facts">
        <?php if ($acc['status'] === 'trial'): ?><div><small>Trial ends</small><b><?= esc(date_str($acc['trial_ends_at'])) ?></b></div>
        <?php else: ?><div><small><?= $acc['cancel_at'] ? 'Ends on' : 'Renews on' ?></small><b><?= esc(date_str($acc['cancel_at'] ?: $acc['period_end'])) ?></b></div><?php endif ?>
        <div><small>Billing</small><b><?= $acc['billing_cycle'] === 'yearly' ? 'Yearly' : 'Monthly' ?></b></div>
        <div><small>Payment</small><b><?= $acc['pay_method'] ? esc($acc['pay_method']) . ($acc['auto_pay'] ? ' · auto' : '') : 'Not set' ?></b></div>
        <div><small>Customer since</small><b><?= esc(date_str($acc['since'])) ?></b></div>
      </div>
      <?php if ($acc['next_plan']): ?>
        <form class="sbanner info" method="post" action="<?= esc(site_url('subscription/plan'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $acc['plan_id'] ?>"><input type="hidden" name="cycle" value="<?= esc($acc['billing_cycle'], 'attr') ?>">
          <?= icon('info', 'sm') ?><span class="grow">Changes to <b><?= esc($acc['next_plan']['name']) ?></b> on <?= esc(date_str($acc['period_end'])) ?>.</span><button class="btn sm" type="submit">Keep <?= esc($p['name']) ?></button></form>
      <?php endif ?>
      <?php if ($acc['cancel_at']): ?>
        <form class="sbanner warn" method="post" action="<?= esc(site_url('subscription/resume'), 'attr') ?>"><?= csrf_field() ?><?= icon('alert', 'sm') ?><span class="grow">Cancelled — the workspace locks on <?= esc(date_str($acc['cancel_at'])) ?>.</span><button class="btn sm primary" type="submit">Keep my subscription</button></form>
      <?php endif ?>
      <?php if ($open): ?>
        <div class="sbanner warn"><?= icon('alert', 'sm') ?><span class="grow"><b><?= $tk($openTotal) ?></b> unpaid (<?= count($open) ?> invoice<?= count($open) > 1 ? 's' : '' ?>)</span><a class="btn sm primary" href="<?= esc(site_url('subscription/pay?ids=' . implode(',', array_column($open, 'id'))), 'attr') ?>">Pay now</a></div>
      <?php endif ?>
      <div class="bill-sum">
        <?php if ($engine): echo $rowsHtml($price['rows']); ?>
          <div class="bs-r muted"><span>Yearly (<?= esc(qty($ym)) ?> months)</span><b><?= $tk($price['monthly'] * $ym) ?></b></div>
        <?php else: ?>
        <div class="bs-r"><span><?= esc($p['name']) ?> plan <small class="muted">(<?= esc($typeName) ?>)</small></span><b><?= $tk($price['base']) ?></b></div>
        <?php $extra = array_values(array_filter($sel['lines'], static fn (array $l): bool => $l['kind'] !== 'plan')); ?>
        <?php foreach ($extra as $a): ?><div class="bs-r"><span>+ <?= esc($a['label']) ?></span><b><?= $tk($a['monthly']) ?></b></div><?php endforeach ?>
        <?php if (! $extra): ?><div class="bs-r muted"><span>No add-on modules or features</span><b>—</b></div><?php endif ?>
        <div class="bs-r tot"><span>Total per month</span><b><?= $tk($price['monthly']) ?></b></div>
        <div class="bs-r muted"><span>Yearly (<?= esc(qty($ym)) ?> months)</span><b><?= $tk($price['monthly'] * $ym) ?></b></div>
        <?php endif ?>
      </div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Usage</h3></div><div class="card-b meters">
      <?= $meter('Outlets', 'store', (int) $usage['outlets'], (int) $limits['outlets']) ?>
      <?= $meter('Active users', 'users', (int) $usage['users'], (int) $limits['users']) ?>
      <?= $meter('Products', 'tag', (int) $usage['products'], 0) ?>
      <?= $meter('Orders this month', 'orders', (int) $usage['orders_month'], 0) ?>
    </div></section>
  </div>

  <?php if ($engine): ?>
    <section class="card"><div class="card-h"><h3><?= $fresh ? 'Start your subscription' : 'Billing cycle' ?></h3><small class="muted">Your price is built from the base package, your outlets and the modules below.</small></div><div class="card-b">
      <form class="row-add" method="post" action="<?= esc(site_url('subscription/plan'), 'attr') ?>" data-confirm="<?= esc(($fresh ? 'Start the subscription' : 'Change the billing cycle') . '?', 'attr') ?>"><?= csrf_field() ?>
        <input type="hidden" name="plan_id" value="<?= (int) $acc['plan_id'] ?>">
        <div class="seg"><?php foreach (['monthly' => 'Monthly · ' . $tk($price['monthly']), 'yearly' => 'Yearly · ' . $tk($price['monthly'] * $ym) . ' (' . qty(12 - $ym) . ' months free)'] as $k => $l): ?>
          <label class="<?= $acc['billing_cycle'] === $k ? 'on' : '' ?>"><input type="radio" name="cycle" value="<?= $k ?>" <?= checked($acc['billing_cycle'] === $k) ?> hidden><?= esc($l) ?></label><?php endforeach ?></div>
        <button class="btn primary" type="submit"><?= $fresh ? 'Start subscription' : 'Change cycle' ?></button>
      </form>
    </div></section>
  <?php else: ?>
  <div class="page-h"><h2>Plans</h2><span class="tag primary">Plans for <?= esc($typeName) ?></span><div class="spacer"></div>
    <div class="seg"><?php foreach (['monthly' => 'Monthly', 'yearly' => 'Yearly · ' . qty(12 - $ym) . ' months free'] as $k => $l): ?><a class="<?= $cycle === $k ? 'on' : '' ?>" href="<?= esc(site_url('subscription?cycle=' . $k), 'attr') ?>"><?= esc($l) ?></a><?php endforeach ?></div></div>
  <div class="plans">
    <?php foreach ($plans as $x): $cur = (int) $x['id'] === (int) $acc['plan_id'] && ! $fresh; ?>
      <article class="plan <?= $x['is_popular'] ? 'pop' : '' ?> <?= (int) $x['id'] === (int) $acc['plan_id'] ? 'cur' : '' ?>" style="--pc:<?= css_color($x['color']) ?>">
        <?php if ($x['is_popular']): ?><span class="plan-badge">Most popular</span><?php endif ?>
        <h3><?= esc($x['name']) ?></h3><p class="muted"><?= esc((string) $x['tagline']) ?></p>
        <div class="plan-price"><?= $tk($x['price']) ?><small>/<?= $cycle === 'yearly' ? 'year' : 'month' ?></small></div>
        <?= $cycle === 'yearly' && $ym < 12 ? '<small class="pos">Save ' . $tk($x['monthly'] * (12 - $ym)) . ' a year</small>' : '<small>&nbsp;</small>' ?>
        <ul>
          <li><?= icon('check', 'sm') ?><?= (int) $x['limit_outlets'] ? (int) $x['limit_outlets'] : 'Unlimited' ?> outlets</li>
          <li><?= icon('check', 'sm') ?><?= (int) $x['limit_users'] ? (int) $x['limit_users'] : 'Unlimited' ?> users</li>
          <li><?= icon('check', 'sm') ?>Unlimited products &amp; orders</li>
          <li><?= icon($x['all_modules'] ? 'check' : 'info', 'sm') ?><?= $x['all_modules'] ? 'All modules' : count((array) $x['modules']) . ' core modules' ?></li>
          <?php foreach ($featLabels as $k => $l): ?><li class="<?= ! empty($x['features'][$k]) ? '' : 'off' ?>"><?= icon(! empty($x['features'][$k]) ? 'check' : 'x', 'sm') ?><?= esc($l) ?></li><?php endforeach ?>
        </ul>
        <?php if ($cur && $cycle === $acc['billing_cycle']): ?><span class="tag ok">Current plan</span>
        <?php elseif ((int) ($acc['next_plan_id'] ?? 0) === (int) $x['id']): ?><span class="tag warn">Starts next renewal</span>
        <?php else: ?>
          <form method="post" action="<?= esc(site_url('subscription/plan'), 'attr') ?>" data-confirm="<?= esc(($fresh ? 'Start ' : 'Switch to ') . $x['name'] . ' (' . $cycle . ')?', 'attr') ?>">
            <?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $x['id'] ?>"><input type="hidden" name="cycle" value="<?= esc($cycle, 'attr') ?>">
            <button class="btn full <?= $x['is_popular'] ? 'primary' : '' ?>" type="submit"><?= $fresh ? 'Choose ' . esc($x['name']) : ($x['monthly'] >= (float) $price['base'] || (int) $x['id'] === (int) $acc['plan_id'] ? 'Switch to ' . esc($x['name']) : 'Downgrade') ?></button>
          </form>
        <?php endif ?>
      </article>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <div class="page-h" id="modules"><div><h2>Modules &amp; features</h2><p class="page-sub">Choose what your business uses — POS, Invoicing, Website or any combination. Modules in your plan are included; anything else is priced per month for <?= esc($typeName) ?>. The price updates as you choose; nothing changes until you confirm.</p></div></div>
  <form class="msel" method="post" action="<?= esc(site_url('subscription/selection'), 'attr') ?>" data-msel data-quote="<?= esc(site_url('subscription/quote'), 'attr') ?>"><?= csrf_field() ?>
    <div class="msel-grid">
      <div class="mod-shop">
        <?php
        $groups = [];
        foreach ($sel['modules'] as $m) { if ($m['state'] !== 'core') { $groups[$catMap[$m['code']] ?? 'Other modules'][] = $m; } }
        foreach ($groups as $gname => $gmods): ?>
        <section class="mgroup"><h4 class="mod-cat"><?= esc($gname) ?> <small class="muted"><?= count($gmods) ?></small></h4><div class="mlist">
        <?php foreach ($gmods as $m):
            $checked = in_array($m['state'], ['included', 'added', 'admin_on'], true);
            $label = match ($m['state']) {
                'included' => 'In your plan', 'owner_off' => 'In your plan · switched off', 'added' => 'Added', 'admin_on' => 'On · set by support', 'admin_off' => 'Off · set by support', default => '',
            }; ?>
          <article class="mshop <?= $m['state'] === 'included' ? 'inc' : ($m['state'] === 'added' ? 'on' : '') ?>">
            <span class="ms-ic"><?= icon($m['icon'] ?: 'grid') ?></span>
            <div class="grow"><b><?= esc($m['name']) ?></b><?= $label !== '' ? ' <span class="tag ' . ($checked ? 'ok' : '') . '">' . esc($label) . '</span>' : '' ?><small><?= esc($m['description']) ?></small>
              <?php if ($m['needs']): ?><small class="muted">Needs <?= esc(implode(', ', array_map(static fn (string $n): string => (string) (service('catalog')->modules()[$n]['name'] ?? $n), $m['needs']))) ?></small><?php endif ?>
              <?php if (! $m['on'] && $checked && $m['reason']): ?><small class="neg"><?= icon('alert', 'sm') ?> Off: <?= esc($m['reason']) ?></small><?php endif ?>
              <?php if (! $m['selectable'] && $m['lock']): ?><small class="muted"><?= icon('lock', 'sm') ?> <?= esc($m['lock']) ?></small><?php endif ?>
              <?php if ($m['features']): ?><div class="msel-feats">
                <?php foreach ($m['features'] as $f): ?>
                  <label class="check"><?php if ($f['selectable']): ?><input type="hidden" name="features[<?= esc($f['code'], 'attr') ?>]" value="0"><?php endif ?>
                    <input type="checkbox" name="features[<?= esc($f['code'], 'attr') ?>]" value="1" <?= checked($f['selected']) ?> <?= $f['selectable'] ? '' : 'disabled' ?>>
                    <?= esc($f['name']) ?> <small class="muted"><?= $f['default'] ? 'included' : ($f['price'] > 0 ? '+' . $tk($f['price']) . '/mo' : 'free') ?></small></label>
                <?php endforeach ?></div><?php endif ?>
            </div>
            <div class="ms-r">
              <?php if (in_array($m['state'], ['included', 'owner_off'], true)): ?><small class="muted"><?= $m['price'] !== null ? 'worth ' . $tk($m['price']) . '/mo' : '' ?></small>
              <?php elseif ($m['price'] !== null): ?><?= $m['price'] > 0 ? '<b>' . $tk($m['price']) . '<small>/mo</small></b>' : '<small class="muted">Included</small>' ?><?php endif ?>
              <?php if ($m['selectable'] && ($checked || $m['state'] === 'owner_off' || $sel['canAdd'])): ?>
                <input type="hidden" name="modules[<?= esc($m['code'], 'attr') ?>]" value="0">
                <label class="switch" title="<?= esc($m['name'], 'attr') ?>"><input type="checkbox" name="modules[<?= esc($m['code'], 'attr') ?>]" value="1" <?= checked($checked) ?> aria-label="Use <?= esc($m['name'], 'attr') ?>"><span></span></label>
              <?php elseif ($m['selectable']): ?><small class="muted">Upgrade to add</small><?php endif ?>
            </div>
          </article>
        <?php endforeach ?>
        </div></section>
        <?php endforeach ?>
      </div>
      <aside class="card msel-sum" aria-live="polite"><div class="card-h"><h3>Your monthly price</h3></div><div class="card-b">
        <?php if ($engine): ?>
          <label class="field msel-outlets">Outlets <small class="muted">(try a different number to see the price)</small>
            <input class="input" type="number" min="1" max="1000" step="1" name="outlets" value="<?= (int) $price['quote']['outlets'] ?>" data-current="<?= (int) $price['quote']['outlets'] ?>" aria-label="Number of outlets"></label>
          <small class="muted" data-outlets-note hidden>Preview only — add or close outlets under Settings › Outlets; your bill follows your active outlets.</small>
        <?php endif ?>
        <div class="bill-sum" data-msel-lines>
          <?php if ($engine): echo $rowsHtml($price['rows']); else: ?>
          <?php foreach ($sel['lines'] as $l): ?><div class="bs-r"><span><?= esc($l['label']) ?></span><b><?= $tk($l['monthly']) ?></b></div><?php endforeach ?>
          <div class="bs-r tot"><span>Total per month</span><b><?= $tk($sel['monthly']) ?></b></div>
          <?php endif ?>
        </div>
        <div class="msel-delta" data-msel-delta hidden></div>
        <p class="neg" data-msel-error hidden></p>
        <div class="bo-actions"><button class="btn primary" type="submit" data-msel-submit><?= icon('check', 'sm') ?>Confirm changes</button></div>
        <small class="muted"><?= $acc['status'] === 'trial' ? 'Add-ons and features are free during your trial and billed after it.' : 'Adding bills only the rest of this period (prorated) on your open invoice or a new one; removing lowers the price from the next renewal (no refund for the current period).' ?></small>
      </div></aside>
    </div>
    <div class="msel-bar"><div><small>Monthly price</small><b data-msel-total><?= $tk($engine ? $price['monthly'] : $sel['monthly']) ?></b></div><button class="btn primary" type="submit" data-msel-submit2 disabled><?= icon('check', 'sm') ?>Confirm changes</button></div>
  </form>
  <script src="<?= esc(base_url('assets/pos/subscription.js') . '?v=' . (is_file(FCPATH . 'assets/pos/subscription.js') ? filemtime(FCPATH . 'assets/pos/subscription.js') : 0), 'attr') ?>" defer></script>
  <link rel="stylesheet" href="<?= esc(base_url('assets/pos/subscription.css') . '?v=' . (is_file(FCPATH . 'assets/pos/subscription.css') ? filemtime(FCPATH . 'assets/pos/subscription.css') : 0), 'attr') ?>">

  <div class="sub-grid">
    <section class="card"><div class="card-h"><h3>Invoices</h3></div><?= $invoices ?></section>
    <div class="sub-side">
      <section class="card"><div class="card-h"><h3>Payment method</h3></div><div class="card-b">
        <form method="post" action="<?= esc(site_url('subscription/method'), 'attr') ?>"><?= csrf_field() ?>
          <div class="pm-list"><?php foreach ($methods as $m): ?><label class="pm <?= $acc['pay_method'] === $m ? 'on' : '' ?>" data-chip-check><input type="radio" name="method" value="<?= esc($m, 'attr') ?>" hidden <?= checked($acc['pay_method'] === $m) ?>><?= icon($m === 'Card' ? 'card' : 'mobile', 'sm') ?><?= esc($m) ?></label><?php endforeach ?></div>
          <label class="check"><input type="checkbox" name="auto_pay" value="1" <?= checked($acc['auto_pay']) ?>>Auto-pay renewals from the saved method</label>
          <div class="bo-actions"><button class="btn sm" type="submit">Save</button></div>
        </form>
      </div></section>
      <section class="card"><div class="card-h"><h3>Account</h3></div><div class="card-b sub-acc">
        <div><small class="muted">Workspace owner</small><b><?= esc($acc['owner_name']) ?></b><span><?= esc($acc['owner_email']) ?><?= $acc['owner_phone'] ? ' · ' . esc($acc['owner_phone']) : '' ?></span></div>
        <p class="muted privacy"><?= icon('shield', 'sm') ?> Platform staff manage your plan and billing only. They cannot open this workspace or see your sales, customers, products or staff.</p>
        <?php if (! $acc['cancel_at'] && in_array($acc['status'], ['active', 'past_due'], true)): ?>
          <form method="post" action="<?= esc(site_url('subscription/cancel'), 'attr') ?>" data-confirm="Cancel the subscription? You keep <?= esc($p['name'], 'attr') ?> until <?= esc(date_str($acc['period_end']), 'attr') ?>; then the workspace is locked (data kept)."><?= csrf_field() ?><button class="btn sm danger" type="submit">Cancel subscription</button></form>
        <?php endif ?>
      </div></section>
    </div>
  </div>
</div>
