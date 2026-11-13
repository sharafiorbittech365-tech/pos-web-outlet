<?php

declare(strict_types=1);

namespace App\Modules\Settings\Controllers;

use App\Modules\BackOffice\Support\BackOfficeController;
use App\Modules\BackOffice\Support\Icons;
use App\Modules\Settings\Services\OutletAdmin;
use App\Modules\Settings\Services\ProductImport;
use App\Libraries\Mailer;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SmtpSettings;
use App\Modules\Website\Services\WebsiteService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Services\OrderService;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

final class SettingsController extends BackOfficeController
{
    public function index(): string
    {
        $svc = new SettingsService();
        $b = $svc->business();
        $web = $this->s()->moduleOn('website') ? (new WebsiteService())->site() : null;

        return $this->page('App\Modules\Settings\Views\index', [
            'title' => 'Setup',
            'b' => $b,
            'web' => $web,
            'features' => SettingsService::availableFeatures(),
            'typeWords' => \OmniPOS\Core\Services\BusinessTypeSetup::labels((string) $b['business_type']),
            'payments' => SettingsService::PAYMENTS,
            'formats' => SettingsService::PRINT_FORMATS,
            'labels' => SettingsService::LABELS,
            'icons' => Icons::choices(),
            'sample' => OrderService::docNo($b, 42),
            'typeCharges' => SettingsService::typeChargesText((array) ($b['settings']['typeCharges'] ?? [])),
            'outlets' => $svc->outletCounts(),
        ]);
    }

    public function save(): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        (new SettingsService())->save($in);
        SmtpSettings::save($in);
        // the Website tab belongs to the same form (only sent when the website module is on)
        if (! empty($in['web_present']) && $this->s()->moduleOn('website')) {
            $files = [];
            foreach (['logo', 'favicon', 'hero'] as $slot) {
                $f = $this->request->getFile('web_' . $slot . '_file');
                if ($f !== null && $f->isValid() && $f->getError() !== UPLOAD_ERR_NO_FILE) {
                    $files[$slot] = $f;
                }
            }
            (new WebsiteService())->saveProfile($in, $files);
        }

        $tab = preg_replace('/[^a-z]/', '', (string) $this->request->getPost('_tab'));

        return $this->done('Setup saved.', site_url('settings') . ($tab !== '' ? '#' . $tab : ''));
    }

    /** POST settings/smtp-test — sends a test message with the values in the form (nothing is saved). */
    public function smtpTest(): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $to = trim((string) ($in['smtp_test_to'] ?? ''));
        $b = $this->s()->business();
        $cfg = SmtpSettings::fromForm($in, $b, false);
        $r = Mailer::send($to !== '' ? $to : (string) $cfg['from_email'], 'Test email from ' . $b['name'], '<p>Your email settings work. Invoices can now be sent from the POS.</p>', null, $cfg);
        $back = site_url('settings') . '#email';
        if (! $r['ok']) {
            return redirect()->to($back)->with('error', 'Test email failed: ' . $r['error']);
        }

        return $this->done('Test email sent to ' . ($to !== '' ? $to : $cfg['from_email']) . '. Save the settings to start using it.', $back);
    }

    public function outlets(): string|ResponseInterface
    {
        $svc = new SettingsService();
        $current = $this->s()->businessId();
        $table = DataTable::make('outlets', $this->request)->from($svc->outletsQuery())
            ->columns([
                Column::make('name', 'Outlet')->sortBy('b.name')->searchIn('b.name', 'b.area', 'b.city')
                    ->cell(static fn (array $r): string => sym_icon($r['type_icon']) . ' <b>' . esc($r['name']) . '</b>' . ((int) $r['id'] === $current ? ' <span class="tag primary">Open now</span>' : '') . '<br><small class="muted">' . esc($r['type_name']) . ' · ' . esc(implode(', ', array_filter([$r['area'], $r['city']]))) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('products', 'Products')->num()->sortBy('products')->cell(static fn (array $r): string => (string) (int) $r['products']),
                Column::make('users', 'Team')->num()->sortBy('users')->cell(static fn (array $r): string => (string) (int) $r['users']),
                Column::make('currency', 'Currency')->cell(static fn (array $r): string => esc($r['currency_code'])),
                Column::make('since', 'Since')->nowrap()->sortBy('b.created_at')->cell(static fn (array $r): string => esc(date_str($r['created_at']))),
                Column::make('status', 'Status')->sortBy('b.is_active')->cell(static fn (array $r): string => $r['is_active'] ? tag_html('Open', 'ok') : tag_html('Closed')),
                Column::make('act', '')->cls('num nw')->noCsv()->cell(static fn (array $r): string => '<button class="btn sm" type="button" data-modal-open="outlet-' . (int) $r['id'] . '" aria-label="Edit details of ' . esc($r['name'], 'attr') . '">' . icon('pencil', 'sm') . 'Details</button> '
                    . ((int) $r['id'] === $current ? '<a class="btn sm" href="' . esc(site_url('settings'), 'attr') . '">' . icon('edit', 'sm') . 'Setup</a>'
                    : ($r['is_active']
                        ? '<a class="btn sm primary" href="' . esc(site_url('outlets'), 'attr') . '">' . icon('swap', 'sm') . 'Switch</a> <form class="inline" method="post" action="' . esc(site_url('settings/outlets/' . $r['id'] . '/close'), 'attr') . '" data-confirm="Close ' . esc($r['name'], 'attr') . '? Its data is kept.">' . csrf_field() . '<button class="btn sm danger" type="submit">Close</button></form>'
                        : '<form class="inline" method="post" action="' . esc(site_url('settings/outlets/' . $r['id'] . '/open'), 'attr') . '">' . csrf_field() . '<button class="btn sm" type="submit">Re-open</button></form>'))),
            ])->defaultSort('since')->placeholder('Outlet, area…')->unit('outlets');

        $admin = new OutletAdmin();
        $templates = $svc->templates();

        return $this->page('App\Modules\Settings\Views\outlets', ['title' => 'Outlets', 'table' => $table->render(), 'counts' => $svc->outletCounts(), 'templates' => $templates,
            'plan' => $this->s()->access()->plan['name'] ?? '', 'outletRows' => $admin->outlets(), 'teamAccess' => $admin->access(),
            'recommended' => $admin->recommendations(array_keys($templates)), 'trial' => $this->s()->access()->status === 'trial', 'myOutlets' => $this->s()->outlets()]);
    }

    public function addOutlet(): RedirectResponse
    {
        $in = (array) $this->request->getPost();
        $bid = (new SettingsService())->addOutlet($in);
        $rec = (new OutletAdmin())->applyRecommended((string) ($in['type'] ?? ''));
        $names = static fn (array $codes): string => implode(', ', array_map(static fn (string $c): string => (string) (service('catalog')->modules()[$c]['name'] ?? $c), $codes));
        $copy = ($in['products'] ?? '') === 'copy';
        $msg = $copy ? 'Outlet created. Now choose the products to copy into it and set their opening stock.' : 'Outlet created from the template. Switch to it from the outlet menu.';
        if ($rec['added'] !== []) {
            $msg .= ' Recommended modules switched on for your trial: ' . $names($rec['added']) . ' (remove them any time under Subscription).';
        }
        if ($rec['suggested'] !== []) {
            $msg .= ' Recommended for this business type: ' . $names($rec['suggested']) . ' (Subscription > Modules).';
        }
        if ($copy) {
            return $this->done($msg, site_url('settings/outlets/' . $bid . '/import') . '?from=' . (int) ($in['copy_from'] ?? 0));
        }

        return $this->done($msg, site_url('settings/outlets'));
    }

    /** Setup › Outlets › Import products: choose an outlet of the same business type and the products to copy. */
    public function importProducts(int $id): string
    {
        $svc = new ProductImport();
        $target = $svc->outlet($id);
        $sources = $svc->sources($id);
        $from = $this->getInt('from');
        if ($from === 0 && count($sources) === 1) {
            $from = (int) $sources[0]['id'];
        }
        $source = null;
        foreach ($sources as $s) {
            if ((int) $s['id'] === $from) {
                $source = $s;
            }
        }

        $cur = $this->cur();
        if ($source !== null) {
            $b = db_connect()->table('businesses')->select('currency_symbol, currency_decimals')->where('id', (int) $source['id'])->get()->getRowArray() ?? [];
            $cur = ['symbol' => (string) ($b['currency_symbol'] ?? $cur['symbol']), 'decimals' => (int) ($b['currency_decimals'] ?? $cur['decimals']), 'position' => 'before'];
        }

        return $this->page('App\Modules\Settings\Views\import', [
            'title' => 'Import products', 'target' => $target, 'sources' => $sources, 'source' => $source, 'cur' => $cur,
            'products' => $source !== null ? $svc->catalogue((int) $source['id'], $id) : [], 'max' => ProductImport::MAX,
        ]);
    }

    public function runImport(int $id): RedirectResponse
    {
        $from = (int) ($this->request->getPost('from') ?? 0);
        // the page sends one JSON field (no PHP max_input_vars limit on big catalogues); without JS: ids[] + stock[id] / vstock[id][variant]
        $items = json_decode((string) ($this->request->getPost('items') ?? ''), true);
        if (! is_array($items)) {
            $items = [];
            $stock = $this->postArray('stock');
            $vstock = $this->postArray('vstock');
            foreach ($this->postArray('ids') as $pid) {
                $items[(int) $pid] = ['q' => $stock[$pid] ?? 0, 'v' => is_array($vstock[$pid] ?? null) ? $vstock[$pid] : []];
            }
        }
        $res = (new ProductImport())->import($from, $id, $items);
        $target = (new ProductImport())->outlet($id);
        $msg = $res['imported'] . ' product' . ($res['imported'] === 1 ? '' : 's') . ' imported into ' . $target['name']
            . ($res['categories'] > 0 ? ', with ' . $res['categories'] . ' new categor' . ($res['categories'] === 1 ? 'y' : 'ies') : '')
            . ($res['stock'] > 0 ? ', opening stock recorded for ' . $res['stock'] . ' line' . ($res['stock'] === 1 ? '' : 's') : '') . '.'
            . ($res['skipped'] > 0 ? ' ' . $res['skipped'] . ' skipped (already in this outlet, or no longer available).' : '');

        return $this->done($msg, site_url('settings/outlets'));
    }

    public function updateOutlet(int $id): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[160]', 'phone' => 'permit_empty|max_length[32]', 'area' => 'permit_empty|max_length[64]',
            'city' => 'permit_empty|max_length[64]', 'address' => 'permit_empty|max_length[255]',
        ]);
        (new OutletAdmin())->update($id, $data);

        return $this->done('Outlet details saved.', site_url('settings/outlets'));
    }

    public function closeOutlet(int $id): RedirectResponse
    {
        (new SettingsService())->setOutletActive($id, false);

        return $this->done('Outlet closed — its data is kept.', site_url('settings/outlets'));
    }

    public function openOutlet(int $id): RedirectResponse
    {
        (new SettingsService())->setOutletActive($id, true);

        return $this->done('Outlet re-opened.', site_url('settings/outlets'));
    }
}
