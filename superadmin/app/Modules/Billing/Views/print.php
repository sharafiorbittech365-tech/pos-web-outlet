<?php
/**
 * Platform invoice, A4 print view (standalone page).
 *
 * @var array<string, mixed>                          $inv
 * @var array{name: string, email: string, phone: string} $platform
 */
helper('ui');
$tk = static fn ($n): string => money($n, ['decimals' => 2]);
$paid = array_sum(array_map(static fn (array $p): float => (float) $p['amount'], $inv['payments']));
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Invoice <?= esc($inv['number']) ?> · <?= esc($platform['name']) ?></title>
  <link rel="icon" href="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" type="image/svg+xml">
  <style>
    @page { size: A4; margin: 16mm; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f1f5f9; color: #0f172a; font: 14px/1.5 Inter, system-ui, -apple-system, "Segoe UI", sans-serif; }
    .sheet { width: 210mm; min-height: 297mm; margin: 24px auto; background: #fff; padding: 18mm 16mm; box-shadow: 0 10px 40px -20px rgba(15, 23, 42, .4); }
    .top { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; border-bottom: 2px solid #6d28d9; padding-bottom: 14px; }
    .brand { display: flex; gap: 10px; align-items: center; }
    .brand img { width: 40px; height: 40px; }
    .brand b { font-size: 20px; display: block; }
    .muted { color: #64748b; }
    h1 { margin: 0; font-size: 26px; letter-spacing: .04em; color: #6d28d9; text-align: right; }
    .meta { text-align: right; }
    .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 22px 0; }
    .cols h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 9px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    th { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
    .num { text-align: right; white-space: nowrap; }
    .sum { width: 50%; margin-left: auto; }
    .sum td { border: 0; padding: 5px 8px; }
    .sum tr.total td { border-top: 2px solid #0f172a; font-weight: 700; font-size: 16px; }
    .stamp { display: inline-block; padding: 4px 12px; border-radius: 999px; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
    .stamp.paid { background: #dcfce7; color: #15803d; } .stamp.open { background: #fef3c7; color: #b45309; } .stamp.void { background: #e2e8f0; color: #475569; }
    .foot { margin-top: 36px; font-size: 12px; }
    .bar { width: 210mm; margin: 16px auto 0; display: flex; justify-content: flex-end; gap: 8px; }
    .bar button { font: inherit; font-weight: 600; padding: 8px 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; }
    .bar button.primary { background: #6d28d9; border-color: #6d28d9; color: #fff; }
    @media print { body { background: #fff; } .sheet { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; } .bar { display: none; } }
  </style>
</head>
<body>
  <div class="bar"><button type="button" onclick="window.close()">Close</button><button class="primary" type="button" onclick="window.print()">Print / Save PDF</button></div>
  <main class="sheet">
    <div class="top">
      <div class="brand"><img src="<?= esc(asset_url('img/icon.svg'), 'attr') ?>" alt=""><div><b><?= esc($platform['name']) ?></b><span class="muted"><?= esc($platform['email']) ?><?= $platform['phone'] !== '' ? ' · ' . esc($platform['phone']) : '' ?></span></div></div>
      <div class="meta"><h1>INVOICE</h1><b><?= esc($inv['number']) ?></b><br><span class="stamp <?= esc($inv['status'], 'attr') ?>"><?= esc($inv['status'] === 'open' && $inv['due_at'] < date('Y-m-d H:i:s') ? 'overdue' : $inv['status']) ?></span></div>
    </div>
    <div class="cols">
      <div><h4>Billed to</h4><b><?= esc($inv['tenant_name']) ?></b><br><?= esc($inv['owner_name']) ?><br><span class="muted"><?= esc($inv['owner_email']) ?><?= $inv['owner_phone'] ? ' · ' . esc($inv['owner_phone']) : '' ?></span><br><span class="muted">Account <?= esc($inv['tenant_code']) ?></span></div>
      <div class="meta"><h4>Details</h4>Issued <?= esc(date_str($inv['issued_at'])) ?><br>Due <?= esc(date_str($inv['due_at'])) ?><?php if ($inv['paid_at']): ?><br>Paid <?= esc(date_str($inv['paid_at'])) ?><?php endif ?></div>
    </div>
    <table>
      <thead><tr><th>Description</th><th>Period</th><th class="num">Amount</th></tr></thead>
      <tbody>
      <?php if ($lines ?? []): ?>
        <tr><td colspan="3"><b><?= esc($inv['plan_name']) ?> plan</b> · <?= esc($inv['billing_cycle']) ?> subscription · <?= esc(service('catalog')->typeName((string) $inv['business_type'])) ?> pricing<?= $inv['note'] ? ' · <span class="muted">' . esc($inv['note']) . '</span>' : '' ?></td></tr>
        <?php foreach ($lines as $l): ?>
          <tr><td><?= esc($l['label']) ?><?= $l['monthly'] !== null && $l['kind'] !== 'plan' ? '<br><span class="muted">' . esc($tk(abs((float) $l['monthly']))) . ' / month</span>' : '' ?></td>
            <td><?= $l['period_from'] ? esc(date_str($l['period_from'])) . ' – ' . esc(date_str($l['period_to'])) : '' ?></td><td class="num"><?= esc($tk($l['amount'])) ?></td></tr>
        <?php endforeach ?>
      <?php else: ?>
      <tr>
        <td><b><?= esc($inv['plan_name']) ?> plan</b> · <?= esc($inv['billing_cycle']) ?> subscription<?= $inv['note'] ? '<br><span class="muted">' . esc($inv['note']) . '</span>' : '' ?><br><span class="muted"><?= esc(service('catalog')->typeName((string) $inv['business_type'])) ?> pricing, including add-on modules</span></td>
        <td><?= esc(date_str($inv['period_from'])) ?> – <?= esc(date_str($inv['period_to'])) ?></td>
        <td class="num"><?= esc($tk($inv['amount'])) ?></td>
      </tr>
      <?php endif ?></tbody>
    </table>
    <table class="sum">
      <tr><td>Subtotal</td><td class="num"><?= esc($tk($inv['amount'])) ?></td></tr>
      <tr><td>VAT</td><td class="num"><?= esc($tk($inv['vat'])) ?></td></tr>
      <tr class="total"><td>Total</td><td class="num"><?= esc($tk($inv['total'])) ?></td></tr>
      <?php if ($paid > 0): ?><tr><td>Paid</td><td class="num"><?= esc($tk($paid)) ?></td></tr><?php endif ?>
    </table>
    <?php if ($inv['payments'] !== []): ?>
      <table>
        <thead><tr><th>Payment</th><th>Reference</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($inv['payments'] as $p): ?>
            <tr><td><?= esc($p['method']) ?> · <?= esc(datetime_str($p['paid_at'])) ?></td><td><?= esc($p['reference'] ?? '—') ?></td><td class="num"><?= esc($tk($p['amount'])) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    <?php endif ?>
    <p class="foot muted">Thank you for using <?= esc($platform['name']) ?>. Questions about this invoice: <?= esc($platform['email']) ?>. This is a computer-generated invoice and needs no signature.</p>
  </main>
</body>
</html>
