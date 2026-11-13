<?php
/**
 * Mushak-6.3 register for one fiscal year.
 *
 * @var string       $table
 * @var string       $fy
 * @var list<string> $years
 * @var bool         $on
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Mushak-6.3 register</h2><p class="page-sub">Every VAT invoice issued in fiscal year <?= esc($fy) ?> (1 July – 30 June), in serial order.</p></div>
    <div class="spacer"></div>
    <form method="get" action="<?= esc(site_url('mushak'), 'attr') ?>"><label class="muted">Fiscal year <select class="select" name="fy" onchange="this.form.submit()"><?php foreach ($years as $y): ?><option value="<?= esc($y, 'attr') ?>" <?= selected($fy, $y) ?>><?= esc($y) ?></option><?php endforeach ?></select></label></form>
    <a class="btn" href="<?= esc(site_url('invoices'), 'attr') ?>"><?= icon('back', 'sm') ?>Invoices</a>
  </div>
  <?php if (! $on): ?><div class="sbanner warn"><?= icon('alert', 'sm') ?><span>Mushak-6.3 is switched off or incomplete for this outlet.</span><?php if (service('posSession')->can('mushak.settings')): ?><a class="btn sm" href="<?= esc(site_url('mushak/settings'), 'attr') ?>">Settings</a><?php endif ?></div><?php endif ?>
  <section class="card"><?= $table ?></section>
</div>
