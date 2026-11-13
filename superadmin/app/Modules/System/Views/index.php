<?php
/**
 * @var array<string, mixed> $settings
 * @var list<array>          $plans
 * @var array<string, mixed> $info
 * @var array<string, mixed> $gateway  StorefrontGateway::status()
 * @var string|null          $newToken a just-generated gateway token (flash, shown once)
 */
helper('ui');
use App\Modules\System\Services\PlatformSettings;

$s = $settings;
$kv = static function (array $pairs): string {
    $out = '';
    foreach ($pairs as $k => $v) {
        $out .= '<div><small>' . esc($k) . '</small><b>' . esc((string) $v) . '</b></div>';
    }

    return $out;
};
?>
<div class="page">
  <form method="post" action="<?= esc(site_url('system/brand'), 'attr') ?>" id="brand"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Brand &amp; company</h3><small class="muted">Shown in every app: sidebar, sign-in, invoices, emails and website addresses</small></div><div class="card-b">
      <?php if (! $brand['writable']): ?><div class="sbanner warn"><?= icon('alert', 'sm') ?><span class="grow">Set <code>omnipos.sharedCachePath</code> in each app's .env so brand changes can be saved and shared.</span></div><?php endif ?>
      <div class="form-grid">
        <label class="field">Brand name<input class="input" name="brandName" value="<?= old_or('brandName', $brand['stored']['name'] ?? '') ?>" maxlength="60" placeholder="<?= esc($brand['name'], 'attr') ?>"><small class="muted">Now: <b><?= esc($brand['name']) ?></b></small><?= field_error('brandName') ?></label>
        <label class="field">Company (legal) name<input class="input" name="company" value="<?= old_or('company', $brand['stored']['company'] ?? '') ?>" maxlength="120" placeholder="<?= esc($brand['company'], 'attr') ?>"></label>
        <label class="field">Main domain<input class="input" name="baseUrl" value="<?= old_or('baseUrl', $brand['stored']['baseUrl'] ?? '') ?>" maxlength="190" placeholder="<?= esc($brand['baseUrl'], 'attr') ?>"><small class="muted">App addresses are built from it: admin., pos., api. …</small><?= field_error('baseUrl') ?></label>
        <label class="field">Hosted websites domain<input class="input" name="siteHost" value="<?= old_or('siteHost', $brand['stored']['siteHost'] ?? '') ?>" maxlength="190" placeholder="<?= esc($brand['siteHost'], 'attr') ?>"><small class="muted">Customer websites: shop-name.<?= esc($brand['siteHost']) ?></small><?= field_error('siteHost') ?></label>
        <label class="field span2">Company address<input class="input" name="address" value="<?= old_or('address', $brand['stored']['address'] ?? '') ?>" maxlength="255" placeholder="Street, city, country"></label>
      </div>
      <small class="muted">Leave a field empty to use the .env value or the default. Customer websites already published keep working on their current address.</small>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save brand</button></div>
    </div></section>
  </form>
  <form method="post" action="<?= esc(site_url('system/settings'), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Platform</h3></div><div class="card-b"><div class="form-grid">
      <label class="field">Product name<input class="input" name="name" value="<?= old_or('name', $s['name']) ?>" required maxlength="80"><?= field_error('name') ?></label>
      <label class="field">Support phone<input class="input" name="supportPhone" value="<?= old_or('supportPhone', $s['supportPhone']) ?>" maxlength="32"><?= field_error('supportPhone') ?></label>
      <label class="field span2">Support email<input class="input" type="email" name="supportEmail" value="<?= old_or('supportEmail', $s['supportEmail']) ?>" required maxlength="160"><?= field_error('supportEmail') ?></label>
    </div></div></section>
    <section class="card"><div class="card-h"><h3>Sign-up &amp; billing</h3></div><div class="card-b"><div class="form-grid">
      <label class="field">Free trial length (days)<input class="input" type="number" min="0" max="90" name="trialDays" value="<?= old_or('trialDays', $s['trialDays']) ?>" required><?= field_error('trialDays') ?></label>
      <label class="field">Trial plan<select class="select" name="trialPlan"><?php foreach ($plans as $p): ?><option value="<?= esc($p['code'], 'attr') ?>" <?= selected(old('trialPlan') ?? $s['trialPlan'], $p['code']) ?>><?= esc($p['name']) ?></option><?php endforeach ?></select><?= field_error('trialPlan') ?></label>
      <label class="field">Grace period before suspension (days)<input class="input" type="number" min="0" max="60" name="graceDays" value="<?= old_or('graceDays', $s['graceDays']) ?>" required><?= field_error('graceDays') ?></label>
      <label class="field">VAT on subscriptions (%)<input class="input" type="number" min="0" max="50" step="any" name="vat" value="<?= old_or('vat', $s['vat']) ?>" required><?= field_error('vat') ?></label>
      <label class="field">Yearly price = monthly × (months)<input class="input" type="number" min="1" max="12" name="yearlyMonths" value="<?= old_or('yearlyMonths', $s['yearlyMonths'] ?? 10) ?>" required><?= field_error('yearlyMonths') ?></label>
      <label class="check span2"><input type="hidden" name="signup" value="0"><input type="checkbox" name="signup" value="1" <?= checked((bool) (old('signup') ?? $s['signup'])) ?>>Allow self sign-up from the POS sign-in page</label>
    </div><div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save settings</button></div></div></section>
  </form>

  <div class="adm-grid2">
    <section class="card"><div class="card-h"><h3>Database schema</h3><?= $info['schema'] ? tag_html('Up to date', 'ok') : tag_html('Not migrated', 'danger') ?></div><div class="card-b kv">
      <?php if ($info['schema']): ?>
        <?= $kv(['Schema version' => $info['schema']['version'], 'Latest migration' => $info['schema']['class'], 'Migrations applied' => $info['schema']['count'], 'Applied' => datetime_str($info['schema']['at']) . ' · batch ' . $info['schema']['batch']]) ?>
      <?php else: ?><div class="span2"><small>Migrations</small><b>None — run php spark migrate --all in the database project</b></div><?php endif ?>
    </div></section>
    <section class="card"><div class="card-h"><h3>Application</h3></div><div class="card-b kv"><?= $kv($info['app']) ?></div></section>
  </div>

  <?php
  $g = $gateway;
  $gTag = ['platform' => tag_html('Managed here', 'ok'), 'env' => tag_html('Not generated yet', 'warn'), 'disabled' => tag_html('Disabled', 'danger')][$g['source']];
  $managed = $g['source'] !== 'env';
  $sfUrl = rtrim(config(\OmniPOS\Core\Config\OmniPOS::class)->storefrontUrl, '/');
  ?>
  <section class="card" id="gateway">
    <div class="card-h"><h3><?= icon('key', 'sm') ?> Storefront gateway token</h3><?= $gTag ?></div>
    <div class="card-b" style="display:grid;gap:14px">
      <p class="muted" style="margin:0">The hosted Storefront app (<code><?= esc($sfUrl) ?></code>) sends this secret to the API to open any shop by its domain or <code>?site=</code>. Once generated here, only its fingerprint is kept and the API checks it straight away — <code>apps/api/.env</code> no longer needs it.</p>
      <?php if (is_string($newToken) && $newToken !== ''): ?>
        <div class="key-once" style="display:grid;gap:8px;padding:14px;border-radius:12px;border:1px dashed var(--warn);background:var(--warn-soft)">
          <b><?= icon('alert', 'sm') ?> New token — copy it now, it will not be shown again</b>
          <span class="muted">Put this line in <code>apps/storefront/.env</code> on every storefront server, then reload PHP:</span>
          <code id="gw-line" style="font-size:13.5px;word-break:break-all;user-select:all">omnipos.storefrontGatewayToken = <?= esc($newToken) ?></code>
          <div><button class="btn sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('gw-line').textContent).then(function(){this.textContent='Copied'}.bind(this))"><?= icon('copy', 'sm') ?>Copy line</button></div>
        </div>
      <?php endif ?>
      <div class="kv">
        <div><small>Status</small><b><?= esc(['platform' => 'Active — the API accepts the token generated here', 'env' => 'The API uses omnipos.storefrontGatewayToken from apps/api/.env', 'disabled' => 'Disabled — hosted storefronts are offline'][$g['source']]) ?></b></div>
        <div><small>Current token</small><b><?= $managed ? '<code>' . esc($g['prefix']) . '…' . esc($g['last4']) . '</code>' : 'the .env value (not visible here)' ?></b></div>
        <?php if ($g['created_at']): ?><div><small>Generated</small><b><?= esc(datetime_str($g['created_at'])) ?> · <?= esc((string) $g['created_by']) ?></b></div><?php endif ?>
        <?php if ($g['previous']): ?><div><small>Old token still accepted</small><b><code><?= esc($g['previous']['prefix']) ?>…<?= esc($g['previous']['last4']) ?></code> until <?= esc(datetime_str($g['previous']['valid_until'])) ?></b></div><?php endif ?>
        <?php if ($g['env_valid_until']): ?><div><small>.env token still accepted</small><b>until <?= esc(datetime_str($g['env_valid_until'])) ?></b></div><?php endif ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
        <form method="post" action="<?= esc(site_url('system/gateway/rotate'), 'attr') ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0"
          data-confirm="Generate a new token? Put it in apps/storefront/.env before the old one stops."><?= csrf_field() ?>
          <?php if ($g['source'] !== 'disabled'): ?>
            <label class="field" style="margin:0"><?= $managed ? 'Old token' : 'The .env token' ?> keeps working for<select class="select" name="grace"><?php foreach (\App\Modules\System\Services\GatewayAdmin::GRACE as $h => $l): ?><option value="<?= $h ?>" <?= $h === 24 ? 'selected' : '' ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <?php else: ?><input type="hidden" name="grace" value="0"><?php endif ?>
          <button class="btn primary" type="submit"><?= icon('refresh', 'sm') ?><?= $g['source'] === 'platform' ? 'Rotate token' : 'Generate token' ?></button>
        </form>
        <?php if ($g['source'] === 'platform'): ?>
          <form method="post" action="<?= esc(site_url('system/gateway/disable'), 'attr') ?>" style="margin:0" data-confirm="Disable the gateway? Every hosted storefront (shops opened by domain or ?site=) goes offline until a new token is generated. Shops using their own pk_live key or CLIENT_ID keep working."><?= csrf_field() ?>
            <button class="btn danger" type="submit"><?= icon('ban', 'sm') ?>Disable</button></form>
        <?php endif ?>
      </div>
      <p class="muted" style="margin:0;font-size:12.5px">One shop only? A storefront can instead use that shop's own publishable key (<code>pk_live_…</code>, made by the owner in POS › Website › API keys) as <code>omnipos.storefrontGatewayToken</code> — it then always opens that shop. Shops on their own hosting use <code>CLIENT_ID</code> / <code>CLIENT_SECRET</code>.</p>
    </div>
  </section>

  <section class="card"><div class="card-h"><h3>Platform configuration</h3><small class="muted">from .env (omnipos.*)</small></div><div class="card-b kv"><?= $kv($info['platform']) ?></div></section>

  <section class="card"><div class="card-h"><h3>Scheduled jobs (cron)</h3></div>
    <div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Schedule</th><th>Runs on</th><th>Command</th><th>What it does</th></tr></thead><tbody>
      <?php foreach (PlatformSettings::CRON as [$when, $where, $cmd, $what]): ?>
        <tr><td data-l="Schedule"><code><?= esc($when) ?></code></td><td data-l="Runs on"><?= esc($where) ?></td><td data-l="Command"><code><?= esc($cmd) ?></code></td><td data-l="What"><?= esc($what) ?></td></tr>
      <?php endforeach ?>
    </tbody></table></div></section>
</div>
