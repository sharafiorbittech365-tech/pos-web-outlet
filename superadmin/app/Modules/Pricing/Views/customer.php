<?php
/**
 * Custom pricing for one customer: discount (percent / fixed), package, domain, custom item prices.
 *
 * @var array<string, mixed> $t       tenant (id, name, owner_name, business_type)
 * @var array                $cfg     PricingEngine::config()
 * @var array                $tc      PricingEngine::tenantConfig()
 * @var array                $quote   PricingEngine::quote() — the bill as it stands
 * @var list<array>          $packages
 */
helper('ui');
$tk = static fn ($n): string => money($n, ['decimals' => 0]);
$id = (int) $t['id'];
$b = $quote['breakdown'];
?>
<div class="page">
  <div class="page-h"><div><h2><?= esc($t['name']) ?></h2><p class="page-sub">Custom pricing for this customer. Leave a field empty to use the standard price / no discount.</p></div></div>

  <div class="sub-top">
    <section class="card"><div class="card-h"><h3>Current monthly price</h3><small class="muted"><?= (int) $quote['outlets'] ?> outlet<?= (int) $quote['outlets'] === 1 ? '' : 's' ?></small></div><div class="card-b">
      <div class="bill-sum">
        <?php foreach ($quote['lines'] as $l): ?><div class="bs-r"><span><?= esc($l['label']) ?></span><b><?= $l['monthly'] < 0 ? '−' : '' ?><?= esc($tk(abs($l['monthly']))) ?></b></div><?php endforeach ?>
        <div class="bs-r tot"><span>Final monthly price</span><b><?= esc($tk($quote['monthly'])) ?></b></div>
        <?php if ($quote['domain']['cost'] > 0): ?><div class="bs-r muted"><span>Domain <?= esc($quote['domain']['name']) ?> (charged separately)</span><b><?= esc($tk($quote['domain']['cost'])) ?></b></div><?php endif ?>
      </div>
      <small class="muted">Modules and outlets come from what the customer has switched on (see their Modules tab).</small>
    </div></section>
  </div>

  <form method="post" action="<?= esc(site_url('pricing/customer/' . $id), 'attr') ?>"><?= csrf_field() ?>
    <section class="card"><div class="card-h"><h3>Customer discount</h3></div><div class="card-b">
      <div class="form-grid">
        <label class="field">Type<select class="select" name="discount_type">
          <?php foreach (['none' => 'No discount', 'percent' => 'Percentage (%)', 'fixed' => 'Fixed amount (৳ / month)'] as $k => $l): ?><option value="<?= $k ?>" <?= selected($tc['discount_type'], $k) ?>><?= esc($l) ?></option><?php endforeach ?></select></label>
        <label class="field">Value<input class="input" type="number" min="0" step="any" name="discount_value" value="<?= $tc['discount_value'] > 0 ? esc((string) $tc['discount_value'], 'attr') : '' ?>"><?= field_error('discount_value') ?></label>
        <label class="field span2">Note (internal)<input class="input" name="discount_note" maxlength="160" value="<?= esc((string) $tc['discount_note'], 'attr') ?>" placeholder="e.g. Launch partner, agreed 12 Nov"></label>
      </div>
      <small class="muted">Applied after the outlet discount, to the whole monthly bill. A fixed amount never takes the bill below zero.</small>
    </div></section>

    <section class="card"><div class="card-h"><h3>Package &amp; domain</h3></div><div class="card-b">
      <div class="form-grid">
        <label class="field">Custom package<select class="select" name="package_id"><option value="">None</option>
          <?php foreach ($packages as $p): if (! (bool) $p['is_active'] && (int) ($tc['package']['id'] ?? 0) !== (int) $p['id']) { continue; } ?><option value="<?= (int) $p['id'] ?>" <?= selected($tc['package']['id'] ?? 0, $p['id']) ?>><?= esc($p['name']) ?> · <?= esc($tk($p['price'])) ?>/mo</option><?php endforeach ?></select>
          <?= field_error('package_id') ?></label>
        <span></span>
        <label class="field">Domain name<input class="input" name="domain_name" maxlength="190" value="<?= esc((string) $tc['domain_name'], 'attr') ?>" placeholder="example.com"></label>
        <label class="field">Domain registration cost ৳<input class="input" type="number" min="0" step="any" name="domain_cost" value="<?= $tc['domain_cost'] === null ? '' : esc((string) $tc['domain_cost'], 'attr') ?>" placeholder="standard: <?= esc($tk($cfg['items']['domain.reg']['price'] ?? 0), 'attr') ?>"><?= field_error('domain_cost') ?></label>
      </div>
      <small class="muted">A package replaces the separate charges of the website / modules it includes and switches those modules on for the customer. The domain cost is charged separately, not in the monthly price.</small>
    </div></section>

    <section class="card"><div class="card-h"><h3>Custom prices</h3><small class="muted">Replace the standard price of one item for this customer only</small></div>
      <div class="table-wrap"><table class="tbl stack"><thead><tr><th>Item</th><th class="num">Standard ৳</th><th class="num">This customer ৳</th></tr></thead><tbody>
        <?php foreach ($cfg['items'] as $i): if ($i['kind'] === 'domain' || ($i['kind'] === 'module' && (bool) $i['in_base'])) { continue; } ?>
          <tr><td><?= esc($i['label']) ?><?= (bool) $i['is_enabled'] ? '' : ' <span class="tag">Not offered</span>' ?></td><td class="num" data-label="Standard ৳"><?= esc($tk($i['price'])) ?></td>
            <td class="num" data-label="This customer ৳"><input class="input" type="number" min="0" step="any" name="prices[<?= esc($i['code'], 'attr') ?>]" value="<?= isset($tc['overrides'][$i['code']]) ? esc((string) $tc['overrides'][$i['code']], 'attr') : '' ?>" placeholder="standard" style="max-width:8rem" aria-label="<?= esc($i['label'], 'attr') ?>"></td></tr>
        <?php endforeach ?>
      </tbody></table></div>
      <div class="card-b"><?= field_error('prices') ?><div class="row-add"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?>Save customer pricing</button>
        <a class="btn" href="<?= esc(site_url('tenants/' . $id), 'attr') ?>">Back to customer</a></div></div>
    </section>
  </form>
</div>
