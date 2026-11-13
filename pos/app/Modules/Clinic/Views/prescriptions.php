<?php
/**
 * Clinic › Prescriptions: latest revision of every prescription, filtered by status, doctor and date.
 *
 * @var string                     $table
 * @var array<string, mixed>       $f        status, doctor, from, to
 * @var list<array<string, mixed>> $doctors
 * @var bool                       $canRx
 */
helper('ui');
$q = static fn (array $c): string => site_url('clinic/prescriptions') . '?' . http_build_query(array_filter(array_merge($f, $c)));
?>
<div class="page cl-page">
  <div class="page-h">
    <div><h2>Prescriptions</h2><p class="page-sub">Drafts and final prescriptions. Final ones are locked — a change makes a new revision.</p></div>
    <div class="spacer"></div>
    <?php if ($canRx): ?><a class="btn primary" href="<?= esc(site_url('clinic/prescriptions/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>New prescription</a><?php endif ?>
  </div>
  <div class="seg"><?php foreach (['' => 'All', 'draft' => 'Drafts', 'final' => 'Final'] as $k => $l): ?><a class="<?= (string) $f['status'] === $k ? 'on' : '' ?>" href="<?= esc($q(['status' => $k]), 'attr') ?>"><?= esc($l) ?></a><?php endforeach ?></div>
  <form class="cl-bar" method="get" action="<?= esc(site_url('clinic/prescriptions'), 'attr') ?>">
    <?php if ($f['status']): ?><input type="hidden" name="status" value="<?= esc((string) $f['status'], 'attr') ?>"><?php endif ?>
    <label class="field cl-inline">Doctor<select class="select" name="doctor" data-cl-autosubmit><option value="">All doctors</option><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $f['doctor'] ? 'selected' : '' ?>><?= esc($d['name']) ?></option><?php endforeach ?></select></label>
    <label class="field cl-inline">From<input class="input" type="date" name="from" value="<?= esc((string) $f['from'], 'attr') ?>"></label>
    <label class="field cl-inline">To<input class="input" type="date" name="to" value="<?= esc((string) $f['to'], 'attr') ?>"></label>
    <button class="btn" type="submit"><?= icon('search', 'sm') ?>Show</button>
    <?php if (array_filter($f)): ?><a class="btn" href="<?= esc(site_url('clinic/prescriptions'), 'attr') ?>">Clear</a><?php endif ?>
  </form>
  <div class="card"><?= $table ?></div>
</div>
