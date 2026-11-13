<?php
/**
 * @var list<array>                       $plans
 * @var array<string, array>              $types
 * @var string                            $type   selected business type
 * @var array<int, array<string, int>>    $counts plan id => type => tenants
 * @var array<string, array>              $modules
 * @var int                               $yearlyMonths
 */
helper('ui');
use App\Modules\Plans\Services\PlanAdmin;

$catalog = service('catalog');
$tk = static fn ($n): string => money($n, ['decimals' => 0]);
$engine = service('pricing')->active();
$typeTenants = static fn (string $ty): int => array_sum(array_map(static fn (array $byType): int => $byType[$ty] ?? 0, $counts));
?>
<div class="page">
  <?php if ($engine): ?><div class="sbanner info"><?= icon('info', 'sm') ?><span class="grow">Prices, outlet discounts and customer discounts are now managed under <b>Pricing</b>. Plans only set limits, bundled modules and features.</span><a class="btn sm primary" href="<?= esc(site_url('pricing'), 'attr') ?>">Open Pricing</a></div><?php endif ?>
  <div class="page-h"><p class="page-sub">Each business type has its own plan prices. Leave a price empty to not offer that plan to the type. Modules, features and limits are set per plan.</p><div class="spacer"></div>
    <a class="btn primary" href="<?= esc(site_url('plans/new'), 'attr') ?>"><?= icon('plus', 'sm') ?>New plan</a></div>
  <div class="cat-chips type-chips">
    <?php foreach ($types as $code => $ty): ?><a class="chip sm <?= $type === $code ? 'on' : '' ?>" href="<?= esc(site_url('plans') . '?type=' . rawurlencode($code), 'attr') ?>"><?= sym_icon($ty['icon']) ?> <?= esc($ty['name']) ?></a><?php endforeach ?>
  </div>
  <div class="plans">
    <?php foreach ($plans as $p): $price = $catalog->planPrice((int) $p['id'], $type); $n = $counts[(int) $p['id']][$type] ?? 0; $total = array_sum($counts[(int) $p['id']] ?? []); ?>
      <article class="plan <?= $p['is_popular'] ? 'pop' : '' ?> <?= $p['is_active'] && $price !== null ? '' : 'off' ?>" style="--pc:<?= css_color($p['color']) ?>" id="plan-<?= (int) $p['id'] ?>">
        <?php if ($p['is_popular']): ?><span class="plan-badge">Most popular</span><?php endif ?>
        <h3><?= esc($p['name']) ?> <?= $p['is_active'] ? '' : tag_html('Hidden') ?></h3><p class="muted"><?= esc($p['tagline'] ?? '') ?></p>
        <?php if (! $engine): ?><div class="plan-price"><?php if ($price !== null): ?><?= esc($tk($price)) ?><small>/month</small><?php else: ?><small>Not offered</small><?php endif ?></div>
        <small class="muted"><?= $price !== null ? esc($tk($price * $yearlyMonths)) . '/year · ' : '' ?><?= $n ?> <?= esc($types[$type]['name']) ?> tenant<?= $n === 1 ? '' : 's' ?> · <?= $total ?> in total</small><?php else: ?><small class="muted"><?= $total ?> tenant<?= $total === 1 ? '' : 's' ?></small><?php endif ?>
        <ul>
          <li><?= icon('check', 'sm') ?><?= (int) $p['limit_outlets'] ? number_format((int) $p['limit_outlets']) : 'Unlimited' ?> outlets</li>
          <li><?= icon('check', 'sm') ?><?= (int) $p['limit_users'] ? number_format((int) $p['limit_users']) : 'Unlimited' ?> team members</li>
          <li><?= icon('check', 'sm') ?>Unlimited products &amp; orders</li>
          <li><?= icon('check', 'sm') ?><?= $p['all_modules'] ? 'All modules (except opt-in ones)' : 'Modules: ' . esc(implode(', ', array_map(static fn (string $m): string => (string) ($modules[$m]['name'] ?? $m), (array) $p['modules'])) ?: 'core only') ?></li>
          <?php foreach (PlanAdmin::FEATURES as $k => $l): $on = ! empty($p['features'][$k]); ?><li class="<?= $on ? '' : 'off' ?>"><?= icon($on ? 'check' : 'x', 'sm') ?><?= esc($l) ?></li><?php endforeach ?>
        </ul>
        <div class="row-add"><a class="btn sm" href="<?= esc(site_url('plans/' . $p['id'] . '/edit'), 'attr') ?>"><?= icon('edit', 'sm') ?>Edit</a>
          <?php if ($total === 0): ?><form class="inline" method="post" action="<?= esc(site_url('plans/' . $p['id'] . '/delete'), 'attr') ?>" data-confirm="Delete the <?= esc($p['name'], 'attr') ?> plan?"><?= csrf_field() ?><button class="btn sm danger" type="submit" aria-label="Delete plan"><?= icon('trash', 'sm') ?></button></form><?php endif ?></div>
      </article>
    <?php endforeach ?>
  </div>

  <?php if (! $engine): ?><form method="post" action="<?= esc(site_url('plans/prices'), 'attr') ?>"><?= csrf_field() ?><input type="hidden" name="type" value="<?= esc($type, 'attr') ?>">
    <section class="card"><div class="card-h"><h3>Price list by business type</h3><small class="muted">Monthly ৳ · yearly = <?= $yearlyMonths ?> × monthly · empty = not offered</small></div>
      <div class="table-wrap"><table class="tbl price-matrix"><thead><tr><th>Business type</th>
        <?php foreach ($plans as $p): ?><th class="num" style="color:<?= css_color($p['color']) ?>"><?= esc($p['name']) ?></th><?php endforeach ?><th class="num">Tenants</th></tr></thead><tbody>
        <?php foreach ($types as $code => $ty): ?>
          <tr class="<?= $code === $type ? 'hl-row' : '' ?>"><td><a class="link" href="<?= esc(site_url('plans') . '?type=' . rawurlencode($code), 'attr') ?>"><?= sym_icon($ty['icon']) ?> <?= esc($ty['name']) ?></a></td>
            <?php foreach ($plans as $p): $v = $catalog->planPrice((int) $p['id'], $code); ?>
              <td class="num"><input class="input" type="number" min="0" step="any" name="prices[<?= (int) $p['id'] ?>][<?= esc($code, 'attr') ?>]" value="<?= $v !== null ? esc((string) (float) $v, 'attr') : '' ?>" placeholder="—" aria-label="<?= esc($p['name'] . ' · ' . $ty['name'], 'attr') ?>"></td>
            <?php endforeach ?>
            <td class="num"><?= $typeTenants($code) ?></td></tr>
        <?php endforeach ?>
      </tbody></table></div>
      <div class="card-b"><div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save prices</button></div><?= field_error('prices') ?></div>
    </section>
  </form><?php endif ?>
</div>
