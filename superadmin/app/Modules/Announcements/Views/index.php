<?php
/**
 * @var string                $table
 * @var list<array>           $rows      announcements on this page (edit dialogs)
 * @var array<string, string> $audiences
 */
helper('ui');
$dialog = static function (?array $a, array $audiences): string {
    $id = $a['id'] ?? null;
    $a ??= ['title' => '', 'body' => '', 'level' => 'info', 'audience' => 'all', 'is_active' => 1];
    $opts = '';
    foreach ($audiences as $v => $l) {
        $opts .= '<option value="' . esc($v, 'attr') . '" ' . selected($a['audience'], $v) . '>' . esc($l) . '</option>';
    }
    $levels = '';
    foreach (['info' => 'Info', 'warn' => 'Warning', 'success' => 'Good news'] as $v => $l) {
        $levels .= '<option value="' . $v . '" ' . selected($a['level'], $v) . '>' . $l . '</option>';
    }

    return '<dialog class="modal-dlg" id="ann-' . ($id ?? 'new') . '"><form method="post" action="' . esc(site_url('announcements' . ($id ? '/' . $id : '')), 'attr') . '">' . csrf_field()
        . '<div class="modal-h"><h3>' . ($id ? 'Edit announcement' : 'New announcement') . '</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal-b"><div class="form-grid">'
        . '<label class="field span2">Title<input class="input" name="title" value="' . esc($a['title'], 'attr') . '" required maxlength="160"></label>'
        . '<label class="field span2">Message<textarea class="input" name="body" rows="3" required maxlength="2000">' . esc($a['body']) . '</textarea></label>'
        . '<label class="field">Type<select class="select" name="level">' . $levels . '</select></label>'
        . '<label class="field">Show to<select class="select" name="audience">' . $opts . '</select></label>'
        . '<label class="check span2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" ' . checked((bool) $a['is_active']) . '>Live now</label>'
        . '</div></div><div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">' . ($id ? 'Save' : 'Publish') . '</button></div></form></dialog>';
};
?>
<div class="page">
  <div class="page-h"><p class="page-sub">Messages appear as a banner inside tenant workspaces. Owners can dismiss them.</p><div class="spacer"></div>
    <button class="btn primary" type="button" data-modal-open="ann-new"><?= icon('plus', 'sm') ?>New announcement</button></div>
  <section class="card"><?= $table ?></section>
</div>
<?= $dialog(null, $audiences) ?>
<?php foreach ($rows as $r): ?><?= $dialog($r, $audiences) ?><?php endforeach ?>
