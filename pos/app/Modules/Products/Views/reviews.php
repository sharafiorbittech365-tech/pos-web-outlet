<?php
/**
 * Reviews of one product.
 *
 * @var array<string, mixed>       $p
 * @var list<array<string, mixed>> $rows
 * @var float                      $avg
 * @var bool                       $manage
 */
helper('ui');
$src = static fn (string $a): string => preg_match('#^https?://#i', $a) === 1 ? $a : base_url(ltrim($a, '/'));
$stars = static fn (int $n): string => str_repeat('★', $n) . str_repeat('☆', 5 - $n);
$post = static fn (string $path): string => esc(site_url($path), 'attr');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Reviews</h2><p class="page-sub"><a class="link" href="<?= $post('products/' . $p['id']) ?>"><?= icon('chevl', 'sm') ?><?= esc((string) $p['name']) ?></a> · <?= count($rows) ?> review<?= count($rows) === 1 ? '' : 's' ?><?= $avg > 0 ? ' · average ' . number_format($avg, 1) . ' ★' : '' ?></p></div>
  </div>
  <?php if ($manage): ?>
  <section class="card"><div class="card-h"><h3>Add a review</h3><span class="muted">e.g. a message a customer sent you</span></div><div class="card-b">
    <form method="post" enctype="multipart/form-data" action="<?= $post('products/' . $p['id'] . '/reviews') ?>"><?= csrf_field() ?>
      <div class="form-grid">
        <label class="field">Customer name *<input class="input" name="author" required maxlength="80" value="<?= old_or('author', '') ?>"><?= field_error('author') ?></label>
        <label class="field">Rating *<select class="select" name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= $stars($i) ?></option><?php endfor ?></select><?= field_error('rating') ?></label>
        <label class="field span2">Title<input class="input" name="title" maxlength="160"></label>
        <label class="field span2">Review<textarea class="input" rows="3" name="body" maxlength="3000"></textarea></label>
        <label class="field span2">Pictures (up to 5 — compressed automatically)<input class="input" type="file" name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple><?= field_error('images') ?></label>
        <label class="field">Show on website<select class="select" name="status"><option value="approved">Approved</option><option value="pending">Waiting</option><option value="rejected">Rejected</option></select></label>
        <label class="check" style="align-self:end"><input type="checkbox" name="verified_purchase" value="1">Verified purchase</label>
      </div>
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Add review</button></div>
    </form>
  </div></section>
  <?php endif ?>
  <section class="card"><div class="card-b">
    <?php if ($rows === []): ?><div class="empty"><span class="empty-ic"><?= icon('star') ?></span><b>No reviews yet</b><span>Customer reviews sent from the website appear here for your approval.</span></div><?php endif ?>
    <?php foreach ($rows as $r): $imgs = (array) (json_decode((string) $r['images'], true) ?: []); ?>
      <article style="padding:12px 0;border-bottom:1px solid var(--line,#ddd)">
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><b><?= esc((string) $r['author']) ?></b><span style="color:#f59e0b"><?= $stars((int) $r['rating']) ?></span>
          <?= (int) $r['verified_purchase'] ? tag_html('Verified purchase', 'ok') : '' ?><?= tag_html(ucfirst((string) $r['status']), $r['status'] === 'approved' ? 'ok' : '') ?><small class="muted"><?= esc((string) $r['created_at']) ?></small></div>
        <?php if ($r['title']): ?><div><b><?= esc((string) $r['title']) ?></b></div><?php endif ?>
        <?php if ($r['body']): ?><p style="margin:4px 0"><?= nl2br(esc((string) $r['body'])) ?></p><?php endif ?>
        <?php if ($imgs): ?><div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 0"><?php foreach ($imgs as $im): ?><a href="<?= esc($src((string) $im), 'attr') ?>" target="_blank" rel="noopener"><img src="<?= esc($src((string) $im), 'attr') ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px"></a><?php endforeach ?></div><?php endif ?>
        <?php if ($manage): ?><div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
          <?php foreach (['approved' => 'Approve', 'rejected' => 'Reject', 'pending' => 'Set to waiting'] as $k => $l): if ($r['status'] !== $k): ?>
            <form class="inline" method="post" action="<?= $post('products/reviews/' . $r['id'] . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $k ?>"><button class="btn sm" type="submit"><?= $l ?></button></form>
          <?php endif; endforeach ?>
          <form class="inline" method="post" action="<?= $post('products/reviews/' . $r['id'] . '/verified') ?>"><?= csrf_field() ?><button class="btn sm" type="submit"><?= (int) $r['verified_purchase'] ? 'Remove verified' : 'Mark verified' ?></button></form>
          <form class="inline" method="post" action="<?= $post('products/reviews/' . $r['id'] . '/delete') ?>" data-confirm="Delete this review?"><?= csrf_field() ?><button class="btn sm danger" type="submit"><?= icon('trash', 'sm') ?>Delete</button></form>
        </div><?php endif ?>
      </article>
    <?php endforeach ?>
  </div></section>
</div>
