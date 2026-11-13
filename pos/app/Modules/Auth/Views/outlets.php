<?php
/**
 * @var list<array<string, mixed>> $outlets
 * @var int                        $current
 */
helper('ui');
?>
<div class="page">
  <div class="page-h"><div><h2>Outlets</h2><p class="page-sub">Open another outlet of this account</p></div></div>
  <div class="opt-grid">
    <?php foreach ($outlets as $o): ?>
      <form method="post" action="<?= esc(site_url('outlets/switch'), 'attr') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="business_id" value="<?= (int) $o['id'] ?>">
        <button class="opt <?= (int) $o['id'] === $current ? 'on' : '' ?>" type="submit" <?= (int) $o['id'] === $current ? 'disabled' : '' ?> style="width:100%">
          <b><?= sym_icon($o['type_icon'] ?? null) ?> <?= esc($o['name']) ?></b>
          <span><?= esc($o['type_name']) ?><?= ! empty($o['area']) ? ' · ' . esc($o['area']) : '' ?><?= (int) $o['id'] === $current ? ' · current' : '' ?></span>
        </button>
      </form>
    <?php endforeach ?>
  </div>
</div>
