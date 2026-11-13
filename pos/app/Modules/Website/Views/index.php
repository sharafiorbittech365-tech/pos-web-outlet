<?php
/**
 * Website management — the design build's websiteHTML (overview / storefront / domains & SSL /
 * API keys / webhooks / sync & cache).
 *
 * @var string               $tab
 * @var array<string, mixed> $site
 * @var string               $host
 * @var string               $preview
 * @var string               $siteHost
 * @var string               $apiUrl
 * @var string               $developersUrl
 * @var bool                 $devapi
 * @var bool                 $marketplace
 * @var array                $cur
 * @var array|null           $newKey
 * @var string|null          $newSecret
 */
use App\Modules\Website\Services\WebsiteService;

helper('ui');
$b = service('posSession')->business();
$live = $site['mode'] !== 'off';
$tabs = [['overview', 'Overview', 'grid'], ['storefront', 'Storefront', 'store'], ['pages', 'Pages', 'file-text'], ['domains', 'Domains & SSL', 'globe'], ['selfhosted', 'Self-hosted', 'server'], ['api', 'API keys', 'key'], ['webhooks', 'Webhooks', 'send'], ['sync', 'Sync & cache', 'refresh']];
$post = static fn (string $path): string => esc(site_url('website/' . $path), 'attr');
$br = $site['brand'];
$ck = $site['checkout'];
$sec = $site['sections'];
?>
<div class="page">
  <div class="page-h">
    <div><h2>Website</h2><p class="page-sub">Sell online without a separate website admin — prices, stock and orders stay in sync with this POS.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc($preview, 'attr') ?>" target="_blank" rel="noopener"><?= icon('external', 'sm') ?>Preview storefront</a>
  </div>
  <div class="seg"><?php foreach ($tabs as [$k, $l, $ic]): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= esc(site_url('website') . ($k === 'overview' ? '' : '?tab=' . $k), 'attr') ?>"><?= icon($ic, 'sm') ?><?= esc($l) ?></a><?php endforeach ?><?php if (service('posSession')->moduleOn('multisite') && ! empty(service('posSession')->user()['is_owner'])): ?><a href="<?= esc(site_url('website/multisite'), 'attr') ?>"><?= icon('store', 'sm') ?>Multi-outlet</a><?php endif ?></div>

<?php if ($tab === 'overview'): $st = $stats; ?>
  <div class="wmodes">
    <?php foreach ([
        ['storefront', 'store', 'Ready-made storefront', 'A fast shop that runs on its own — no website admin to manage.', ['Your logo, colours & products automatically', 'Free address ' . $site['slug'] . '.' . $siteHost, 'Connect your own domain with free SSL', 'Orders land in POS with stock updated'], true],
        ['api', 'code', 'API-only (headless)', 'Build your own site in React, Next.js, Vue, WordPress or a mobile app.', ['REST API locked to your business', 'Publishable & secret keys with scopes', 'Webhooks for orders & stock changes', 'Full API documentation'], $devapi],
        ['off', 'ban', 'No website', 'Sell only in store. Switch on any time — nothing is lost.', ['POS, invoices & inventory keep working', 'No public pages', 'API keys stop working'], true],
    ] as [$id, $ic, $title, $desc, $points, $allowed]): ?>
      <form method="post" action="<?= $post('mode') ?>" class="wmode <?= $site['mode'] === $id ? 'on' : '' ?>">
        <?= csrf_field() ?><input type="hidden" name="mode" value="<?= esc($id, 'attr') ?>">
        <span class="wm-ic"><?= icon($ic) ?></span><b><?= esc($title) ?></b><span class="muted"><?= esc($desc) ?></span>
        <ul><?php foreach ($points as $pt): ?><li><?= icon('check', 'sm') ?><?= esc($pt) ?></li><?php endforeach ?></ul>
        <?php if ($site['mode'] === $id): ?><span class="wm-sel"><?= icon('check', 'sm') ?>Selected</span>
        <?php elseif (! $allowed): ?><span class="wm-sel muted">Needs the Developer API module</span>
        <?php else: ?><button class="wm-sel btn sm" type="submit">Use this</button><?php endif ?>
      </form>
    <?php endforeach ?>
  </div>
  <?php if ($live): ?>
    <section class="card wlive"><div class="card-b">
      <div class="wl-url"><span class="wl-dot"></span><div class="grow"><small class="muted"><?= $site['mode'] === 'storefront' ? 'Your store is live at' : 'API base URL' ?></small><b><?= esc($site['mode'] === 'storefront' ? 'https://' . $host : $apiUrl) ?></b></div>
        <?php if ($site['mode'] === 'storefront'): ?><a class="btn primary" href="<?= esc($preview, 'attr') ?>" target="_blank" rel="noopener"><?= icon('expand', 'sm') ?>Open storefront</a>
        <?php else: ?><a class="btn primary" href="<?= esc($developersUrl, 'attr') ?>" target="_blank" rel="noopener"><?= icon('book', 'sm') ?>API documentation</a><?php endif ?>
        <button class="btn" type="button" data-copy="<?= esc($site['mode'] === 'storefront' ? 'https://' . $host : $apiUrl, 'attr') ?>"><?= icon('copy', 'sm') ?>Copy</button></div>
      <div class="wl-chips">
        <span class="wchip ok"><?= icon('shield', 'sm') ?>SSL / HTTPS</span>
        <span class="wchip ok"><?= icon('key', 'sm') ?><?= (int) $st['keys'] ?> API key(s)</span>
        <span class="wchip <?= $st['hooks'] ? 'ok' : '' ?>"><?= icon('send', 'sm') ?><?= (int) $st['hooks'] ?> webhook(s)</span>
        <span class="wchip"><?= icon('zap', 'sm') ?>Cache <?= (int) $site['cache_pages_sec'] ?>s</span>
        <?php if ($site['marketplace_listed']): ?><span class="wchip ok"><?= icon('compass', 'sm') ?>On the Marketplace</span><?php endif ?>
      </div></div></section>
  <?php endif ?>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'orders', 'label' => 'Online orders today', 'value' => (string) $st['orders_today'], 'sub' => money($st['sales_today'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'chart', 'label' => 'Online sales · 7 days', 'value' => money($st['sales_7d'], $cur)]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'globe', 'label' => 'Allowed origins', 'value' => (string) $st['origins']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'code', 'label' => 'API requests (30 days)', 'value' => number_format($st['api_30d'])]) ?>
  </div>
  <section class="card"><div class="card-h"><h3>Recent online orders</h3><a class="link" href="<?= esc(site_url('orders'), 'attr') ?>">All orders</a></div><?= $orders ?></section>

<?php elseif ($tab === 'storefront'): ?>
  <?php if ($site['mode'] !== 'storefront'): ?>
    <form class="sbanner info" method="post" action="<?= $post('mode') ?>"><?= csrf_field() ?><input type="hidden" name="mode" value="storefront"><?= icon('info', 'sm') ?><span class="grow">The ready-made storefront is not switched on. Your settings are kept.</span><button class="btn sm primary" type="submit">Use the storefront</button></form>
  <?php endif ?>
  <form method="post" action="<?= $post('storefront') ?>" class="wsf">
    <?= csrf_field() ?>
    <div class="wsf-form">
      <section class="card"><div class="card-h"><h3>Theme</h3></div><div class="card-b"><div class="wthemes">
        <?php foreach (WebsiteService::THEMES as $id => [$l, $d]): ?>
          <label class="wtheme t-<?= esc($id, 'attr') ?> <?= $site['theme'] === $id ? 'on' : '' ?>" data-chip-check><input type="radio" name="theme" value="<?= esc($id, 'attr') ?>" hidden <?= checked($site['theme'] === $id) ?>><span class="wt-art"><i></i><em></em><em></em><em></em></span><b><?= esc($l) ?></b><small><?= esc($d) ?></small></label>
        <?php endforeach ?>
      </div></div></section>
      <section class="card"><div class="card-h"><h3><?= icon('palette', 'sm') ?> Colours</h3><small class="muted">Pick a palette or set your own</small></div><div class="card-b">
        <div class="wpals">
          <label class="wpal <?= $br['palette'] === '' ? 'on' : '' ?>" data-chip-check><input type="radio" name="palette" value="" hidden <?= checked($br['palette'] === '') ?>><span class="wpal-sw"><i style="background:<?= css_color($br['color'] ?: '#4F46E5') ?>"></i><i style="background:<?= css_color($br['accent'] ?: '#F59E0B') ?>"></i><i style="background:<?= css_color($br['dark'] ?: '#0F1420') ?>"></i></span><b>My colours</b></label>
          <?php foreach (WebsiteService::PALETTES as $id => [$l, $c1, $c2, $c3]): ?>
            <label class="wpal <?= $br['palette'] === $id ? 'on' : '' ?>" title="<?= esc($l, 'attr') ?>" data-chip-check><input type="radio" name="palette" value="<?= esc($id, 'attr') ?>" hidden <?= checked($br['palette'] === $id) ?>><span class="wpal-sw"><i style="background:<?= css_color($c1) ?>"></i><i style="background:<?= css_color($c2) ?>"></i><i style="background:<?= css_color($c3) ?>"></i></span><b><?= esc($l) ?></b></label>
          <?php endforeach ?>
        </div>
        <div class="form-grid wcolors" data-show-for="" data-show-name="palette">
          <label class="field">Brand<input class="input" type="color" name="brand_color" value="<?= esc($br['color'] ?: (string) ($b['settings']['theme']['primary'] ?? '#4F46E5'), 'attr') ?>"></label>
          <label class="field">Accent<input class="input" type="color" name="brand_accent" value="<?= esc($br['accent'] ?: '#F59E0B', 'attr') ?>"></label>
          <label class="field">Header &amp; footer<input class="input" type="color" name="brand_dark" value="<?= esc($br['dark'] ?: '#0F1420', 'attr') ?>"></label>
        </div>
      </div></section>
      <section class="card"><div class="card-h"><h3><?= icon('compass', 'sm') ?> Marketplace</h3></div><div class="card-b">
        <?php if ($marketplace): ?>
          <label class="check"><input type="checkbox" name="marketplace_listed" value="1" <?= checked($site['marketplace_listed']) ?> <?= $live ? '' : 'disabled' ?>>List <?= esc($b['name'] ?? '') ?> on the <?= esc(brand_name()) ?> Marketplace (shoppers nearby can find and order)</label>
          <div class="form-grid"><label class="field">Show to shoppers within (km, 0 = delivery radius)<input class="input" type="number" min="0" max="200" step="0.5" name="marketplace_radius_km" value="<?= esc((string) $site['marketplace_radius_km'], 'attr') ?>"></label></div>
          <?php if (empty($b['lat'])): ?><small class="muted">Set the shop location in <a class="link" href="<?= esc(site_url('settings#location'), 'attr') ?>">Setup › Location</a> so nearby shoppers see you.</small><?php endif ?>
        <?php else: ?>
          <p class="muted" style="margin:0">The Marketplace listing module is off for this account.</p>
        <?php endif ?>
      </div></section>
      <section class="card"><div class="card-h"><h3><?= icon('megaphone', 'sm') ?> Announcement bar</h3></div><div class="card-b"><div class="form-grid">
        <label class="field span2">Logo image (https:// link, optional)<input class="input" type="url" name="logo" maxlength="300" value="<?= esc((string) ($br['logo'] ?? ''), 'attr') ?>" placeholder="https://www.yourshop.com/logo.png"><?= field_error('logo') ?></label>
        <label class="field span2">Text at the very top of every page<input class="input" name="announce" maxlength="90" value="<?= esc($br['announce'], 'attr') ?>" placeholder="e.g. Eid sale — 15% off everything until Friday"></label></div><small class="muted">Leave empty to show delivery and payment info automatically.</small></div></section>
      <section class="card"><div class="card-h"><h3>Home page</h3></div><div class="card-b"><div class="form-grid">
        <label class="field span2">Headline<input class="input" name="hero" maxlength="120" value="<?= esc($br['hero'], 'attr') ?>"></label>
        <label class="field span2">Sub-headline<input class="input" name="sub" maxlength="200" value="<?= esc($br['sub'], 'attr') ?>"></label>
        </div><div class="field">Sections</div><div class="perm-grid">
          <?php foreach (WebsiteService::SECTIONS as $k => $l): ?><label class="check"><input type="checkbox" name="sections[<?= esc($k, 'attr') ?>]" value="1" <?= checked($sec[$k] ?? true) ?>><?= esc($l) ?></label><?php endforeach ?>
        </div></div></section>
      <section class="card"><div class="card-h"><h3>Checkout</h3></div><div class="card-b">
        <div class="perm-grid"><?php foreach (WebsiteService::CHECKOUT as $k => $l): ?><label class="check"><input type="checkbox" name="checkout[<?= esc($k, 'attr') ?>]" value="1" <?= checked(! empty($ck[$k])) ?>><?= esc($l) ?></label><?php endforeach ?></div>
        <?= field_error('checkout') ?>
        <div class="form-grid"><label class="field">Delivery charge (<?= esc($cur['symbol']) ?>)<input class="input" type="number" min="0" step="any" name="delivery" value="<?= esc((string) $ck['delivery'], 'attr') ?>"></label>
          <label class="field">Free delivery above (0 = never)<input class="input" type="number" min="0" step="any" name="free_above" value="<?= esc((string) $ck['freeAbove'], 'attr') ?>"></label></div>
      </div></section>
      <section class="card"><div class="card-h"><h3>Search engines (SEO)</h3></div><div class="card-b"><div class="form-grid">
        <label class="field span2">Page title<input class="input" name="seo_title" maxlength="70" value="<?= esc((string) $site['seo']['title'], 'attr') ?>"></label>
        <label class="field span2">Description<textarea class="input" rows="2" name="seo_description" maxlength="170" placeholder="Shown in Google results"><?= esc((string) $site['seo']['description']) ?></textarea></label></div>
        <div class="seo-prev"><small><?= esc($host) ?></small><b><?= esc($site['seo']['title'] ?: ($b['name'] ?? '')) ?></b><span><?= esc($site['seo']['description'] ?: $br['sub']) ?></span></div></div></section>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save &amp; publish</button><span class="muted">Changes go live in a few seconds (cache is cleared for you).</span></div>
    </div>
    <div class="wsf-prev"><div class="card"><div class="card-h"><h3>Live preview</h3><a class="link" href="<?= esc($preview, 'attr') ?>" target="_blank" rel="noopener">Open <?= icon('external', 'sm') ?></a></div>
      <div class="wbrowser"><div class="wb-bar"><i></i><i></i><i></i><span><?= icon('shield', 'sm') ?>https://<?= esc($host) ?></span></div><div class="wb-frame"><iframe src="<?= esc($preview . '&preview=1', 'attr') ?>" title="Storefront preview" loading="lazy"></iframe></div></div></div></div>
  </form>

  <?php $pp = $site['product_page']; $ppRows = static fn (array $rows, int $n, array $blank): array => array_slice(array_merge($rows, array_fill(0, $n, $blank)), 0, $n); ?>
  <form method="post" action="<?= $post('product-page') ?>" class="wsf-pp" id="product-page">
    <?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Product page</h3><span class="muted">What a customer sees when they open one product</span></div><div class="card-b">
      <div class="perm-grid"><?php foreach (WebsiteService::PAGE_STYLES as $id => [$l, $d]): ?><label class="wtheme <?= $pp['style'] === $id ? 'on' : '' ?>" data-chip-check><input type="radio" name="pp_style" value="<?= esc($id, 'attr') ?>" hidden <?= checked($pp['style'] === $id) ?>><b><?= esc($l) ?></b><small><?= esc($d) ?></small></label><?php endforeach ?></div>
      <small class="muted">The options below apply to the <b>Detailed</b> style. Product code and photos are set on each product (Products › edit › Basic).</small>
    </div></section>
    <section class="card"><div class="card-h"><h3>Buttons &amp; details</h3></div><div class="card-b"><div class="form-grid">
      <label class="field">Order button text<input class="input" name="pp_orderLabel" maxlength="30" value="<?= esc((string) $pp['orderLabel'], 'attr') ?>"></label>
      <label class="field">Cart button text<input class="input" name="pp_cartLabel" maxlength="30" value="<?= esc((string) $pp['cartLabel'], 'attr') ?>"></label>
      <label class="check"><input type="checkbox" name="pp_showCode" value="1" <?= checked($pp['showCode']) ?>>Show the product code</label>
      <label class="check"><input type="checkbox" name="pp_showStock" value="1" <?= checked($pp['showStock']) ?>>Show availability (In stock / Out of stock)</label>
      <label class="check"><input type="checkbox" name="pp_showCategory" value="1" <?= checked($pp['showCategory']) ?>>Show the category</label>
    </div></div></section>
    <section class="card"><div class="card-h"><h3>Call &amp; chat</h3></div><div class="card-b"><div class="form-grid">
      <label class="check span2"><input type="checkbox" name="pp_callBox" value="1" <?= checked($pp['callBox']) ?>>Show the "call us" box</label>
      <label class="field span2">Text above the number<input class="input" name="pp_callText" maxlength="120" value="<?= esc((string) $pp['callText'], 'attr') ?>"></label>
      <label class="field">Phone number <small class="muted">(empty = the shop's phone)</small><input class="input" name="pp_callPhone" maxlength="32" value="<?= esc((string) $pp['callPhone'], 'attr') ?>" placeholder="<?= esc((string) ($b['phone'] ?? '+8801XXXXXXXXX'), 'attr') ?>"></label>
      <label class="field">Messenger link or page name<input class="input" name="pp_messenger" maxlength="300" value="<?= esc((string) $pp['messenger'], 'attr') ?>" placeholder="https://m.me/yourpage"><?= field_error('pp_messenger') ?></label>
      <label class="check span2"><input type="checkbox" name="pp_showWhatsapp" value="1" <?= checked($pp['showWhatsapp']) ?>>Show the WhatsApp button <small class="muted">(uses the WhatsApp number in Setup › Website)</small></label>
    </div></div></section>
    <section class="card"><div class="card-h"><h3>Delivery-charge table</h3></div><div class="card-b">
      <label class="check"><input type="checkbox" name="pp_deliveryTable" value="1" <?= checked($pp['deliveryTable']) ?>>Show the table on the product page</label>
      <div class="form-grid"><label class="field span2">Table title<input class="input" name="pp_deliveryTitle" maxlength="60" value="<?= esc((string) $pp['deliveryTitle'], 'attr') ?>"></label>
      <?php foreach ($ppRows($pp['delivery'], 6, ['area' => '', 'charge' => '']) as $i => $r): ?>
        <label class="field">Area <?= $i + 1 ?><input class="input" name="pp_delivery[<?= $i ?>][area]" maxlength="60" value="<?= esc((string) $r['area'], 'attr') ?>" placeholder="<?= $i === 0 ? 'Inside Dhaka' : ($i === 1 ? 'Outside Dhaka' : '') ?>"></label>
        <label class="field">Charge (<?= esc($cur['symbol']) ?>)<input class="input" type="number" min="0" step="any" name="pp_delivery[<?= $i ?>][charge]" value="<?= esc((string) $r['charge'], 'attr') ?>"></label>
      <?php endforeach ?></div><?= field_error('pp_delivery') ?>
      <small class="muted">Leave an area empty to skip the row. This table is for information; the checkout still uses the delivery charge above.</small>
    </div></section>
    <section class="card"><div class="card-h"><h3>Side notes</h3><span class="muted">Short messages beside the product (advice, complaints, payment, review…)</span></div><div class="card-b">
      <label class="check"><input type="checkbox" name="pp_showNotes" value="1" <?= checked($pp['showNotes']) ?>>Show the notes next to the product</label>
      <?php foreach ($ppRows($pp['notes'], 6, ['icon' => 'check', 'title' => '', 'text' => '']) as $i => $r): ?>
        <div class="form-grid" style="margin-top:10px">
          <label class="field">Icon<select class="select" name="pp_notes[<?= $i ?>][icon]"><?php foreach (WebsiteService::NOTE_ICONS as $k => $l): ?><option value="<?= esc($k, 'attr') ?>" <?= selected($r['icon'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
          <label class="field">Title<input class="input" name="pp_notes[<?= $i ?>][title]" maxlength="60" value="<?= esc((string) $r['title'], 'attr') ?>"></label>
          <label class="field span2">Message<textarea class="input" rows="2" name="pp_notes[<?= $i ?>][text]" maxlength="300"><?= esc((string) $r['text']) ?></textarea></label>
        </div>
      <?php endforeach ?>
    </div></section>
    <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save product page</button><span class="muted">Live in a few seconds.</span></div>
  </form>

<?php elseif ($tab === 'pages'): $editing = $editPage ?? null; $isNewPage = ! empty($newPage); ?>
  <div class="wpg">
    <section class="card"><div class="card-h"><h3>Pages</h3><a class="btn sm primary" href="<?= esc(site_url('website') . '?tab=pages&new=1', 'attr') ?>"><?= icon('plus', 'sm') ?>New page</a></div>
      <div class="card-b" style="display:grid;gap:8px">
        <?php foreach ($pages as $pg): ?>
          <a class="wpage" href="<?= esc(site_url('website') . '?tab=pages&edit=' . (int) $pg['id'], 'attr') ?>"<?= $editing && (int) $editing["id"] === (int) $pg["id"] ? " style=\"border-color:var(--primary);background:var(--primary-soft)\"" : "" ?>>
            <b><?= esc((string) $pg['title']) ?></b>
            <div style="font-size:12px;color:var(--muted,#6b7280)">/pages/<?= esc((string) $pg['slug']) ?> · <?= (int) $pg['is_published'] ? 'Published' : 'Hidden' ?><?= (int) $pg['show_in_footer'] ? ' · Footer' : '' ?><?= $pg['kind'] !== 'custom' ? ' · Standard' : '' ?></div>
          </a>
        <?php endforeach ?>
      </div></section>
    <?php if ($editing || $isNewPage): $pv = static fn (string $k, mixed $d = '') => old($k) ?? ($editing[$k] ?? $d); ?>
      <section class="card"><div class="card-h"><h3><?= $editing ? 'Edit page' : 'New page' ?></h3>
        <?php if ($editing && (int) $editing['is_published']): ?><a class="link" href="<?= esc(rtrim($preview, '/') . '', 'attr') ?>" target="_blank" rel="noopener">Open website <?= icon('external', 'sm') ?></a><?php endif ?></div>
        <div class="card-b">
          <form method="post" action="<?= $post($editing ? 'pages/' . (int) $editing['id'] : 'pages') ?>"><?= csrf_field() ?>
            <div class="form-grid">
              <label class="field span2">Title *<input class="input" name="title" required maxlength="160" value="<?= esc((string) $pv('title'), 'attr') ?>"><?= field_error('title') ?></label>
              <label class="field">Web address <small class="muted">— /pages/…</small><input class="input" name="slug" maxlength="70" value="<?= esc((string) $pv('slug'), 'attr') ?>" placeholder="made from the title"><?= field_error('slug') ?></label>
              <label class="field">Order<input class="input" type="number" min="0" max="999" name="sort" value="<?= (int) $pv('sort_order', 10) ?>"></label>
              <div class="field span2"><span>Content</span>
                <details class="pgb-tpl" <?= $editing ? '' : 'open' ?>><summary><?= icon('grid', 'sm') ?>Start from a design <small class="muted">— pick a ready-made page and edit it</small></summary>
                  <div class="pgb-tgrid"><?php foreach ($pageTemplates as $t): ?>
                    <button class="pgb-t" type="button" data-tpl="<?= esc($t['key'], 'attr') ?>"><span class="pgb-ti"><?= icon($t['icon']) ?></span><b><?= esc($t['name']) ?></b><small><?= esc($t['desc']) ?></small></button>
                  <?php endforeach ?></div></details>
                <div class="pgb" data-pgb>
                  <div class="pgb-bar" role="toolbar" aria-label="Formatting">
                    <select class="select pgb-sel" data-fmt aria-label="Text style"><option value="p">Paragraph</option><option value="h2">Heading</option><option value="h3">Sub-heading</option><option value="h4">Small heading</option></select>
                    <button type="button" data-cmd="bold" title="Bold"><b>B</b></button><button type="button" data-cmd="italic" title="Italic"><i>I</i></button><button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
                    <span class="pgb-sep"></span>
                    <button type="button" data-cmd="insertUnorderedList" title="Bullet list">&bull; List</button><button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button><button type="button" data-cmd="formatBlock:blockquote" title="Quote">&ldquo; Quote</button>
                    <span class="pgb-sep"></span>
                    <button type="button" data-cmd="justifyLeft" title="Align left">&#8676;</button><button type="button" data-cmd="justifyCenter" title="Centre">&#8596;</button>
                    <button type="button" data-link title="Link"><?= icon('link', 'sm') ?></button><button type="button" data-cmd="unlink" title="Remove link">&times;&#8203;<?= icon('link', 'sm') ?></button>
                    <button type="button" data-cmd="insertHorizontalRule" title="Divider">&mdash;</button><button type="button" data-cmd="removeFormat" title="Clear formatting">Tx</button>
                    <span class="pgb-sep"></span>
                    <select class="select pgb-sel" data-block aria-label="Insert a block"><option value="">+ Insert block…</option>
                      <option value="note">Highlight box</option><option value="cols2">2 columns</option><option value="cols3">3 cards</option><option value="steps">Numbered steps</option><option value="checks">Tick list</option>
                      <option value="table">Table</option><option value="faq">Question &amp; answer</option><option value="cta">Button banner</option><option value="hero">Hero banner</option><option value="stats">Numbers</option></select>
                    <span class="pgb-sep"></span>
                    <button type="button" data-cmd="undo" title="Undo">&#8630;</button><button type="button" data-cmd="redo" title="Redo">&#8631;</button>
                    <button type="button" data-src title="Show the HTML">&lt;/&gt;</button>
                  </div>
                  <div class="pgb-ed pg-doc" contenteditable="true" data-pg-ed spellcheck="true" aria-label="Page content"><?= $pageHtml ?></div>
                  <textarea class="input pgb-src" name="body" rows="14" maxlength="60000" data-pg-body hidden><?= esc((string) $pageHtml) ?></textarea>
                </div>
                <?= field_error('body') ?>
                <small class="muted">Use the toolbar like a document editor, or start from a design above. Scripts and unsafe code are removed automatically.</small></div>
              <label class="field span2">Search description (optional)<input class="input" name="seo_description" maxlength="300" value="<?= esc((string) $pv('seo_description'), 'attr') ?>"></label>
              <label class="check"><input type="checkbox" name="published" value="1" <?= checked((int) $pv('is_published', 1) === 1) ?>>Published</label>
              <label class="check"><input type="checkbox" name="footer" value="1" <?= checked((int) $pv('show_in_footer', 1) === 1) ?>>Show a link in the website footer</label>
            </div>
            <div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save page</button></div>
          </form>
          <?php if ($editing && $editing['kind'] === 'custom'): ?>
            <form class="inline" method="post" action="<?= $post('pages/' . (int) $editing['id'] . '/delete') ?>" data-confirm="Delete this page?" style="margin-top:10px"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Delete page</button></form>
          <?php endif ?>
        </div></section>
      <link rel="stylesheet" href="<?= esc(base_url('assets/pos/site-pages.css') . '?v=' . (is_file(FCPATH . 'assets/pos/site-pages.css') ? filemtime(FCPATH . 'assets/pos/site-pages.css') : 0), 'attr') ?>">
      <script type="application/json" id="pg-tpls"><?= json_encode(array_column($pageTemplates, 'html', 'key'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
      <script src="<?= esc(base_url('assets/pos/page-builder.js') . '?v=' . (is_file(FCPATH . 'assets/pos/page-builder.js') ? filemtime(FCPATH . 'assets/pos/page-builder.js') : 0), 'attr') ?>" defer></script>
    <?php else: ?>
      <section class="card"><div class="card-b"><div class="empty"><span class="empty-ic"><?= icon('file-text') ?></span><b>Pick a page to edit</b><span>Delivery &amp; returns, Privacy policy, Terms &amp; conditions and About us show in your website's footer.</span></div></div></section>
    <?php endif ?>
  </div>

<?php elseif ($tab === 'domains'): ?>
  <section class="card"><div class="card-h"><h3>Free address</h3><span class="tag ok"><?= icon('shield', 'sm') ?>SSL active</span></div><div class="card-b">
    <form class="row-add" method="post" action="<?= $post('slug') ?>"><?= csrf_field() ?><div class="slug-in"><span>https://</span><input class="input" name="slug" value="<?= old_or('slug', $site['slug']) ?>" maxlength="48" aria-label="Address"><span>.<?= esc($siteHost) ?></span></div><button class="btn" type="submit">Save address</button></form>
    <?= field_error('slug') ?><small class="muted">Always available, even after you connect your own domain.</small></div></section>
  <section class="card"><div class="card-h"><h3>Custom domains</h3><small class="muted">Use your own domain, e.g. www.yourshop.com — SSL is free and renews automatically</small></div><div class="card-b">
    <div class="dom-list">
      <?php foreach ($domains as $d): $active = $d['status'] === 'active'; $parts = explode('.', $d['host']);
          $records = count($parts) > 2 && ! str_starts_with($d['host'], 'www.') ? [['CNAME', $parts[0], 'cname.' . $siteHost]] : (str_starts_with($d['host'], 'www.') ? [['CNAME', 'www', 'cname.' . $siteHost]] : [['A', '@', '76.76.21.21'], ['CNAME', 'www', 'cname.' . $siteHost]]);
          $records[] = ['TXT', '_omnipos', $d['verify_token']]; ?>
        <article class="dom <?= esc($d['status'], 'attr') ?>">
          <div class="dom-h"><?= icon('globe') ?><div class="grow"><b><?= esc($d['host']) ?></b><?= $d['is_primary'] ? ' ' . tag_html('Primary', 'primary') : '' ?><small class="muted">Added <?= esc(date_str($d['created_at'])) ?><?= $d['verified_at'] ? ' · verified ' . esc(date_str($d['verified_at'])) : '' ?></small></div>
            <?= (int) $d['admin_blocked'] ? tag_html('Blocked by ' . \OmniPOS\Ui\Brand::name(), 'danger') : ((int) $d['is_enabled'] ? tag_html($active ? 'Connected' : 'Waiting for DNS', $active ? 'ok' : 'warn') : tag_html('Disabled')) ?>
            <?= tag_html($d['hosting'] === 'external' ? 'Own server' : 'Hosted by ' . \OmniPOS\Ui\Brand::name(), 'primary') ?>
            <span class="tag <?= $d['ssl_status'] === 'active' ? 'ok' : '' ?>"><?= icon('shield', 'sm') ?><?= $d['ssl_status'] === 'active' ? 'SSL · until ' . esc(date_str($d['ssl_expires_at'])) : 'No SSL yet' ?></span></div>
          <div class="dsteps"><?php foreach (['DNS records', 'Verified', 'SSL issued', 'Live'] as $i => $l): $stp = $active ? 4 : 1; ?><span class="<?= $i < $stp ? 'done' : ($i === $stp ? 'cur' : '') ?>"><?= $i < $stp ? icon('check', 'sm') : '<b>' . ($i + 1) . '</b>' ?><?= esc($l) ?></span><?php endforeach ?></div>
          <?php if (! $active): ?>
            <p class="muted" style="margin:0">Add these records at your domain provider, then press <b>Check DNS</b>. It can take a few minutes to a few hours.</p>
            <table class="tbl dns"><thead><tr><th>Type</th><th>Name / host</th><th>Value</th><th></th></tr></thead><tbody>
              <?php foreach ($records as [$t, $n, $val]): ?><tr><td><span class="tag"><?= esc($t) ?></span></td><td><code><?= esc($n) ?></code></td><td><code><?= esc($val) ?></code></td><td class="num"><button class="icon-btn sm" type="button" data-copy="<?= esc($val, 'attr') ?>" title="Copy"><?= icon('copy', 'sm') ?></button></td></tr><?php endforeach ?>
            </tbody></table>
          <?php endif ?>
          <div class="row-add">
            <?php if (! $active): ?><form class="inline" method="post" action="<?= $post('domains/' . $d['id'] . '/verify') ?>"><?= csrf_field() ?><button class="btn sm primary" type="submit"><?= icon('refresh', 'sm') ?>Check DNS</button></form><?php endif ?>
            <?php if ($active && ! $d['is_primary']): ?><form class="inline" method="post" action="<?= $post('domains/' . $d['id'] . '/primary') ?>"><?= csrf_field() ?><button class="btn sm" type="submit">Make primary</button></form><?php endif ?>
            <form class="inline" method="post" action="<?= $post('domains/' . $d['id'] . '/hosting') ?>"><?= csrf_field() ?><select class="select sm" name="hosting" onchange="this.form.submit()" aria-label="Hosting" style="width:auto"><option value="platform" <?= selected($d['hosting'], 'platform') ?>>Hosted by <?= esc(brand_name()) ?></option><option value="external" <?= selected($d['hosting'], 'external') ?>>My own server</option></select><noscript><button class="btn sm" type="submit">Save</button></noscript></form>
            <?php if (! (int) $d['admin_blocked']): ?><form class="inline" method="post" action="<?= $post('domains/' . $d['id'] . '/toggle') ?>"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= (int) $d['is_enabled'] ? '0' : '1' ?>"><button class="btn sm" type="submit"><?= (int) $d['is_enabled'] ? 'Disable' : 'Enable' ?></button></form><?php else: ?><small class="muted"><?= esc((string) $d['blocked_reason']) ?></small><?php endif ?>
            <form class="inline" method="post" action="<?= $post('domains/' . $d['id'] . '/delete') ?>" data-confirm="Remove <?= esc($d['host'], 'attr') ?>?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Remove</button></form>
          </div>
        </article>
      <?php endforeach ?>
      <?php if (! $domains): ?><div class="muted">No custom domain yet.</div><?php endif ?>
    </div>
    <form class="row-add dom-add" method="post" action="<?= $post('domains') ?>"><?= csrf_field() ?><input class="input" name="host" placeholder="yourshop.com or shop.yourcompany.com" required maxlength="190"><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add domain</button></form>
    <?= field_error('host') ?>
  </div></section>

<?php elseif ($tab === 'selfhosted'): $live = array_values(array_filter($domains, static fn (array $d): bool => $d['status'] === 'active' && (int) $d['is_enabled'] === 1 && (int) $d['admin_blocked'] === 0)); ?>
  <section class="card"><div class="card-h"><h3><?= icon('server', 'sm') ?> Self-hosted storefront</h3><small class="muted">Run the <?= esc(brand_name()) ?> storefront on your own hosting and domain</small></div><div class="card-b">
    <p class="muted" style="margin:0">Your website server talks to <?= esc(brand_name()) ?> with a <b>Client ID</b> and a <b>Client secret</b>. Your logo, colours, theme, products and settings load automatically. The secret stays in your server's <code>.env</code> file — it is never sent to visitors' browsers. The site only works on your verified, enabled domains.</p>
    <ol class="steps-list">
      <li>Add your domain under <a class="link" href="<?= esc(site_url('website') . '?tab=domains', 'attr') ?>">Domains</a>, add the DNS records, press <b>Check DNS</b>, and set hosting to <b>My own server</b>.</li>
      <li>Create a client below and copy the values into the storefront's <code>.env</code> (see <code>env.selfhosted.example</code>).</li>
      <li>Upload the storefront, run <code>composer install --no-dev</code>, and point the web root at <code>public/</code>.</li>
    </ol>
    <div class="kv"><div><small>Domains that can serve your store now</small><b><?= $live ? esc(implode(', ', array_column($live, 'host'))) : 'None yet — verify and enable a domain first' ?></b></div>
      <div><small>Storefront published</small><b><?= $site['mode'] === 'storefront' ? tag_html('Yes', 'ok') : tag_html('No — choose "Ready-made storefront" in Overview', 'warn') ?></b></div></div>
  </div></section>

  <?php if (is_array($newClient ?? null)): ?>
    <section class="card new-secret"><div class="card-h"><h3><?= icon('key', 'sm') ?> Copy these now</h3><?= tag_html('Secret shown once', 'warn') ?></div><div class="card-b">
      <p class="muted" style="margin:0">Paste into the <code>.env</code> file on your storefront server. <?= esc(brand_name()) ?> keeps only a fingerprint of the secret — if you lose it, rotate it.</p>
      <?php $envBlock = 'CLIENT_ID = ' . $newClient['client_id'] . "\nCLIENT_SECRET = " . $newClient['client_secret'] . "\nPOS_API_URL = " . rtrim((string) preg_replace('#/v1$#', '', $apiUrl), '/') . '/'; ?>
      <pre class="env-block"><code><?= esc($envBlock) ?></code></pre>
      <div class="row-add"><button class="btn sm primary" type="button" data-copy="<?= esc($envBlock, 'attr') ?>"><?= icon('copy', 'sm') ?>Copy .env lines</button></div>
    </div></section>
  <?php endif ?>

  <section class="card"><div class="card-h"><h3>Clients</h3><button class="btn sm primary" type="button" data-modal-open="dlg-client"><?= icon('plus', 'sm') ?>Create client</button></div>
    <div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th>Client ID</th><th>Secret</th><th>Last used</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($clients as $c): $revoked = $c['revoked_at'] !== null; ?>
        <tr>
          <td data-l="Name"><b><?= esc($c['name']) ?></b><br><small class="muted">Created <?= esc(date_str($c['created_at'])) ?><?= $c['rotated_at'] ? ' · rotated ' . esc(date_str($c['rotated_at'])) : '' ?></small></td>
          <td data-l="Client ID"><code><?= esc($c['client_id']) ?></code> <button class="icon-btn sm" type="button" data-copy="<?= esc($c['client_id'], 'attr') ?>" title="Copy"><?= icon('copy', 'sm') ?></button></td>
          <td data-l="Secret"><code>sfs_…<?= esc($c['secret_last4']) ?></code></td>
          <td data-l="Last used"><?= $c['last_used_at'] ? esc(datetime_str($c['last_used_at'])) . '<br><small class="muted">' . esc((string) $c['last_used_host']) . '</small>' : '<span class="muted">Never</span>' ?></td>
          <td data-l="Status"><?= $revoked ? tag_html('Revoked') : ((int) $c['is_active'] ? tag_html('Active', 'ok') : tag_html('Paused', 'warn')) ?></td>
          <td class="num nw"><?php if (! $revoked): ?>
            <form class="inline" method="post" action="<?= $post('clients/' . $c['id'] . '/rotate') ?>" data-confirm="Issue a new secret? The current one stops working immediately."><?= csrf_field() ?><button class="btn sm" type="submit"><?= icon('refresh', 'sm') ?>Rotate</button></form>
            <form class="inline" method="post" action="<?= $post('clients/' . $c['id'] . '/toggle') ?>"><?= csrf_field() ?><button class="btn sm" type="submit"><?= (int) $c['is_active'] ? 'Pause' : 'Resume' ?></button></form>
            <form class="inline" method="post" action="<?= $post('clients/' . $c['id'] . '/revoke') ?>" data-confirm="Revoke this client? Sites using it stop working."><?= csrf_field() ?><button class="btn sm danger" type="submit">Revoke</button></form>
          <?php endif ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (! $clients): ?><tr class="dt-empty"><td colspan="6"><span class="muted">No clients yet.</span></td></tr><?php endif ?>
    </tbody></table></div>
  </section>
  <dialog class="modal-dlg sm" id="dlg-client"><form method="post" action="<?= $post('clients') ?>"><?= csrf_field() ?>
    <div class="modal-h"><h3>Create storefront client</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x') ?></button></div>
    <div class="modal-b"><label class="field">Name<input class="input" name="client_name" maxlength="80" placeholder="e.g. www.yourshop.com (cPanel)" autofocus></label>
      <small class="muted">You will see the secret once, right after creating it.</small></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Create</button></div>
  </form></dialog>

<?php elseif ($tab === 'api'): ?>

  <?php if (is_array($newKey)): ?>
    <div class="key-once"><b><?= icon('key', 'sm') ?> <?= esc($newKey['name']) ?> — copy this key now. It is shown only once.</b>
      <div class="row-add"><code><?= esc($newKey['key']) ?></code><button class="btn sm" type="button" data-copy="<?= esc($newKey['key'], 'attr') ?>"><?= icon('copy', 'sm') ?>Copy</button></div>
      <small class="muted"><?= esc(brand_name()) ?> stores only a fingerprint (hash) of the key. If you lose it, revoke it and create a new one.<?= $newKey['type'] === 'secret' ? ' Keep secret keys on your server — never in a browser or app.' : '' ?></small></div>
  <?php endif ?>
  <div class="api-top">
    <section class="card"><div class="card-b api-id"><small class="muted">Business ID</small><div class="row-add"><code class="big"><?= (int) $site['business_id'] ?></code><button class="icon-btn sm" type="button" data-copy="<?= (int) $site['business_id'] ?>"><?= icon('copy', 'sm') ?></button></div>
      <p class="muted">Every key below is locked to this business. A request can never read or change another business's data.</p></div></section>
    <section class="card"><div class="card-b api-id"><small class="muted">Base URL</small><div class="row-add"><code class="big"><?= esc($apiUrl) ?></code><button class="icon-btn sm" type="button" data-copy="<?= esc($apiUrl, 'attr') ?>"><?= icon('copy', 'sm') ?></button></div>
      <p class="muted">HTTPS only · JSON · <a class="link" href="<?= esc($developersUrl, 'attr') ?>" target="_blank" rel="noopener">API documentation <?= icon('external', 'sm') ?></a></p></div></section>
  </div>
  <section class="card"><div class="card-h"><h3>API keys</h3><?php if ($devapi): ?><button class="btn sm primary" type="button" data-modal-open="dlg-key"><?= icon('plus', 'sm') ?>Create key</button><?php else: ?><span class="tag warn">Developer API module off</span><?php endif ?></div><?= $keys ?></section>
  <div class="api-grid">
    <section class="card"><div class="card-h"><h3>Allowed origins (CORS)</h3></div><div class="card-b">
      <p class="muted" style="margin:0">Websites allowed to call the API from the browser with a publishable key.</p>
      <div class="origins">
        <?php foreach ($origins as $or): ?>
          <form class="origin" method="post" action="<?= $post('origins/delete') ?>"><?= csrf_field() ?><input type="hidden" name="origin" value="<?= esc($or, 'attr') ?>"><?= icon('globe', 'sm') ?><code><?= esc($or) ?></code><button class="icon-btn sm" type="submit" aria-label="Remove"><?= icon('x', 'sm') ?></button></form>
        <?php endforeach ?>
        <?php if (! $origins): ?><span class="muted">Any origin (not recommended)</span><?php endif ?>
      </div>
      <form class="row-add" method="post" action="<?= $post('origins') ?>"><?= csrf_field() ?><input class="input" name="origin" placeholder="https://www.yourshop.com" required maxlength="190"><button class="btn" type="submit"><?= icon('plus', 'sm') ?>Add</button></form>
      <?= field_error('origin') ?>
    </div></section>
    <section class="card"><div class="card-h"><h3>Quick start</h3></div><div class="card-b">
      <pre class="code"><?= esc("curl " . $apiUrl . "/products \\\n  -H \"Authorization: Bearer pk_live_your_key\"\n\n// JavaScript\nconst res = await fetch('" . $apiUrl . "/products', {\n  headers: { Authorization: 'Bearer pk_live_your_key' },\n});\nconst { data } = await res.json();") ?></pre></div></section>
  </div>
  <?php if ($devapi): ?>
  <dialog class="modal-dlg" id="dlg-key">
    <form method="post" action="<?= $post('keys') ?>">
      <?= csrf_field() ?>
      <div class="modal-h"><h3><?= icon('key', 'sm') ?> Create API key</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
      <div class="modal-b">
        <label class="field">Name<input class="input" name="key_name" required maxlength="80" placeholder="e.g. My Next.js site" autofocus></label>
        <div class="stypes">
          <label class="opt on"><input type="radio" name="key_type" value="publishable" checked><b>Publishable</b><span>Safe in a browser — catalogue, checkout, bookings</span></label>
          <label class="opt"><input type="radio" name="key_type" value="secret"><b>Secret</b><span>Server only — any scope you grant</span></label>
        </div>
        <div class="field">Scopes</div>
        <div class="perm-grid">
          <?php foreach ($scopes as $k => [$l, $pub]): ?>
            <label class="perm" data-chip-check <?= $pub ? '' : 'data-show-for="secret" data-show-name="key_type"' ?>><input type="checkbox" name="scopes[]" value="<?= esc($k, 'attr') ?>" <?= $pub ? 'checked' : '' ?>><span><code><?= esc($k) ?></code> <?= esc($l) ?></span></label>
          <?php endforeach ?>
        </div>
        <small class="muted">Publishable keys only keep browser-safe scopes; the rest are dropped.</small>
      </div>
      <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Create key</button></div>
    </form>
  </dialog>
  <?php endif ?>

<?php elseif ($tab === 'webhooks'): ?>
  <?php if (is_string($newSecret)): ?>
    <div class="key-once"><b><?= icon('key', 'sm') ?> Signing secret for the new endpoint</b><div class="row-add"><code><?= esc($newSecret) ?></code><button class="btn sm" type="button" data-copy="<?= esc($newSecret, 'attr') ?>"><?= icon('copy', 'sm') ?>Copy</button></div>
      <small class="muted">Verify the <code>X-OmniPOS-Signature</code> header (HMAC-SHA256 of the body with this secret).</small></div>
  <?php endif ?>
  <section class="card"><div class="card-h"><h3>Webhook endpoints</h3><?php if ($devapi): ?><button class="btn sm primary" type="button" data-modal-open="dlg-hook"><?= icon('plus', 'sm') ?>Add endpoint</button><?php else: ?><span class="tag warn">Developer API module off</span><?php endif ?></div><div class="card-b">
    <p class="muted" style="margin:0"><?= esc(brand_name()) ?> sends a signed POST to your server when something changes, so your website never has to poll.</p>
    <div class="hooks">
      <?php foreach ($hooks as $h): ?>
        <article class="hook"><div class="hook-h"><?= icon('send') ?><div class="grow"><b><code><?= esc($h['url']) ?></code></b><small class="muted">Signing secret <code><?= esc(substr($h['secret'], 0, 10)) ?>••••</code> · added <?= esc(date_str($h['created_at'])) ?></small></div>
          <form class="inline" method="post" action="<?= $post('webhooks/' . $h['id'] . '/toggle') ?>"><?= csrf_field() ?><button class="btn sm" type="submit"><?= $h['is_active'] ? icon('pause', 'sm') . 'Pause' : icon('play', 'sm') . 'Resume' ?></button></form></div>
          <div class="scopes"><?php foreach ($h['events'] as $e): ?><span><?= esc($e) ?></span><?php endforeach ?><?= $h['is_active'] ? '' : ' ' . tag_html('Paused', 'warn') ?></div>
          <div class="row-add">
            <form class="inline" method="post" action="<?= $post('webhooks/' . $h['id'] . '/test') ?>"><?= csrf_field() ?><button class="btn sm" type="submit"><?= icon('play', 'sm') ?>Send test event</button></form>
            <form class="inline" method="post" action="<?= $post('webhooks/' . $h['id'] . '/delete') ?>" data-confirm="Remove this endpoint?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Remove</button></form>
          </div></article>
      <?php endforeach ?>
      <?php if (! $hooks): ?><div class="muted">No endpoints yet.</div><?php endif ?>
    </div></div></section>
  <section class="card"><div class="card-h"><h3>Recent deliveries</h3></div><?= $deliveries ?></section>
  <?php if ($devapi): ?>
  <dialog class="modal-dlg" id="dlg-hook">
    <form method="post" action="<?= $post('webhooks') ?>">
      <?= csrf_field() ?>
      <div class="modal-h"><h3><?= icon('send', 'sm') ?> Add webhook endpoint</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
      <div class="modal-b">
        <label class="field">Endpoint URL<input class="input" type="url" name="url" required maxlength="500" placeholder="https://yourshop.com/api/omnipos/webhook" autofocus></label>
        <div class="field">Events</div>
        <div class="perm-grid"><?php foreach ($events as $e): ?><label class="perm on" data-chip-check><input type="checkbox" name="events[]" value="<?= esc($e, 'attr') ?>" <?= in_array($e, ['order.created', 'order.paid'], true) ? 'checked' : '' ?>><span><code><?= esc($e) ?></code></span></label><?php endforeach ?></div>
      </div>
      <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Add endpoint</button></div>
    </form>
  </dialog>
  <?php endif ?>

<?php else: ?>
  <section class="card"><div class="card-h"><h3>What stays in sync</h3><span class="tag ok"><?= icon('refresh', 'sm') ?>Real-time</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Data</th><th>Direction</th><th>Status</th></tr></thead><tbody>
    <?php foreach ([['tag', 'Products & prices', 'POS → website'], ['box', 'Stock levels', 'Both ways (every sale)'], ['orders', 'Orders', 'Website → POS'], ['receipt', 'Invoices', 'POS → website / API'], ['users', 'Customers', 'Website → POS']] as [$ic, $w, $how]): ?>
      <tr><td><?= icon($ic, 'sm') ?> <b><?= esc($w) ?></b></td><td><?= esc($how) ?></td><td><?= tag_html('In sync', 'ok') ?></td></tr>
    <?php endforeach ?>
  </tbody></table></div></section>
  <section class="card"><div class="card-h"><h3>Cache</h3></div><div class="card-b">
    <form method="post" action="<?= $post('cache') ?>"><?= csrf_field() ?>
      <div class="form-grid">
        <label class="field">Storefront pages (CDN)<select class="select" name="cache_pages_sec"><?php foreach ([0 => 'No cache', 60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 3600 => '1 hour'] as $v => $l): ?><option value="<?= $v ?>" <?= selected($site['cache_pages_sec'], $v) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
        <label class="field">API responses<select class="select" name="cache_api_sec"><?php foreach ([0 => 'No cache', 30 => '30 seconds', 60 => '1 minute', 300 => '5 minutes'] as $v => $l): ?><option value="<?= $v ?>" <?= selected($site['cache_api_sec'], $v) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
      </div>
      <p class="muted" style="margin:0">Stock and prices are never served stale: a change in POS clears the cached pages for that product.</p>
      <div class="bo-actions"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save</button></div>
    </form>
  </div></section>
<?php endif ?>
</div>
