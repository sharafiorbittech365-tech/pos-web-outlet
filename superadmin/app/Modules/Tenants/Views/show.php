<?php
/**
 * Tenant detail with tabs. Shows account data and usage counts only — never workspace records.
 *
 * @var array<string, mixed> $tenant
 * @var string               $tab
 * @var array<string, int>   $counts
 * @var float                $mrr
 * @var list<array>          $plans
 * @var array<string, array> $types
 */
helper('ui');
use App\Modules\Tenants\Services\TenantAccount;

$t = $tenant;
$id = (int) $t['id'];
$session = service('adminSession');
$catalog = service('catalog');
$tk = static fn ($n): string => money($n, ['decimals' => 0]);
$url = static fn (string $path): string => site_url('tenants/' . $id . '/' . $path);
$manage = $session->can('tenants.manage');
$plan = $catalog->plan((int) $t['plan_id']);
$limOutlets = $t['limit_outlets'] !== null ? (int) $t['limit_outlets'] : (int) $t['plan_outlets'];
$limUsers = $t['limit_users'] !== null ? (int) $t['limit_users'] : (int) $t['plan_users'];
$otp = session()->getFlashdata('otp');
$tabs = ['overview' => 'Overview', 'subscription' => 'Subscription', 'limits' => 'Limits', 'modules' => 'Modules', 'invoices' => 'Invoices (' . $counts['invoices'] . ')',
    'notes' => 'Notes (' . $counts['notes'] . ')', 'server' => 'POS server', 'actions' => 'Account actions'];
$meter = static function (string $label, string $ic, int $used, int $lim, bool $custom = false): string {
    $pct = $lim > 0 ? (int) min(100, round($used / $lim * 100)) : 0;
    $cls = $lim === 0 ? '' : ($used > $lim ? 'over' : ($pct >= 85 ? 'near' : ''));

    return '<div class="meter ' . $cls . '"><div class="meter-h">' . icon($ic, 'sm') . '<span class="grow">' . esc($label) . '</span><b>' . number_format($used) . '</b><small>/ '
        . ($lim > 0 ? number_format($lim) : '∞') . ($custom ? ' (custom)' : '') . '</small></div><div class="meter-bar"><i style="width:' . ($lim > 0 ? max(2, $pct) : 6) . '%"></i></div></div>';
};
$dlgHead = static fn (string $title): string => '<div class="modal-h"><h3>' . esc($title) . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>';
$dlgFoot = static fn (string $label, string $kind = 'primary'): string => '<div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn ' . $kind . '" type="submit">' . esc($label) . '</button></div>';
?>
<div class="page">
  <?php if (is_array($otp)): ?>
    <section class="card"><div class="card-h"><h3><?= icon('key', 'sm') ?> <?= esc($otp['title']) ?> — one-time password</h3><?= tag_html('Shown once', 'warn') ?></div><div class="card-b">
      <p class="muted" style="margin:0">Give these sign-in details to <b><?= esc($otp['name']) ?></b> over a verified channel. The password is not stored in readable form and will not be shown again; ask the owner to change it after signing in.</p>
      <div class="kv"><div><small>Email</small><b><?= esc($otp['email']) ?></b></div><div><small>One-time password</small><b><code class="otp"><?= esc($otp['password']) ?></code></b></div></div>
    </div></section>
  <?php endif ?>

  <div class="ten-head" style="--pc:<?= css_color($t['plan_color']) ?>"><span class="ten-logo"><?= esc(initials($t['name'])) ?></span>
    <div class="grow"><h2><?= esc($t['name']) ?></h2><span class="muted"><?= esc($t['plan_name']) ?> · <?= esc($t['billing_cycle']) ?> · <?= status_tag($t['status']) ?><?= $t['status'] === 'suspended' && $t['suspend_reason'] ? ' <small class="muted">(' . esc($t['suspend_reason']) . ')</small>' : '' ?><?= $t['cancel_at'] ? ' ' . tag_html('Ends ' . date_str($t['cancel_at']), 'warn') : '' ?></span></div>
    <div class="ten-kpis"><div><small>MRR</small><b><?= esc($tk($mrr)) ?></b></div><div><small>Unpaid</small><b class="<?= $t['open_count'] ? 'neg' : '' ?>"><?= esc($tk($t['open_total'])) ?></b></div></div></div>

  <form class="seg adm-tabs" method="get" action="<?= esc(site_url('tenants/' . $id), 'attr') ?>">
    <?php foreach ($tabs as $k => $l): ?><button class="<?= $tab === $k ? 'on' : '' ?>" type="submit" name="tab" value="<?= esc($k, 'attr') ?>"><?= esc($l) ?></button><?php endforeach ?>
  </form>

<?php if ($tab === 'overview'): ?>
  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Account</h3></div><div class="card-b kv">
      <div><small>Owner</small><b><?= esc($t['owner_name']) ?></b></div><div><small>Email</small><b><?= esc($t['owner_email']) ?></b></div>
      <div><small>Mobile</small><b><?= esc($t['owner_phone'] ?: '—') ?></b></div><div><small>Customer since</small><b><?= esc(date_str($t['created_at'])) ?></b></div>
      <div><small>Owner last sign-in</small><b><?= esc(datetime_str($lastLogin)) ?></b></div><div><small>Account ID</small><b><code><?= esc($t['code']) ?></code></b></div>
      <div><small>Business type (pricing)</small><b><?= sym_icon($t['type_icon']) ?> <?= esc($t['type_name']) ?></b></div><div><small>POS server</small><b><?= $t['server_name'] ? esc($t['server_name']) : tag_html('Unassigned', 'warn') ?></b></div>
      <div class="span2"><small>Outlet types</small><b><?php foreach ($outletTypes as $ot): ?><span class="tag"><?= esc($ot) ?></span> <?php endforeach ?><?= $outletTypes === [] ? '—' : '' ?></b></div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Usage</h3><small class="muted"><?= $t['usage_at'] ? 'Reported ' . esc(datetime_str($t['usage_at'])) : 'No report yet' ?></small></div><div class="card-b meters">
      <?= $meter('Outlets', 'store', (int) $t['outlets'], $limOutlets, $t['limit_outlets'] !== null) ?>
      <?= $meter('Team members', 'users', (int) $t['users'], $limUsers, $t['limit_users'] !== null) ?>
      <?= $meter('Products', 'tag', (int) $t['products'], 0) ?>
      <?= $meter('Orders this month', 'orders', (int) $t['orders_month'], 0) ?>
    </div></section>
  </div>
  <div class="adm-private"><?= icon('shield') ?><div><b>Workspace data is private</b><span>Sales, customers, products, staff and settings belong to <?= esc($t['name']) ?>. Platform staff can't open or change them. To help, ask the owner to share a screen or an export.</span></div></div>

<?php elseif ($tab === 'subscription'): ?>
  <section class="card"><div class="card-h"><h3>Subscription</h3><?= status_tag($t['status']) ?></div><div class="card-b">
    <div class="kv">
      <div><small>Plan base</small><b><?= esc($tk($catalog->planPrice((int) $t['plan_id'], (string) $t['business_type']) ?? 0)) ?>/month<?= service('pricing')->active() ? ' <small class="muted">per outlet</small>' : '' ?></b></div>
      <div><small>Add-on modules</small><b><?= $addons ? esc(implode(' · ', array_map(static fn (array $a): string => $a['name'] . ' ' . money($a['price'], ['decimals' => 0]), $addons))) : '—' ?></b></div>
      <div><small>Total bill</small><b><?= esc($tk($cycleTotal)) ?>/<?= $t['billing_cycle'] === 'yearly' ? 'year' : 'month' ?></b></div><div><small>MRR</small><b><?= esc($tk($mrr)) ?></b></div>
      <div><small><?= $t['status'] === 'trial' ? 'Trial ends' : 'Current period' ?></small><b><?= $t['status'] === 'trial' ? esc(date_str($t['trial_ends_at'])) : esc(date_str($t['period_start'])) . ' – ' . esc(date_str($t['period_end'])) ?></b></div>
      <div><small>Payment method</small><b><?= $t['pay_method'] ? esc($t['pay_method']) . ((int) $t['auto_pay'] ? ' · auto-pay' : '') : 'Not set' ?></b></div>
      <div><small>Scheduled change</small><b><?= $t['next_plan_id'] ? esc($catalog->plan((int) $t['next_plan_id'])['name']) . ' at renewal' : ($t['cancel_at'] ? 'Cancels ' . esc(date_str($t['cancel_at'])) : '—') ?></b></div>
    </div>
    <?php if (service('pricing')->active() && $session->can('plans.manage')): $pq = service('pricing')->quote($id); ?>
      <div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Pricing: <?= (int) $pq['outlets'] ?> outlet<?= (int) $pq['outlets'] === 1 ? '' : 's' ?> · <b><?= esc($tk($pq['monthly'])) ?>/month</b>
        <?= $pq['outlet_discount'] > 0 ? ' · outlet discount −' . esc($tk($pq['outlet_discount'])) : '' ?><?= $pq['customer_discount'] > 0 ? ' · customer discount −' . esc($tk($pq['customer_discount'])) : '' ?></span>
        <a class="btn sm" href="<?= esc(site_url('pricing/customer/' . $id), 'attr') ?>">Custom pricing</a></div>
    <?php endif ?>
    <?php if ($manage): ?>
      <form method="post" action="<?= esc($url('subscription'), 'attr') ?>"><?= csrf_field() ?>
        <div class="form-grid">
          <label class="field">Plan<select class="select" name="plan_id">
            <?php foreach ($plans as $p): $price = $catalog->planPrice((int) $p['id'], (string) $t['business_type']); ?>
              <option value="<?= (int) $p['id'] ?>" <?= selected($t['plan_id'], $p['id']) ?>><?= esc($p['name']) ?> · <?= $price !== null ? esc($tk($price)) . '/mo' : 'not offered for this type' ?><?= $p['is_active'] ? '' : ' (hidden)' ?></option>
            <?php endforeach ?></select></label>
          <label class="field">Business type (sets the price)<select class="select" name="business_type">
            <?php foreach ($types as $code => $ty): ?><option value="<?= esc($code, 'attr') ?>" <?= selected($t['business_type'], $code) ?>><?= esc($ty['name']) ?></option><?php endforeach ?></select></label>
          <label class="field">Billing cycle<select class="select" name="billing_cycle"><option value="monthly" <?= selected($t['billing_cycle'], 'monthly') ?>>Monthly</option><option value="yearly" <?= selected($t['billing_cycle'], 'yearly') ?>>Yearly</option></select></label>
          <label class="field"><?= $t['status'] === 'trial' ? 'Trial ends' : 'Current period ends' ?><input class="input" type="date" name="ends_on" value="<?= esc(substr((string) ($t['status'] === 'trial' ? $t['trial_ends_at'] : $t['period_end']), 0, 10), 'attr') ?>"><?= field_error('ends_on') ?></label>
          <label class="field">At next renewal switch to<select class="select" name="next_plan_id"><option value="">No change</option>
            <?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>" <?= selected($t['next_plan_id'], $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach ?></select></label>
        </div>
        <p class="muted">Changes here apply immediately without an invoice (comps, migrations, corrections). The owner sees them on their Subscription page.</p>
        <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save subscription</button>
          <?php if (in_array($t['status'], ['trial', 'expired'], true)): ?><button class="btn" type="submit" form="extend-trial"><?= icon('clock', 'sm') ?>Extend trial 7 days</button><?php endif ?></div>
      </form>
      <form id="extend-trial" method="post" action="<?= esc($url('extend-trial'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="days" value="7"></form>
    <?php else: ?><span class="tag">Read only for your role</span><?php endif ?>
  </div></section>
  <?php if (in_array($t['status'], ['trial', 'expired', 'cancelled'], true) && $session->can('billing.manage')): ?>
    <section class="card"><div class="card-h"><h3><?= $t['status'] === 'trial' ? 'Convert trial to paid' : 'Start a paid subscription' ?></h3></div><div class="card-b">
      <form method="post" action="<?= esc($url('convert'), 'attr') ?>" data-confirm="Issue the first invoice for this account?"><?= csrf_field() ?>
        <div class="form-grid">
          <label class="field">Plan<select class="select" name="plan_id"><?php foreach ($plans as $p): if ($catalog->planPrice((int) $p['id'], (string) $t['business_type']) === null) { continue; } ?>
            <option value="<?= (int) $p['id'] ?>" <?= selected($t['plan_id'], $p['id']) ?>><?= esc($p['name']) ?> · <?= esc($tk($catalog->planPrice((int) $p['id'], (string) $t['business_type']))) ?>/mo</option><?php endforeach ?></select></label>
          <label class="field">Billing cycle<select class="select" name="billing_cycle"><option value="monthly" <?= selected($t['billing_cycle'], 'monthly') ?>>Monthly</option><option value="yearly" <?= selected($t['billing_cycle'], 'yearly') ?>>Yearly</option></select></label>
          <label class="check span2"><input type="hidden" name="pay_now" value="0"><input type="checkbox" name="pay_now" value="1" checked>Payment received — record it and activate now</label>
          <label class="field">Method<select class="select" name="method"><?php foreach ($methods as $m): ?><option><?= esc($m) ?></option><?php endforeach ?></select></label>
          <label class="field">Reference<input class="input" name="reference" maxlength="64" placeholder="Transaction id (optional)"></label>
        </div>
        <p class="muted">Issues the start invoice (<?= (int) $catalog->setting('yearlyMonths', 10) ?> × monthly for yearly). Without a payment the account becomes active once the invoice is paid.</p>
        <div><button class="btn primary" type="submit"><?= icon('card', 'sm') ?>Start paid subscription</button></div>
      </form>
    </div></section>
  <?php endif ?>

<?php elseif ($tab === 'limits'): ?>
  <form method="post" action="<?= esc($url('limits'), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Custom limits</h3><small class="muted">Blank = use the <?= esc($t['plan_name']) ?> plan · 0 = unlimited · products &amp; orders are always unlimited</small></div><div class="card-b">
      <div class="form-grid">
        <label class="field">Outlets <small class="muted">plan: <?= (int) $t['plan_outlets'] ?: '∞' ?> · using <?= (int) $t['outlets'] ?></small><input class="input" type="number" min="0" max="10000" name="limit_outlets" value="<?= old_or('limit_outlets', $t['limit_outlets']) ?>" placeholder="<?= (int) $t['plan_outlets'] ?: 'Unlimited' ?>" <?= $manage ? '' : 'disabled' ?>><?= field_error('limit_outlets') ?></label>
        <label class="field">Team members <small class="muted">plan: <?= (int) $t['plan_users'] ?: '∞' ?> · using <?= (int) $t['users'] ?></small><input class="input" type="number" min="0" max="10000" name="limit_users" value="<?= old_or('limit_users', $t['limit_users']) ?>" placeholder="<?= (int) $t['plan_users'] ?: 'Unlimited' ?>" <?= $manage ? '' : 'disabled' ?>><?= field_error('limit_users') ?></label>
      </div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Features</h3><small class="muted">Override what the plan gives this account</small></div><div class="card-b">
      <div class="feat-ov">
        <?php foreach (TenantAccount::FEATURES as $k => $label): $v = array_key_exists($k, $t['feature_overrides']) ? ($t['feature_overrides'][$k] ? 'on' : 'off') : 'plan'; $planOn = ! empty($t['plan_features'][$k]); ?>
          <label class="field"><?= esc($label) ?><select class="select" name="feature_<?= esc($k, 'attr') ?>" <?= $manage ? '' : 'disabled' ?>>
            <option value="plan" <?= selected($v, 'plan') ?>>Use plan (<?= $planOn ? 'on' : 'off' ?>)</option><option value="on" <?= selected($v, 'on') ?>>On</option><option value="off" <?= selected($v, 'off') ?>>Off</option></select></label>
        <?php endforeach ?>
      </div>
    </div></section>
    <section class="card"><div class="card-h"><h3>POS features</h3><small class="muted">Default: what <?= esc($t['type_name']) ?> outlets are offered (Super Admin › Business types). On = offered to this account's outlets anyway · Off = never</small></div><div class="card-b">
      <?php $offered = \OmniPOS\Core\Services\BusinessTypeSetup::offered((string) $t['business_type']); ?>
      <div class="feat-ov">
        <?php foreach (TenantAccount::posFeatures() as $k => $label): $v = array_key_exists($k, $t['feature_overrides']) ? ($t['feature_overrides'][$k] ? 'on' : 'off') : 'plan'; $typeOn = in_array(substr($k, 4), $offered, true); ?>
          <label class="field"><?= esc($label) ?><select class="select" name="posf_<?= esc(substr($k, 4), 'attr') ?>" <?= $manage ? '' : 'disabled' ?>>
            <option value="plan" <?= selected($v, 'plan') ?>>Business type (<?= $typeOn ? 'offered' : 'hidden' ?>)</option><option value="on" <?= selected($v, 'on') ?>>Offer</option><option value="off" <?= selected($v, 'off') ?>>Never</option></select></label>
        <?php endforeach ?>
      </div>
      <?php if ($manage): ?><div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save limits &amp; features</button></div><?php endif ?>
    </div></section>
  </form>

<?php elseif ($tab === 'modules'): ?>
  <div class="sbanner info"><?= icon('shield', 'sm') ?><span class="grow">Only platform staff decide which modules this workspace gets. The owner sees them read-only. A module whose required modules are off stays off.</span></div>
  <form method="post" action="<?= esc($url('modules'), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Modules</h3><small class="muted">Plan: <?= esc($t['plan_name']) ?> · prices for <?= esc($t['type_name']) ?></small></div>
      <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Module</th><th>Plan</th><th>Add-on</th><th>For this tenant</th><th>Customer may change</th><th>Now</th></tr></thead><tbody>
        <?php foreach ($modules as $m): ?>
          <tr>
            <td data-l="Module"><span class="mp-mod"><?= icon($m['icon'] ?: 'grid', 'sm') ?><span><b><?= esc($m['name']) ?></b>
              <?php if ($m['needs']): ?><small class="muted">Needs <?= esc(implode(', ', $m['needs'])) ?></small><?php endif ?>
              <?php if ($m['conflicts'] ?? []): ?><small class="muted">Cannot run with <?= esc(implode(', ', $m['conflicts'])) ?></small><?php endif ?>
              <?php if ($m['opt_in']): ?><small class="muted">Optional · off unless switched on, even on all-module plans</small><?php endif ?>
              <?php if ($m['recommended'] ?? false): ?><small><?= tag_html('Recommended for ' . $t['type_name'], 'primary') ?></small><?php endif ?>
              <?php if ($m['owner_off'] ?? false): ?><small class="muted">Switched off by the customer</small><?php endif ?>
              <?php foreach ($m['features'] ?? [] as $f): ?><small class="muted mp-feat"><?= icon($f['on'] ? 'check' : 'minus', 'sm') ?> <?= esc($f['name']) ?> · <?= $f['default'] ? 'included' : ($f['price'] === null ? 'not offered' : esc($tk($f['price'])) . '/mo') ?><?= $f['chosen'] !== null ? ' · chosen by customer' : '' ?>
                <select class="select sm" name="feat[<?= esc(str_replace('.', ':', $f['code']), 'attr') ?>]" <?= $manage ? '' : 'disabled' ?> aria-label="<?= esc($f['name'], 'attr') ?> for this tenant">
                  <option value="plan" <?= selected($f['admin'], 'plan') ?>>Plan / customer</option><option value="on" <?= selected($f['admin'], 'on') ?>>On</option><option value="off" <?= selected($f['admin'], 'off') ?>>Off</option></select></small><?php endforeach ?></span></span></td>
            <td data-l="Plan"><?= $m['core'] ? tag_html('Core', 'ok') : ($m['in_plan'] ? tag_html('In plan', 'ok') : ($m['price'] === null ? tag_html('Not for this type') : tag_html('Not included'))) ?></td>
            <td data-l="Add-on"><?php if (! $m['core'] && ! $m['in_plan'] && $m['price'] !== null): ?>
              <label class="check"><input type="checkbox" name="addons[]" value="<?= esc($m['code'], 'attr') ?>" <?= checked($m['addon']) ?> <?= $manage ? '' : 'disabled' ?>>Bought · <?= esc($tk($m['price'])) ?>/mo</label>
            <?php elseif ($m['addon']): ?><input type="hidden" name="addons[]" value="<?= esc($m['code'], 'attr') ?>"><small class="muted">Bought (not billed)</small><?php else: ?><span class="muted">—</span><?php endif ?></td>
            <td data-l="Tenant"><?php if ($m['core']): ?><span class="muted">Always on</span><?php else: ?>
              <select class="select" name="mod[<?= esc($m['code'], 'attr') ?>]" <?= $manage ? '' : 'disabled' ?>>
                <option value="plan" <?= selected($m['override'], 'plan') ?>>Use plan / add-on</option><option value="on" <?= selected($m['override'], 'on') ?>>On</option><option value="off" <?= selected($m['override'], 'off') ?>>Off</option></select><?php endif ?></td>
            <td data-l="Customer"><?php if ($m['core']): ?><span class="muted">—</span><?php else: ?>
              <select class="select" name="lock[<?= esc($m['code'], 'attr') ?>]" <?= $manage ? '' : 'disabled' ?> aria-label="Self-selection of <?= esc($m['name'], 'attr') ?>">
                <option value="default" <?= selected($m['lock'] ?? 'default', 'default') ?>>Default (<?= ($m['self_select'] ?? true) ? 'yes' : 'no' ?>)</option><option value="allow" <?= selected($m['lock'] ?? '', 'allow') ?>>Allowed</option><option value="lock" <?= selected($m['lock'] ?? '', 'lock') ?>>Locked</option></select><?php endif ?></td>
            <td data-l="Now"><?= $m['on'] ? tag_html('On', 'ok') : tag_html('Off') ?><?php if (! $m['on'] && ($m['reason'] ?? null)): ?><br><small class="muted"><?= esc($m['reason']) ?></small><?php endif ?></td>
          </tr>
        <?php endforeach ?>
      </tbody></table></div>
      <?= field_error('mod') ?>
      <?php if ($manage): ?><div class="card-b"><div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save modules</button></div></div><?php endif ?>
    </section>
  </form>

<?php elseif ($tab === 'invoices'): ?>
  <section class="card"><div class="card-h"><h3>Invoices</h3></div>
    <?= $table ?? '<div class="card-b"><span class="tag">Your role cannot see invoices (billing.view).</span></div>' ?>
  </section>

<?php elseif ($tab === 'notes'): ?>
  <section class="card"><div class="card-b">
    <?php if ($session->can('tenants.note')): ?>
      <form class="note-new" method="post" action="<?= esc($url('notes'), 'attr') ?>"><?= csrf_field() ?>
        <textarea class="input" name="body" rows="2" maxlength="2000" required placeholder="Support or billing note (only platform staff see this)"></textarea>
        <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add note</button></form><?= field_error('body') ?>
    <?php endif ?>
  </div><?= $table ?></section>

<?php elseif ($tab === 'server'): ?>
  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Current POS server</h3></div><div class="card-b kv">
      <div><small>Server</small><b><?= $t['server_name'] ? esc($t['server_name']) . ' <code>' . esc($t['server_code']) . '</code>' : tag_html('Unassigned', 'warn') ?></b></div>
      <div><small>Status</small><b><?= $t['server_status'] ? status_tag($t['server_status']) : '—' ?></b></div>
      <div><small>Sign-in URL</small><b><?= $t['server_url'] ? esc($t['server_url']) : '—' ?></b></div>
      <div><small>Since</small><b><?= esc(datetime_str($t['assigned_at'])) ?></b><?php if ($t['assign_reason']): ?><span class="muted"><?= esc($t['assign_reason']) ?></span><?php endif ?></div>
    </div></section>
    <section class="card"><div class="card-h"><h3>Move to another server</h3></div><div class="card-b">
      <?php if ($session->can('servers.manage')): ?>
        <form method="post" action="<?= esc($url('server'), 'attr') ?>" data-confirm="Move this account? Its users sign in again on the new server."><?= csrf_field() ?>
          <div class="form-grid one">
            <label class="field">Server<select class="select" name="server_id" required>
              <?php foreach ($servers as $s): if ((int) $s['id'] === (int) $t['server_id']) { continue; } ?>
                <option value="<?= (int) $s['id'] ?>" <?= $s['status'] !== 'active' ? 'disabled' : '' ?>><?= esc($s['name']) ?> · <?= (int) $s['tenants'] ?>/<?= (int) $s['capacity'] ?><?= $s['status'] !== 'active' ? ' · ' . esc($s['status']) : '' ?></option>
              <?php endforeach ?></select></label>
            <label class="field">Reason<input class="input" name="reason" required maxlength="160" placeholder="e.g. Rebalancing, closer region"><?= field_error('reason') ?></label>
          </div>
          <p class="muted">No data moves — the database is shared. The old server refuses the account from its next request and sends users to the new one.</p>
          <button class="btn primary" type="submit"><?= icon('swap', 'sm') ?>Move account</button>
        </form>
      <?php else: ?><span class="tag">Your role cannot move accounts (servers.manage).</span><?php endif ?>
    </div></section>
  </div>
  <section class="card"><div class="card-h"><h3>Assignment history</h3></div><?= $table ?></section>

<?php elseif ($tab === 'actions'): ?>
  <div class="act-grid">
    <?php if ($manage): ?>
      <?php if (in_array($t['status'], ['suspended', 'expired', 'cancelled'], true)): ?>
        <section class="card act-card primary"><div class="card-b"><span class="act-ic"><?= icon('play') ?></span><b>Reactivate account</b>
          <p class="muted"><?= $t['status'] === 'suspended' ? 'Unlock the workspace now.' . ($t['open_count'] ? ' ' . (int) $t['open_count'] . ' invoice(s) are still unpaid.' : '') : 'Starts a fresh paid period on the current plan and issues an invoice for it.' ?></p>
          <form method="post" action="<?= esc($url('reactivate'), 'attr') ?>" data-confirm="Reactivate <?= esc($t['name'], 'attr') ?>?"><?= csrf_field() ?><button class="btn primary" type="submit">Reactivate account</button></form></div></section>
      <?php else: ?>
        <section class="card act-card danger"><div class="card-b"><span class="act-ic"><?= icon('ban') ?></span><b>Suspend account</b>
          <p class="muted">Locks the workspace for everyone. Data is kept. The owner sees the reason.</p>
          <button class="btn danger" type="button" data-modal-open="dlg-suspend">Suspend account</button></div></section>
      <?php endif ?>
      <section class="card act-card"><div class="card-b"><span class="act-ic"><?= icon('shield') ?></span><b>Reset owner password</b>
        <p class="muted">Creates a one-time password to give to the verified owner. You never see their current password.</p>
        <form method="post" action="<?= esc($url('reset-password'), 'attr') ?>" data-confirm="Only do this after verifying the owner (<?= esc($t['owner_name'] . ', ' . ($t['owner_phone'] ?: $t['owner_email']), 'attr') ?>). Their current password stops working."><?= csrf_field() ?><button class="btn" type="submit">Reset owner password</button></form></div></section>
      <?php if (in_array($t['status'], ['active', 'past_due'], true)): ?>
        <section class="card act-card"><div class="card-b"><span class="act-ic"><?= icon('x') ?></span><b><?= $t['cancel_at'] ? 'Withdraw cancellation' : 'Cancel at period end' ?></b>
          <p class="muted"><?= $t['cancel_at'] ? 'Currently ends ' . esc(date_str($t['cancel_at'])) . '.' : 'Stops renewals. The workspace stays usable until the paid period ends.' ?></p>
          <form method="post" action="<?= esc($url('cancel'), 'attr') ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= $t['cancel_at'] ? 'Withdraw cancellation' : 'Cancel at period end' ?></button></form></div></section>
      <?php endif ?>
    <?php endif ?>
    <?php if ($session->can('tenants.delete')): ?>
      <section class="card act-card danger"><div class="card-b"><span class="act-ic"><?= icon('trash') ?></span><b>Delete account</b>
        <p class="muted">Removes the account at once and voids open invoices. Workspace data is erased after <?= (int) config(\OmniPOS\Core\Config\OmniPOS::class)->tenantRetentionDays ?> days. Paid invoices are kept for accounting.</p>
        <button class="btn danger" type="button" data-modal-open="dlg-delete">Delete account</button></div></section>
    <?php endif ?>
    <?php if (! $manage && ! $session->can('tenants.delete')): ?><span class="tag">Your role cannot change this account.</span><?php endif ?>
  </div>

  <?php if ($manage): ?>
    <dialog class="modal-dlg" id="dlg-suspend"><form method="post" action="<?= esc($url('suspend'), 'attr') ?>"><?= csrf_field() ?><?= $dlgHead('Suspend ' . $t['name']) ?>
      <div class="modal-b"><div class="form-grid">
        <label class="field span2">Reason (shown to the owner)<select class="select" name="reason"><?php foreach (TenantAccount::SUSPEND_REASONS as $r): ?><option><?= esc($r) ?></option><?php endforeach ?></select></label>
        <label class="field span2">Internal note<input class="input" name="note" maxlength="500" placeholder="Optional"></label></div>
        <p class="muted" style="margin:0">Everyone in this workspace is locked out until it is reactivated. No data is deleted.</p></div>
      <?= $dlgFoot('Suspend account', 'danger') ?></form></dialog>
  <?php endif ?>
  <?php if ($session->can('tenants.delete')): ?>
    <dialog class="modal-dlg" id="dlg-delete"><form method="post" action="<?= esc($url('delete'), 'attr') ?>"><?= csrf_field() ?><?= $dlgHead('Delete ' . $t['name']) ?>
      <div class="modal-b"><p class="muted" style="margin:0">The account disappears from Super Admin and its users can no longer sign in. Type the account name to confirm.</p>
        <label class="field">Account name<input class="input" name="confirm" required maxlength="160" autocomplete="off" placeholder="<?= esc($t['name'], 'attr') ?>"><?= field_error('confirm') ?></label></div>
      <?= $dlgFoot('Delete account', 'danger') ?></form></dialog>
  <?php endif ?>
<?php endif ?>
</div>
