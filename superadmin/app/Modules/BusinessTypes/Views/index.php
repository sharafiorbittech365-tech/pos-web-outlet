<?php
/**
 * @var list<array<string, mixed>>                         $types business_types rows, setup decoded
 * @var array<string, array{tenants: int, outlets: int}>   $usage
 */
helper('ui');
use OmniPOS\Core\Services\BusinessTypeSetup as Setup;

$methodTone = ['shop' => '', 'menu' => 'warn', 'booking' => 'ok', 'service' => 'info'];
?>
<div class="page">
  <div class="page-h"><p class="page-sub">Each business type has its own working method: how its storefront sells, which online requests it takes, and the setup a new outlet starts with (POS switches, order types and delivery charges, VAT, payments, labels, receipt and hours). Existing outlets keep their own settings; owners can change them in POS › Settings.</p></div>
  <section class="card"><div class="table-wrap"><table class="tbl adm-tbl"><thead><tr><th>Business type</th><th>Method</th><th>Order types</th><th>VAT</th><th>Payments</th><th class="num">Accounts</th><th></th></tr></thead><tbody>
    <?php foreach ($types as $t): $s = $t['setup']; $u = $usage[$t['code']] ?? ['tenants' => 0, 'outlets' => 0]; $tax = (array) ($s['tax'] ?? []); ?>
      <tr><td data-l="Type"><span class="mp-mod"><?= sym_icon($t['icon']) ?><span><b><?= esc($t['name']) ?></b><small class="muted"><?= esc((string) ($t['name_bn'] ?? '')) ?> · <code><?= esc($t['code']) ?></code><?= $t['is_active'] ? '' : ' · ' . tag_html('Not offered', 'warn') ?></small></span></span></td>
        <td data-l="Method"><?= tag_html(Setup::STORE_KINDS[$t['store_kind']] ?? $t['store_kind'], $methodTone[$t['store_kind']] ?? '') ?><?php if ($t['booking_kind']): ?><br><small class="muted">Online: <?= esc(Setup::BOOKING_KINDS[$t['booking_kind']] ?? $t['booking_kind']) ?></small><?php endif ?></td>
        <td data-l="Order types"><small><?= esc(implode(' · ', (array) ($s['features']['orderTypes'] ?? []))) ?: '<span class="muted">—</span>' ?></small></td>
        <td data-l="VAT"><?= esc(rtrim(rtrim(number_format((float) ($tax['rate'] ?? 0), 2), '0'), '.')) ?>% <small class="muted"><?= ! empty($tax['inclusive']) ? 'incl.' : '+ on top' ?></small></td>
        <td data-l="Payments"><small><?= esc(implode(', ', array_map(static fn (string $p): string => explode(' (', Setup::PAYMENTS[$p] ?? $p)[0], (array) ($s['payments'] ?? [])))) ?></small></td>
        <td class="num" data-l="Accounts"><?= $u['tenants'] ?> <small class="muted">/ <?= $u['outlets'] ?> outlet<?= $u['outlets'] === 1 ? '' : 's' ?></small></td>
        <td class="num"><a class="btn sm" href="<?= esc(site_url('business-types/' . $t['code']), 'attr') ?>"><?= icon('edit', 'sm') ?>Set up</a></td></tr>
    <?php endforeach ?>
  </tbody></table></div></section>
</div>
