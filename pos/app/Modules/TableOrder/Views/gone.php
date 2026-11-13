<?php
/**
 * Shown for an unknown / retired table code, a closed outlet or when table ordering is off.
 *
 * @var string $message
 * @var string $title
 */
helper('ui');
?><!doctype html>
<html lang="en" data-theme="light">
<head>
<?= view('App\Modules\TableOrder\Views\_head', ['title' => $title, 'primary' => '#4F46E5']) ?>
</head>
<body class="to to-plain">
<main class="to-done-card">
  <span class="to-ok warn"><?= icon('alert') ?></span>
  <h1>We cannot open this table</h1>
  <p><?= esc($message) ?></p>
</main>
</body>
</html>
