<?php
/**
 * Pricing — every price, module switch, outlet discount and package, all stored in the database.
 *
 * @var array{items: array<string, array<string, mixed>>, tiers: list<array<string, mixed>>} $cfg
 * @var array<string, array>   $modules  module catalogue (for dependency hints)
 * @var array<int, array>      $example  outlets => PricingEngine::compute()
 * @var list<array>            $packages
 * @var array<int|string, int> $pkgUse   package id => customers
 * @var int                    $custom   customers with their own discount / package
 */
helper('ui');
$tk = static fn ($n): string => money($n, ['decimals' => 0]);
$items = $cfg['items'];
$by = static fn (array $kinds): array => array_filter($items, static fn (array $i): bool => in_array($i['kind'], $kinds, true));
$row = static function (array $i, bool $inBaseToggle = false, bool $tier = false) use ($modules): string {
    $c = esc($i['code'], 'attr');
    $h = '<tr><td data-label="Item"><input class="input" name="items[' . $c . '][label]" value="' . esc($i['label'], 'attr') . '" aria-label="Name" maxlength="120">'
        . (($i['module_code'] ?? null) && isset($modules[$i['module_code']]) && $modules[$i['module_code']]['needs'] ? '<small class="muted">Needs ' . esc(implode(', ', array_map(static fn (string $n): string => (string) ($modules[$n]['name'] ?? $n), $modules[$i['module_code']]['needs']))) . '</small>' : '') . '</td>';
    $h .= '<td class="num" data-label="Up to outlets">' . ($tier ? '<input class="input" type="number" min="1" name="items[' . $c . '][max_outlets]" value="' . esc((string) $i['max_outlets'], 'attr') . '" aria-label="Up to outlets" style="max-width:6rem">' : '—') . '</td>';
    $h .= '<td class="num" data-label="৳ / month"><input class="input" type="number" min="0" step="any" name="items[' . $c . '][price]" value="' . esc((string) (float) $i['price'], 'attr') . '" aria-label="Monthly price" style="max-width:8rem"></td>';
    if ($inBaseToggle) {
        $h .= '<td data-label="Included"><input type="hidden" name="items[' . $c . '][in_base]" value="0"><label class="check"><input type="checkbox" name="items[' . $c . '][in_base]" value="1" ' . checked((bool) $i['in_base']) . '>In base package</label></td>';
    }
    $h .= '<td data-label="Offered"><input type="hidden" name="items[' . $c . '][enabled]" value="0"><label class="switch" title="Offered to customers"><input type="checkbox" name="items[' . $c . '][enabled]" value="1" ' . checked((bool) $i['is_enabled']) . ' aria-label="Enabled"><span></span></label></td></tr>';

    return $h;
};
$thead = static fn (string $first, bool $tier, bool $base = false): string => '<thead><tr><th>' . $first . '</th><th class="num">' . ($tier ? 'Up to outlets' : '') . '</th><th class="num">৳ / month</th>' . ($base ? '<th>Included</th>' : '') . '<th>Offered</th></tr></thead>';
?>
<div class="page">
  <div class="page-h"><p class="page-sub">All subscription prices and discount rules are stored in the database — nothing is fixed in code. Changes apply to every customer's next bill and live price preview.
    <?= $custom ?> customer<?= $custom === 1 ? ' has' : 's have' ?> their own discount or package (set on the customer's <b>Subscription</b> tab › <i>Custom pricing</i>).</p></div>

  <form method="post" action="<?= esc(site_url('pricing/items'), 'attr') ?>" id="prices"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Base package &amp; website</h3><small class="muted">POS + Invoice + Inventory + Customers + Reports + other core modules</small></div>
      <div class="table-wrap"><table class="tbl stack"><?= $thead('Item', false) ?><tbody>
        <?php foreach ($by(['base', 'website']) as $i) { echo $row($i); } ?>
      </tbody></table></div>
      <div class="card-b"><small class="muted">The package price is charged <b>per outlet</b>; the outlet discount below applies to it. Domain registration is not part of the monthly price — it is shown separately.</small></div>
    </section>

    <section class="card"><div class="card-h"><h3>Multi-outlet website</h3><small class="muted">The first tier that covers the customer's outlets; above the largest tier the extra-outlet charge is added per outlet</small></div>
      <div class="table-wrap"><table class="tbl stack"><?= $thead('Tier', true) ?><tbody>
        <?php foreach ($by(['multisite']) as $i) { echo $row($i, false, true); } ?>
        <?php foreach ($by(['outlet_extra']) as $i) { echo $row($i); } ?>
      </tbody></table></div>
    </section>

    <section class="card"><div class="card-h"><h3>Additional modules</h3><small class="muted">Switch a module off to stop offering it. "In base package" modules cost nothing extra.</small></div>
      <div class="table-wrap"><table class="tbl stack"><?= $thead('Module', false, true) ?><tbody>
        <?php foreach ($by(['module']) as $i) { echo $row($i, true); } ?>
      </tbody></table></div>
    </section>

    <section class="card"><div class="card-h"><h3>Domain registration</h3></div>
      <div class="table-wrap"><table class="tbl stack"><?= $thead('Item', false) ?><tbody>
        <?php foreach ($by(['domain']) as $i) { echo $row($i); } ?>
      </tbody></table></div>
      <div class="card-b"><small class="muted">The standard price shown to customers who add a domain. The real cost can be set per customer (Customer › Custom pricing).</small>
        <div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save prices</button></div><?= field_error('items') ?></div>
    </section>
  </form>

  <form method="post" action="<?= esc(site_url('pricing/tiers'), 'attr') ?>" id="discounts"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Outlet-based discount</h3><small class="muted">Applied to the base package of customers by their total number of outlets</small></div>
      <div class="table-wrap"><table class="tbl stack"><thead><tr><th>Name</th><th class="num">From outlets</th><th class="num">To outlets</th><th class="num">Discount %</th><th>On</th><th>Delete</th></tr></thead><tbody>
        <?php foreach ($cfg['tiers'] as $t): $k = (int) $t['id']; ?>
          <tr><td data-label="Name"><input class="input" name="tiers[<?= $k ?>][label]" value="<?= esc($t['label'], 'attr') ?>" maxlength="80"></td>
            <td class="num" data-label="From outlets"><input class="input" type="number" min="1" name="tiers[<?= $k ?>][min]" value="<?= (int) $t['min_outlets'] ?>" style="max-width:6rem"></td>
            <td class="num" data-label="To outlets"><input class="input" type="number" min="1" name="tiers[<?= $k ?>][max]" value="<?= $t['max_outlets'] === null ? '' : (int) $t['max_outlets'] ?>" placeholder="no limit" style="max-width:6rem"></td>
            <td class="num" data-label="Discount %"><input class="input" type="number" min="0" max="100" step="any" name="tiers[<?= $k ?>][percent]" value="<?= esc((string) (float) $t['percent'], 'attr') ?>" style="max-width:6rem"></td>
            <td data-label="On"><input type="hidden" name="tiers[<?= $k ?>][enabled]" value="0"><label class="switch"><input type="checkbox" name="tiers[<?= $k ?>][enabled]" value="1" <?= checked((bool) $t['is_enabled']) ?> aria-label="Enabled"><span></span></label></td>
            <td data-label="Delete"><input type="hidden" name="tiers[<?= $k ?>][delete]" value="0"><label class="check"><input type="checkbox" name="tiers[<?= $k ?>][delete]" value="1">Delete</label></td></tr>
        <?php endforeach ?>
        <tr><td data-label="Name"><input class="input" name="new[label]" placeholder="New rule name" maxlength="80"></td>
          <td data-label="From outlets" class="num"><input class="input" type="number" min="1" name="new[min]" placeholder="from" style="max-width:6rem"></td>
          <td data-label="To outlets" class="num"><input class="input" type="number" min="1" name="new[max]" placeholder="no limit" style="max-width:6rem"></td>
          <td data-label="Discount %" class="num"><input class="input" type="number" min="0" max="100" step="any" name="new[percent]" placeholder="%" style="max-width:6rem"></td>
          <td data-label="On"><input type="hidden" name="new[enabled]" value="1"></td><td data-label="Delete"><small class="muted">Add a rule</small></td></tr>
      </tbody></table></div>
      <div class="card-b"><small class="muted">Where ranges overlap the rule with the highest "from" wins. Leave "to" empty for no upper limit.</small>
        <div><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save discounts</button></div><?= field_error('tiers') ?></div>
    </section>
  </form>

  <section class="card"><div class="card-h"><h3>Price preview</h3><small class="muted">Base package + website, by outlets (saved values)</small></div>
    <div class="table-wrap"><table class="tbl stack"><thead><tr><th>Outlets</th><th class="num">Package</th><th class="num">Website</th><th class="num">Outlet discount</th><th class="num">Per month</th></tr></thead><tbody>
      <?php foreach ($example as $n => $e): ?><tr><td data-label="Outlets"><?= (int) $n ?></td><td class="num" data-label="Package"><?= esc($tk($e['breakdown']['base'])) ?></td><td class="num" data-label="Website"><?= esc($tk($e['breakdown']['website'])) ?></td>
        <td class="num" data-label="Outlet discount"><?= $e['outlet_discount'] > 0 ? '−' . esc($tk($e['outlet_discount'])) . ' (' . esc(rtrim(rtrim(number_format($e['outlet_discount_percent'], 2), '0'), '.')) . '%)' : '—' ?></td><td class="num" data-label="Per month"><b><?= esc($tk($e['monthly'])) ?></b></td></tr><?php endforeach ?>
    </tbody></table></div>
  </section>

  <section class="card" id="packages"><div class="card-h"><h3>Custom packages</h3><small class="muted">A bundle of website / modules at one fixed monthly price. Assign it to a customer under Customer › Custom pricing.</small></div>
    <?php foreach ($packages as $p): $pi = (array) (json_decode((string) $p['items'], true) ?: []); $n = (int) ($pkgUse[$p['id']] ?? 0); ?>
      <form class="card-b pkg-form" method="post" action="<?= esc(site_url('pricing/packages/' . $p['id']), 'attr') ?>"><?= csrf_field() ?>
        <div class="form-grid">
          <label class="field">Name<input class="input" name="name" value="<?= esc($p['name'], 'attr') ?>" maxlength="80" required></label>
          <label class="field">Price ৳ / month<input class="input" type="number" min="0" step="any" name="price" value="<?= esc((string) (float) $p['price'], 'attr') ?>" required></label>
          <label class="field span2">Description<input class="input" name="description" value="<?= esc((string) $p['description'], 'attr') ?>" maxlength="255"></label>
        </div>
        <?= view('App\Modules\Pricing\Views\package_items', ['items' => $items, 'chosen' => $pi]) ?>
        <div class="row-add"><input type="hidden" name="is_active" value="0"><label class="check"><input type="checkbox" name="is_active" value="1" <?= checked((bool) $p['is_active']) ?>>Offered</label>
          <small class="muted"><?= $n ?> customer<?= $n === 1 ? '' : 's' ?></small>
          <button class="btn sm primary" type="submit"><?= icon('check', 'sm') ?>Save</button>
          <button class="btn sm danger" type="submit" formaction="<?= esc(site_url('pricing/packages/' . $p['id'] . '/delete'), 'attr') ?>" formnovalidate data-confirm="Delete the <?= esc($p['name'], 'attr') ?> package?"><?= icon('trash', 'sm') ?>Delete</button></div>
      </form>
    <?php endforeach ?>
    <form class="card-b pkg-form" method="post" action="<?= esc(site_url('pricing/packages'), 'attr') ?>"><?= csrf_field() ?>
      <b>New package</b>
      <div class="form-grid">
        <label class="field">Name<input class="input" name="name" maxlength="80" required placeholder="e.g. Shop + Website bundle"></label>
        <label class="field">Price ৳ / month<input class="input" type="number" min="0" step="any" name="price" required></label>
        <label class="field span2">Description<input class="input" name="description" maxlength="255"></label>
      </div>
      <?= view('App\Modules\Pricing\Views\package_items', ['items' => $items, 'chosen' => []]) ?>
      <input type="hidden" name="is_active" value="1">
      <div class="row-add"><button class="btn primary" type="submit"><?= icon('plus', 'sm') ?>Create package</button></div>
    </form>
  </section>
</div>
