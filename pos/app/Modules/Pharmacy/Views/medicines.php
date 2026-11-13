<?php
/**
 * Pharmacy › Medicine database: the shared, read-only reference list (imported by the platform).
 *
 * @var string $table
 */
helper('ui');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Medicine database</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('pharmacy'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Pharmacy</a></p></div>
  </div>
  <p class="muted bo-note">Shared reference data maintained by the platform. “Add as product” creates your own product (your price, stock and batches) linked to the medicine — the shared list itself never changes.</p>
  <div class="card"><?= $table ?></div>
</div>
