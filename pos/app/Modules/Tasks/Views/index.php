<?php
/**
 * @var string             $table
 * @var array<string, int> $counts
 * @var bool               $all
 * @var string             $status
 * @var bool               $manage
 * @var list<array>        $team
 * @var int                $me
 */
helper('ui');
$q = static fn (array $set): string => site_url('tasks') . (($s = http_build_query(array_filter(array_merge(['scope' => $all ? 'all' : '', 'status' => $status === 'open' ? '' : $status], $set)))) !== '' ? '?' . $s : '');
?>
<div class="page">
  <div class="page-h">
    <div><h2>Tasks</h2><p class="page-sub"><?= $manage ? 'Assign work to your team and track it.' : 'Your assigned work.' ?></p></div>
    <?php if ($manage): ?><div class="seg"><a class="<?= $all ? '' : 'on' ?>" href="<?= esc($q(['scope' => '']), 'attr') ?>">My tasks</a><a class="<?= $all ? 'on' : '' ?>" href="<?= esc($q(['scope' => 'all']), 'attr') ?>">Whole team</a></div><?php endif ?>
    <div class="spacer"></div>
    <?php if ($manage): ?>
      <form class="inline" method="post" action="<?= esc(site_url('tasks/auto'), 'attr') ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= icon('zap', 'sm') ?>Auto-create from stock alerts</button></form>
      <button class="btn primary" type="button" data-modal-open="dlg-task"><?= icon('plus', 'sm') ?>New task</button>
    <?php endif ?>
  </div>
  <div class="stats">
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'checks', 'label' => 'Open', 'value' => (string) $counts['open']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'clock', 'label' => 'Due today', 'value' => (string) $counts['today']]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'alert', 'label' => 'Overdue', 'value' => (string) $counts['overdue'], 'color' => $counts['overdue'] ? 'var(--danger)' : null]) ?>
    <?= view('OmniPOS\Ui\Views\components\stat', ['icon' => 'check', 'label' => 'Done', 'value' => (string) $counts['done']]) ?>
  </div>
  <div class="cat-chips"><?php foreach (['open' => 'Open', 'overdue' => 'Overdue', 'done' => 'Done', 'any' => 'All'] as $k => $l): ?><a class="chip <?= $status === $k ? 'on' : '' ?>" href="<?= esc($q(['status' => $k === 'open' ? '' : $k]), 'attr') ?>"><?= esc($l) ?></a><?php endforeach ?></div>
  <div class="card"><?= $table ?></div>
</div>
<?php if ($manage): ?>
<dialog class="modal-dlg" id="dlg-task">
  <form method="post" action="<?= esc(site_url('tasks'), 'attr') ?>">
    <?= csrf_field() ?>
    <div class="modal-h"><h3>New task</h3><button class="icon-btn" type="button" data-ui="modal-close" aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="modal-b"><div class="form-grid">
      <label class="field span2">Task *<input class="input" name="title" required maxlength="200" placeholder="e.g. Count rice stock in store room" autofocus></label>
      <label class="field">Assign to<select class="select" name="assignee_id"><?php foreach ($team as $u): ?><option value="<?= (int) $u['id'] ?>" <?= selected($me, $u['id']) ?>><?= esc($u['name']) ?></option><?php endforeach ?></select></label>
      <label class="field">Due<input class="input" type="datetime-local" name="due_at" value="<?= esc(date('Y-m-d\T18:00'), 'attr') ?>"></label>
    </div></div>
    <div class="modal-f"><button class="btn" type="button" data-ui="modal-close">Cancel</button><button class="btn primary" type="submit">Assign task</button></div>
  </form>
</dialog>
<?php endif ?>
