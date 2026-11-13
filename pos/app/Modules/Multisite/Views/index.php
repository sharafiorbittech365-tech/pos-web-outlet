<?php
/**
 * POS › Website › Multi-outlet website.
 *
 * @var array<string, mixed>       $ms       multisites row
 * @var list<array<string, mixed>> $outlets  every active outlet with on / sort / products
 * @var ?array<string, mixed>      $key      the current website key (prefix, last4, …) or null
 * @var array<string, string>      $addr     platform, preview, api
 * @var ?string                    $newKey   the key just issued (shown once)
 */
helper('ui');
$tabs = [['', 'Overview', 'grid'], ['storefront', 'Storefront', 'store'], ['domains', 'Domains & SSL', 'globe'], ['selfhosted', 'Self-hosted', 'server'], ['api', 'API keys', 'key'], ['webhooks', 'Webhooks', 'send'], ['sync', 'Sync & cache', 'refresh']];
$post = static fn (string $path): string => esc(site_url('website/multisite/' . $path), 'attr');
$shown = array_values(array_filter($outlets, static fn (array $o): bool => $o['on']));
$live = (bool) $ms['is_enabled'];
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/pos/multisite.css'), 'attr') ?>">
<div class="page msite">
  <div class="page-h">
    <div><h2>Website</h2><p class="page-sub">One website for all your outlets — customers see where each product is available and order from the outlet they choose.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc($addr['preview'], 'attr') ?>" target="_blank" rel="noopener"><?= icon('external', 'sm') ?>Preview website</a>
  </div>
  <div class="seg"><?php foreach ($tabs as [$k, $l, $ic]): ?><a href="<?= esc(site_url('website') . ($k === '' ? '' : '?tab=' . $k), 'attr') ?>"><?= icon($ic, 'sm') ?><?= esc($l) ?></a><?php endforeach ?><a class="on" href="<?= esc(site_url('website/multisite'), 'attr') ?>"><?= icon('store', 'sm') ?>Multi-outlet</a></div>

  <section class="card wlive"><div class="card-b">
    <div class="wl-url"><span class="wl-dot <?= $live ? '' : 'off' ?>"></span><div class="grow"><small class="muted"><?= $live ? 'Your multi-outlet website is live at' : 'Not published yet — planned address' ?></small>
      <b><?= esc($ms['domain'] ? 'https://' . $ms['domain'] : $addr['platform']) ?></b></div>
      <?= $live ? tag_html('Published', 'ok') : tag_html('Off', 'warn') ?>
      <span class="muted"><?= count($shown) ?> of <?= count($outlets) ?> outlets shown</span></div>
  </div></section>

  <div class="msite-grid">
    <section class="card"><div class="card-h"><h3><?= icon('sliders', 'sm') ?> Settings</h3></div><div class="card-b">
      <form method="post" action="<?= $post('settings') ?>" class="form-grid">
        <?= csrf_field() ?>
        <label class="field">Website name<input class="input" name="name" maxlength="120" value="<?= old_or('name', $ms['name'] ?? '') ?>" placeholder="Default: your account name"><?= field_error('name') ?></label>
        <label class="field">Tagline<input class="input" name="tagline" maxlength="200" value="<?= old_or('tagline', $ms['tagline'] ?? '') ?>" placeholder="e.g. Order from the outlet nearest to you"><?= field_error('tagline') ?></label>
        <label class="field">Address on <?= esc(brand_name()) ?><span class="slug-in"><input class="input" name="slug" maxlength="48" required value="<?= old_or('slug', $ms['slug']) ?>" aria-label="Address"><span>.<?= esc(config(\OmniPOS\Core\Config\OmniPOS::class)->multisiteHost) ?></span></span><?= field_error('slug') ?></label>
        <label class="field">Own domain (optional)<input class="input" name="domain" maxlength="190" value="<?= old_or('domain', $ms['domain'] ?? '') ?>" placeholder="www.yourstores.com"><small class="muted">For a website on your own server with the key below; used for links and search engines.</small><?= field_error('domain') ?></label>
        <label class="field">Brand from<select class="input" name="home_business_id">
          <?php foreach ($outlets as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) (old('home_business_id') ?? $ms['home_business_id']) === $o['id'] ? 'selected' : '' ?>><?= esc($o['name']) ?></option><?php endforeach ?>
        </select><small class="muted">Logo, colours, theme and currency come from this outlet's <a class="link" href="<?= esc(site_url('website') . '?tab=storefront', 'attr') ?>">Storefront</a> settings.</small><?= field_error('home_business_id') ?></label>
        <label class="check span2"><input type="checkbox" name="is_enabled" value="1" <?= checked($live) ?>>Publish the multi-outlet website</label>
        <div class="span2"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save settings</button></div>
      </form>
    </div></section>

    <section class="card" id="key"><div class="card-h"><h3><?= icon('key', 'sm') ?> Website key</h3><small class="muted">For running the website on your own server</small></div><div class="card-b">
      <?php if ($newKey): ?>
        <div class="new-secret">
          <p class="muted" style="margin:0">Copy into the website's <code>.env</code> now — <?= esc(brand_name()) ?> keeps only a fingerprint and cannot show it again.</p>
          <?php $envBlock = 'MULTISITE_KEY = ' . $newKey . "\nPOS_API_URL = " . $addr['api']; ?>
          <pre class="env-block"><code><?= esc($envBlock) ?></code></pre>
          <div class="row-add"><button class="btn sm primary" type="button" data-copy="<?= esc($envBlock, 'attr') ?>"><?= icon('copy', 'sm') ?>Copy .env lines</button></div>
        </div>
      <?php endif ?>
      <?php if ($key !== null): ?>
        <div class="kv"><div><small>Current key</small><b><code><?= esc($key['prefix']) ?>••••<?= esc($key['last4']) ?></code></b></div>
          <div><small>Issued</small><b><?= esc(date_str($key['created_at'])) ?></b></div>
          <div><small>Last used</small><b><?= $key['last_used_at'] ? esc(datetime_str($key['last_used_at'])) : '<span class="muted">Never</span>' ?></b></div></div>
      <?php else: ?>
        <p class="muted">No key issued. The website hosted by <?= esc(brand_name()) ?> does not need one.</p>
      <?php endif ?>
      <p class="muted">The key is a server-side secret: it reads the outlets you show here and places orders into them — nothing else. Never put it in a browser or app.</p>
      <div class="row-add">
        <form class="inline" method="post" action="<?= $post('key') ?>" <?= $key !== null ? 'data-confirm="Issue a new key? The current key stops working immediately."' : '' ?>><?= csrf_field() ?><button class="btn sm primary" type="submit"><?= icon('key', 'sm') ?><?= $key !== null ? 'Rotate key' : 'Issue key' ?></button></form>
        <?php if ($key !== null): ?><form class="inline" method="post" action="<?= $post('key/revoke') ?>" data-confirm="Revoke the website key? A website on your own server stops working immediately."><?= csrf_field() ?><button class="btn sm danger" type="submit">Revoke</button></form><?php endif ?>
      </div>
    </div></section>
  </div>

  <section class="card" id="outlets"><div class="card-h"><h3><?= icon('store', 'sm') ?> Outlets on the website</h3><small class="muted">Only the outlets you tick are shown. Each keeps its own prices, stock, delivery charge and payment methods.</small></div>
    <form method="post" action="<?= $post('outlets') ?>">
      <?= csrf_field() ?>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Show</th><th>Outlet</th><th>Area</th><th class="num">Online products</th><th class="num">Order</th></tr></thead><tbody>
        <?php foreach ($outlets as $o): ?>
          <tr>
            <td data-l="Show"><label class="check"><input type="checkbox" name="on[]" value="<?= (int) $o['id'] ?>" <?= checked($o['on']) ?> aria-label="Show <?= esc($o['name'], 'attr') ?>"></label></td>
            <td data-l="Outlet"><b><?= esc($o['name']) ?></b><br><small class="muted"><?= esc($o['type_name']) ?></small></td>
            <td data-l="Area"><?= esc(trim(($o['area'] ?? '') . ', ' . ($o['city'] ?? ''), ', ') ?: '—') ?></td>
            <td data-l="Online products" class="num"><?= (int) $o['products'] ?></td>
            <td data-l="Order" class="num"><input class="input msite-sort" type="number" min="0" max="999" name="sort[<?= (int) $o['id'] ?>]" value="<?= (int) $o['sort'] ?>" aria-label="Position of <?= esc($o['name'], 'attr') ?>"></td>
          </tr>
        <?php endforeach ?>
      </tbody></table></div>
      <div class="card-b"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save outlets</button></div>
    </form>
  </section>
</div>
