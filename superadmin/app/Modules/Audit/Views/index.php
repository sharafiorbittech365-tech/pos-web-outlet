<?php
/**
 * @var string                    $table
 * @var list<string>              $actionList
 * @var string                    $action
 * @var array<string, mixed>|null $tenant
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <form class="row-add" method="get" action="<?= esc(site_url('audit'), 'attr') ?>">
      <?php if ($tenant): ?><input type="hidden" name="tenant" value="<?= (int) $tenant['id'] ?>"><?php endif ?>
      <select class="select" name="action" aria-label="Action" onchange="this.form.submit()" style="width:auto">
        <option value="">All actions</option>
        <?php foreach ($actionList as $a): ?><option value="<?= esc($a, 'attr') ?>" <?= selected($action, $a) ?>><?= esc($a) ?></option><?php endforeach ?>
      </select>
      <noscript><button class="btn sm" type="submit">Filter</button></noscript>
    </form>
    <?php if ($tenant): ?>
      <span class="chip sm on"><?= icon('store', 'sm') ?><?= esc($tenant['name']) ?></span>
      <a class="link" href="<?= esc(site_url('audit') . ($action !== '' ? '?action=' . rawurlencode($action) : ''), 'attr') ?>">All tenants</a>
      <a class="link" href="<?= esc(site_url('tenants/' . $tenant['id']), 'attr') ?>">Open account</a>
    <?php endif ?>
  </div>
  <section class="card"><?= $table ?></section>
  <p class="muted adm-note"><?= icon('shield', 'sm') ?> Every Super Admin and billing action is recorded here with the staff member, time and IP address. Entries cannot be edited or deleted.</p>
</div>
