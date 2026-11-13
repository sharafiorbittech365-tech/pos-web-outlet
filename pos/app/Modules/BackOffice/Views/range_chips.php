<?php
/**
 * Period chips (today, yesterday, 7 days, this month, last month, custom) for reports & lists.
 *
 * @var \App\Modules\BackOffice\Support\DateRange $range
 * @var string                                    $base  page URL
 * @var array<string, string>                     $keep  other query params to keep (tab, filter)
 * @var list<string>|null                         $only  range keys to show
 */
helper('ui');
use App\Modules\BackOffice\Support\DateRange;

$keep ??= [];
$only ??= array_keys(DateRange::RANGES);
?>
<?php foreach ($only as $k): if ($k === 'custom') {
    continue;
} ?>
  <a class="chip <?= $range->key === $k ? 'on' : '' ?>" href="<?= esc($base . '?' . http_build_query(array_merge($keep, ['range' => $k])), 'attr') ?>"><?= esc(DateRange::RANGES[$k]) ?></a>
<?php endforeach ?>
<?php if (in_array('custom', $only, true)): ?>
  <form class="range-custom <?= $range->key === 'custom' ? 'on' : '' ?>" method="get" action="<?= esc($base, 'attr') ?>">
    <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= esc($k, 'attr') ?>" value="<?= esc($v, 'attr') ?>"><?php endforeach ?>
    <input type="hidden" name="range" value="custom">
    <input class="input sm" type="date" name="from" value="<?= esc($range->fromDate(), 'attr') ?>" aria-label="From">
    <span class="muted">to</span>
    <input class="input sm" type="date" name="to" value="<?= esc($range->toDate(), 'attr') ?>" aria-label="To">
    <button class="chip <?= $range->key === 'custom' ? 'on' : '' ?>" type="submit"><?= icon('calendar', 'sm') ?>Custom</button>
  </form>
<?php endif ?>
