<?php
/**
 * The print document inside the invoice page's viewer (iframe): same markup and styles as the
 * print page, without its toolbar; scales an A4 page down to the frame width.
 *
 * @var string $title
 * @var string $content document markup (already escaped)
 * @var string $kind    receipt | a4 | a5
 * @var string $primary
 */
helper('ui');
?><!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= esc($title) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= esc(asset_url('css/app.css'), 'attr') ?>">
  <style>
    :root{--primary:<?= css_color($primary) ?>}
    html,body{margin:0;background:#eef1f6}
    .ev{padding:18px;display:flex;justify-content:center}
    .ev .a4{box-shadow:0 10px 30px -12px rgba(16,24,40,.25);border-radius:6px;width:794px;max-width:none;flex:none;background:#fff}
    .ev .receipt{background:#fff;max-width:340px}
    /* the page is scaled, never reflowed: keep the desktop A4 layout at any viewer width */
    .ev .a4{padding:40px 44px}
    .ev .a4-head{display:flex}.ev .a4-parties{display:grid;grid-template-columns:1fr 1fr}.ev .a4-bottom{display:grid;grid-template-columns:1fr 300px}
    .ev .a4 h2{text-align:right}.ev .a4-title table{margin-left:auto}.ev .a4-items{font-size:13px}
    @media (max-width:640px){.ev{padding:8px}}
  </style>
</head>
<body><div class="ev" id="ev"><?= $content ?></div>
<script>/* an A4 page keeps its layout and is scaled down to the viewer width */
(function(){var ev=document.getElementById('ev'),a4=ev.querySelector('.a4');if(!a4)return;function fit(){var w=document.documentElement.clientWidth-(window.innerWidth<640?16:36);a4.style.zoom=w<794?(w/794).toFixed(3):'';}fit();window.addEventListener('resize',fit);})();</script>
</body>
</html>
