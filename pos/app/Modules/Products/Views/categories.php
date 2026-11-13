<?php
/**
 * @var string       $table
 * @var list<array>  $rows   rows on this page (edit dialogs)
 * @var list<string> $icons
 * @var bool         $manage
 */
helper('ui');
$iconSelect = static function (string $current) use ($icons): string {
    $h = '<select class="select" name="icon" aria-label="Icon">';
    foreach ($icons as $ic) {
        $h .= '<option value="' . esc($ic, 'attr') . '" ' . selected($current, $ic) . '>' . esc(str_replace('-', ' ', $ic)) . '</option>';
    }

    return $h . '</select>';
};
?>
<div class="page">
  <div class="page-h">
    <div><h2>Categories</h2><p class="page-sub"><a class="link" href="<?= esc(site_url('products'), 'attr') ?>"><?= icon('chevl', 'sm') ?>Products</a></p></div>
  </div>
  <?php
$topCats = db_connect()->table('categories')->select('id, name')->where('business_id', service('tenant')->businessId())->where('parent_id', null)->orderBy('name')->get()->getResultArray();
$parentSelect = static function (?int $cur, ?int $self = null) use ($topCats): string {
    $o = '<option value="0">— Top level —</option>';
    foreach ($topCats as $t) {
        if ((int) $t['id'] !== $self) {
            $o .= '<option value="' . (int) $t['id'] . '"' . ((int) $t['id'] === (int) $cur ? ' selected' : '') . '>' . esc($t['name']) . '</option>';
        }
    }

    return '<select class="select" name="parent_id" aria-label="Parent category">' . $o . '</select>';
};
?>
  <?php if ($manage): ?>
    <section class="card"><div class="card-b">
      <form class="row-add" method="post" action="<?= esc(site_url('products/categories'), 'attr') ?>">
        <?= csrf_field() ?>
        <div class="ra-ic"><?= $iconSelect('tag') ?></div>
        <input class="input" name="name" placeholder="New category name" required maxlength="100">
        <?= $parentSelect(null) ?>
        <input class="input ra-ord" type="number" name="sort_order" min="0" placeholder="Order" aria-label="Order">
        <button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add</button>
      </form>
      <?= field_error('name') ?>
    </div></section>
  <?php endif ?>
  <section class="card"><?= $table ?></section>
</div>
<?php if ($manage): foreach ($rows as $r): ?>
  <dialog class="modal-dlg sm" id="cat-<?= (int) $r['id'] ?>">
    <form method="post" action="<?= esc(site_url('products/categories/' . $r['id']), 'attr') ?>">
      <?= csrf_field() ?>
      <div class="modal-h"><h3>Edit category</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
      <div class="modal-b">
        <label class="field">Name<input class="input" name="name" value="<?= esc($r['name'], 'attr') ?>" required maxlength="100"></label>
        <label class="field">Parent category<?= $parentSelect(isset($r['parent_id']) ? (int) $r['parent_id'] : null, (int) $r['id']) ?></label>
        <label class="field">Icon<?= $iconSelect((string) $r['icon']) ?></label>
        <label class="field">Order<input class="input" type="number" min="0" name="sort_order" value="<?= (int) $r['sort_order'] ?>"></label>
      </div>
      <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Save</button></div>
    </form>
  </dialog>
<?php endforeach; endif ?>
