<?php
/**
 * Print page for documents (receipt, A4 invoice, challan, kitchen ticket, statement).
 * Opens the browser print dialog by itself when ?auto=1.
 *
 * @var string $title
 * @var string $content  document markup (already escaped)
 * @var string $kind     receipt | a4 | a5 (an A5 page uses the A4 layout, scaled)
 * @var int    $width    58 | 80 (receipts)
 * @var string $primary
 */
helper('ui');
$kind ??= 'a4';
$width ??= 80;
$primary ??= '#4F46E5';
$auto = (string) service('request')->getGet('auto') === '1';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= esc($title) ?></title>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <style>
    :root{--primary:<?= css_color($primary) ?>}
    body{background:var(--surface-3);margin:0}
    .print-bar{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:12px;position:sticky;top:0;z-index:5;background:var(--surface-3);padding-top:calc(12px + env(safe-area-inset-top))}
    .print-bar .btn{min-height:44px;padding:0 18px;font-size:15px}
    .print-doc{padding:16px;display:flex;justify-content:center}
    /* A4 / A5 on a phone or tab: keep the paper's own width and scale the page down to fit (print.js-free, see script below) */
    .print-doc .a4{width:820px;max-width:none;flex:none;zoom:var(--fit,1)}
    .print-doc .receipt{width:100%}
    @page{<?= $kind === 'receipt' ? 'size:' . (int) $width . 'mm auto;margin:3mm' : ($kind === 'a5' ? 'size:A5;margin:8mm' : 'size:A4;margin:8mm') ?>}
    .print-doc{flex-direction:column;align-items:center;gap:16px}
    .copy-mark{font:600 11px/1.4 Inter,sans-serif;color:#64748b;text-align:right;margin-bottom:4px}
    @media print{body{background:#fff}.print-bar{display:none}.print-doc{padding:0;display:block}.print-doc .receipt{border:0;padding:0;max-width:none}.print-doc .a4{box-shadow:none;padding:0;width:auto;max-width:none;border-radius:0;zoom:<?= $kind === 'a5' ? '.71' : '1' ?>}.print-copy+.print-copy{break-before:page}}
  </style>
</head>
<body>
  <div class="print-bar"><button class="btn primary" type="button" id="pb-print"><?= icon('print', 'sm') ?>Print</button><button class="btn" type="button" data-print-setup><?= icon('sliders', 'sm') ?>Printer</button><button class="btn" type="button" onclick="history.length>1&&!window.opener?history.back():window.close()">Close</button></div>
  <div class="print-doc"><?= $content ?></div>
  <script src="<?= esc(asset_url('js/omnipos-ui.js'), 'attr') ?>" defer></script>
  <script>window.OMNI_PRINTER_DEFAULT=<?= json_encode(\App\Modules\Orders\Services\PrintSettings::printer(service('posSession')->business()), JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <script src="<?= esc(base_url('assets/pos/print.js') . '?v=' . filemtime(FCPATH . 'assets/pos/print.js'), 'attr') ?>" defer></script>
  <script>
  window.addEventListener('load', function () {
    var kind = <?= json_encode($kind) ?>, width = <?= (int) $width ?>, doc = document.querySelector('.print-doc');
    // fit the paper to the screen (phones, tablets in portrait); printing always uses the real size
    function fit() { var a = document.querySelector('.print-doc .a4'); if (!a) return; document.documentElement.style.setProperty('--fit', Math.min(1, (window.innerWidth - 24) / 820).toFixed(3)); }
    fit(); window.addEventListener('resize', fit); window.addEventListener('orientationchange', fit);
    // thermal receipts go to the device's chosen printer (Bluetooth / RawBT), anything else to the print dialog
    function go() {
      if (window.OmniPrint && kind === 'receipt' && OmniPrint.settings().mode !== 'browser') OmniPrint.printHtml(doc.innerHTML, { kind: kind, paper: String(width) });
      else window.print();
    }
    document.getElementById('pb-print').addEventListener('click', go);
    <?php if ($auto): ?>setTimeout(go, 300);<?php endif ?>
  });
  </script>
</body>
</html>
