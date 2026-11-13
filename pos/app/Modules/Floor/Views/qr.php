<?php
/**
 * Printable table QR cards (Table QR ordering). Each QR opens the outlet's storefront at
 * /order/table-1/<secret code>: the menu, ordering for that table. Drawn in the browser (vendor/qrcode-generator).
 *
 * @var array<string, string>                                   $labels
 * @var list<array{name: string, area: ?string, url: ?string}> $cards
 * @var ?array{base: string, query: string}                     $siteBase
 */
helper('ui');
$b = service('posSession')->business();
$v = static fn (string $f): string => is_file(FCPATH . $f) ? (string) filemtime(FCPATH . $f) : '1';
?>
<div class="page">
  <div class="page-h">
    <div><h2>Table QR codes</h2><p class="page-sub">Print, cut and place one on each <?= esc(strtolower((string) ($labels['seat'] ?? 'table'))) ?>. Guests scan it to see the menu and order — the order arrives in Orders and the kitchen with the table name.</p></div>
    <div class="spacer"></div>
    <a class="btn" href="<?= esc(site_url('floor/manage'), 'attr') ?>"><?= icon('back', 'sm') ?>Manage</a>
    <?php if ($siteBase !== null && $cards): ?><button class="btn primary" type="button" id="tq-print"><?= icon('print', 'sm') ?>Print <?= count($cards) ?> card<?= count($cards) === 1 ? '' : 's' ?></button><?php endif ?>
  </div>
  <?php if ($siteBase === null): ?>
    <div class="sbanner warn"><?= icon('alert', 'sm') ?><span>Publish your storefront first (Website) — the QR codes open your online menu.</span></div>
  <?php else: ?>
    <div class="tq-cards" id="tq-cards">
      <?php foreach ($cards as $c): ?>
        <div class="tq-card">
          <div class="tq-shop"><?= esc($b['name'] ?? '') ?></div>
          <div class="tq-qr" data-url="<?= esc((string) $c['url'], 'attr') ?>"></div>
          <div class="tq-name"><?= esc($c['name']) ?><?= ! empty($c['area']) ? ' <small>· ' . esc((string) $c['area']) . '</small>' : '' ?></div>
          <div class="tq-hint">Scan to see the menu &amp; order<br><span lang="bn">মেনু দেখতে ও অর্ডার করতে স্ক্যান করুন</span></div>
        </div>
      <?php endforeach ?>
    </div>
    <p class="muted" style="font-size:12.5px">Each code is secret to its table. If a card goes missing, use <b>New QR</b> on the Manage page — the old code stops working at once.</p>
  <?php endif ?>
</div>
<style>
  .tq-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; }
  .tq-card { display: grid; justify-items: center; gap: 8px; padding: 18px 14px; border: 1.5px dashed #94a3b8; border-radius: 16px; background: #fff; color: #111; text-align: center; break-inside: avoid; }
  .tq-shop { font-weight: 700; font-size: 15px; }
  .tq-qr { width: 170px; height: 170px; }
  .tq-qr svg { width: 100%; height: 100%; display: block; }
  .tq-name { font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
  .tq-name small { font-size: 13px; font-weight: 600; color: #475569; }
  .tq-hint { font-size: 12.5px; color: #475569; line-height: 1.4; }
</style>
<script src="<?= esc(base_url('assets/pos/vendor/qrcode-generator-1.4.4.js') . '?v=' . $v('assets/pos/vendor/qrcode-generator-1.4.4.js'), 'attr') ?>"></script>
<script>
  (function () {
    document.querySelectorAll('.tq-qr').forEach(function (el) {
      var q = window.qrcode(0, 'M');
      q.addData(el.getAttribute('data-url'), 'Byte');
      q.make();
      var n = q.getModuleCount(), d = '';
      for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + c + ' ' + r + 'h1v1h-1z';
      el.innerHTML = '<svg viewBox="-2 -2 ' + (n + 4) + ' ' + (n + 4) + '" role="img" aria-label="QR code"><path fill="#111" d="' + d + '"/></svg>';
    });
    var btn = document.getElementById('tq-print');
    if (btn) btn.addEventListener('click', function () {
      var css = document.querySelector('style').outerHTML.replace('repeat(auto-fill, minmax(230px, 1fr))', 'repeat(2, 1fr)');
      var html = css + '<div class="tq-cards">' + document.getElementById('tq-cards').innerHTML + '</div>';
      if (window.OmniPrint) window.OmniPrint.printHtml(html, { kind: 'label', pageCss: '@page{size:A4;margin:12mm}' }); else window.print();
    });
  })();
</script>
