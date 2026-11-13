<?php
/**
 * Signature block of a printed document. The authorized signature is switched on / off in
 * Invoices › Invoice page (with an optional name, title and signature image); a delivery challan
 * also has a line for the receiver.
 *
 * @var array<string, mixed> $d        OrderDocs::build() (sign = show, name, title, image)
 * @var bool|null            $receiver add the receiver's line (delivery challan)
 */
$sg = (array) ($d['sign'] ?? ['show' => true, 'name' => '', 'title' => '', 'image' => null]);
$receiver = ! empty($receiver);
$customer = ! empty($customer);
if (empty($sg['show']) && ! $receiver && ! $customer) {
    return;
}
?>
<style>
.doc-sign{display:flex;justify-content:flex-end;gap:40px;margin:14px 0 2px;break-inside:avoid}
.doc-sign-box{min-width:190px;max-width:260px;text-align:center;font:600 11px/1.35 Inter,system-ui,sans-serif;color:#374151}
.doc-sign-sp{display:flex;align-items:flex-end;justify-content:center;height:40px}
.doc-sign-sp img{max-height:38px;max-width:220px;object-fit:contain}
.doc-sign-ln{display:block;border-top:1px solid #1f2937;margin:2px 0 4px}
.doc-sign-box b{display:block;font-weight:700;font-size:11px;letter-spacing:.02em;color:#111827}
.doc-sign-box i{display:block;font-style:normal;font-weight:600;font-size:12px;color:#111827;margin-top:1px}
.doc-sign-box small{display:block;font-weight:500;font-size:10.5px;color:#6b7280}
.doc-sign.two{justify-content:space-between}
</style>
<div class="doc-sign<?= $receiver || $customer ? ' two' : '' ?>">
  <?php if ($customer && ! $receiver): ?><div class="doc-sign-box"><span class="doc-sign-sp"></span><span class="doc-sign-ln"></span><b>Customer signature</b></div><?php endif ?>
  <?php if ($receiver): ?><div class="doc-sign-box"><span class="doc-sign-sp"></span><span class="doc-sign-ln"></span><b>Receiver signature</b></div><?php endif ?>
  <?php if (! empty($sg['show'])): ?>
    <div class="doc-sign-box"><span class="doc-sign-sp"><?php if (! empty($sg['image'])): ?><img src="<?= esc((string) $sg['image'], 'attr') ?>" alt=""><?php endif ?></span><span class="doc-sign-ln"></span><b>Authorized signature</b>
      <?php if (! empty($sg['name'])): ?><i><?= esc((string) $sg['name']) ?></i><?php endif ?><?php if (! empty($sg['title'])): ?><small><?= esc((string) $sg['title']) ?></small><?php endif ?></div>
  <?php endif ?>
</div>
