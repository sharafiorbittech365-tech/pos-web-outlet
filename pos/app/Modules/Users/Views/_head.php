<?php
/** @var string $tab users | roles | log */
helper('ui');
$b = service('posSession')->business();
?>
<div class="page-h">
  <div><h2>Users &amp; roles</h2><p class="page-sub">Who can do what at <?= esc($b['name'] ?? '') ?>.</p></div>
  <div class="spacer"></div>
  <div class="seg">
    <?php foreach ([['users', 'users', 'Team'], ['roles', 'users/roles', 'Roles & permissions'], ['log', 'users/activity', 'Activity log']] as [$k, $url, $l]): ?>
      <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= esc(site_url($url), 'attr') ?>"><?= esc($l) ?></a>
    <?php endforeach ?>
  </div>
</div>
