<?php
/**
 * The new account was placed on another POS server.
 *
 * @var array<string, mixed> $server
 * @var string               $email
 * @var string               $pin
 */
helper('ui');
$url = rtrim((string) $server['base_url'], '/') . '/login?' . http_build_query(['server' => 'moved', 'email' => $email]);
?>
<h2>Your workspace is ready</h2>
<p class="muted">Your account is served by <b><?= esc($server['name']) ?></b>. Sign in there with <b><?= esc($email) ?></b> and the password you chose.</p>
<div class="due-box"><small>Your POS address</small><b style="font-size:16px;word-break:break-all"><?= esc(rtrim((string) $server['base_url'], '/')) ?></b></div>
<p class="muted" style="font-size:13px">Your till PIN is <b><?= esc($pin) ?></b> — change it in Users &amp; roles after signing in.</p>
<a class="btn primary lg full" href="<?= esc($url, 'attr') ?>"><?= icon('user', 'sm') ?>Sign in on <?= esc($server['name']) ?></a>
