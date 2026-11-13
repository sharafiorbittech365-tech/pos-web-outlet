<?php
/**
 * Super Admin page layout: the shared admin shell with this app's navigation,
 * filtered by the signed-in staff member's permissions.
 *
 * @var string      $title
 * @var string      $content
 * @var string|null $actions extra topbar HTML
 * @var array|null  $back    [url, label]
 */
$session = service('adminSession');
$admin = $session->current();
$pastDue = (int) (cache('omnipos_sa_pastdue') ?? (function (): int {
    $n = db_connect()->table('subscriptions')->where('status', 'past_due')->countAllResults();
    cache()->save('omnipos_sa_pastdue', $n, 60);

    return $n;
})());
$nav = array_values(array_filter([
    ['overview', 'Overview', 'grid', 'overview.view'],
    ['tenants', 'Customers & tenants', 'store', 'tenants.view', $pastDue],
    ['subscriptions', 'Subscriptions', 'refresh', 'billing.view'],
    ['pricing', 'Pricing', 'tag', 'plans.manage'],
    ['plans', 'Plans (limits)', 'star', 'plans.manage'],
    ['modules', 'Modules & pricing', 'puzzle', 'plans.manage'],
    ['business-types', 'Business types', 'layers', 'plans.manage'],
    ['billing', 'Invoices & payments', 'receipt', 'billing.view'],
    ['servers', 'POS servers', 'server', 'servers.manage'],
    ['websites', 'Websites & domains', 'globe', 'tenants.view'],
    ['locations', 'Locations', 'map-pin', 'settings.manage'],
    ['medicines', 'Medicine database', 'pill', 'settings.manage'],
    ['announcements', 'Announcements', 'send', 'announcements.manage'],
    ['staff', 'Staff & roles', 'shield', 'admins.manage'],
    ['audit', 'Audit log', 'book', 'audit.view'],
    ['system', 'System', 'setup', 'settings.manage'],
    ['system/health', 'System health', 'gauge', 'settings.manage'],
], static fn (array $i): bool => $session->can($i[3])));
$nav = array_map(static fn (array $i): array => [$i[0], $i[1], $i[2], $i[4] ?? 0], $nav);

echo view('OmniPOS\Ui\Views\layouts\admin_shell', [
    'title' => $title ?? 'Admin',
    'content' => $content,
    'brandSub' => 'Platform admin',
    'nav' => $nav,
    'user' => $admin ? ['name' => $admin['name'], 'role' => $admin['role'], 'color' => $admin['color']] : null,
    'notice' => 'Workspaces are private. Staff manage accounts & billing only — no access to tenant sales, customers or products.',
    'actions' => $actions ?? '',
    'primary' => '#6D28D9',
    'logoutUrl' => site_url('logout'),
    'back' => $back ?? null,
]);
