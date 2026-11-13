<?php
/**
 * Print-ready prescription on the doctor's (or the clinic's) pad — the same markup as the editor's
 * left side (_pad, read-only). "Save as PDF" = the browser print dialog with "Save as PDF" as the
 * printer; @page carries the paper size and the pad's margins (a pre-printed letterhead keeps its
 * header space blank).
 *
 * @var array<string, mixed> $rx
 * @var array<string, mixed> $view  PadView::build()
 * @var bool                 $auto  open the print dialog on load
 * @var ?array<string, mixed> $booking {serial_no} of the appointment
 */
helper('ui');
$m = $view['margins'];
$a5 = $view['paper'] === 'a5';
$url = static fn (string $p): string => site_url('clinic/rx/' . $rx['id'] . '/print') . '?paper=' . $p;
$css = FCPATH . 'assets/pos/rxpad.css';
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= esc($rx['rx_no'] . ' · ' . ($rx['patient_snapshot']['name'] ?? '')) ?></title>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <link rel="stylesheet" href="<?= esc(base_url('assets/pos/rxpad.css') . '?v=' . (is_file($css) ? filemtime($css) : 0), 'attr') ?>">
  <style>
    @page { size: <?= $a5 ? 'A5' : 'A4' ?>; margin: <?= $m[0] ?>mm <?= $m[1] ?>mm <?= $m[2] ?>mm <?= $m[3] ?>mm; }
    body { margin: 0; background: #e2e8f0; }
    .rp-bar { display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: wrap; padding: 12px 16px; font: 500 13px/1.4 Inter, sans-serif; }
    .rp-sheet { display: flex; justify-content: center; padding: 0 16px 24px; }
    .rp-sheet .rxp { box-shadow: 0 2px 14px rgba(15, 23, 42, .18); }
    @media screen and (max-width: 860px) { .rp-sheet .rxp { width: 100%; min-height: 0; } .rp-sheet .rxp-body { grid-template-columns: 1fr; } .rp-sheet .rxp-l { border-right: 0; } }
    @media print {
      body { background: #fff; }
      .rp-bar { display: none; }
      .rp-sheet { padding: 0; display: block; }
      /* the printer's page margins (@page) replace the sheet padding */
      .rp-sheet .rxp { box-shadow: none; width: auto; min-height: calc(<?= $a5 ? '210mm' : '297mm' ?> - <?= $m[0] + $m[2] ?>mm - 1mm); padding: 0; }
      .rxp-mark { position: fixed; }
    }
  </style>
</head>
<body>
  <div class="rp-bar">
    <button class="btn primary" type="button" data-print><?= icon('print', 'sm') ?>Print</button>
    <button class="btn" type="button" data-print title="Choose &quot;Save as PDF&quot; as the printer"><?= icon('download', 'sm') ?>Save as PDF</button>
    <a class="btn <?= ! $a5 ? 'primary' : '' ?>" href="<?= esc($url('a4'), 'attr') ?>" aria-pressed="<?= ! $a5 ? 'true' : 'false' ?>">A4</a><a class="btn <?= $a5 ? 'primary' : '' ?>" href="<?= esc($url('a5'), 'attr') ?>" aria-pressed="<?= $a5 ? 'true' : 'false' ?>">A5</a>
    <span class="muted">PDF: pick “Save as PDF” in the print dialog.</span>
  </div>
  <div class="rp-sheet"><?= view('App\Modules\Clinic\Views\_pad', ['rx' => $rx, 'view' => $view, 'edit' => false, 'booking' => $booking ?? null]) ?></div>
  <script>
    document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
    <?php if ($auto): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });<?php endif ?>
  </script>
</body>
</html>
