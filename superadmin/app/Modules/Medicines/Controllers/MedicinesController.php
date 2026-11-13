<?php

declare(strict_types=1);

namespace App\Modules\Medicines\Controllers;

use App\Controllers\BaseController;
use App\Modules\Medicines\Services\MedicineAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Services\MedicineImporter;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Super Admin › Medicine database (shared reference data for the Pharmacy module). */
final class MedicinesController extends BaseController
{
    public function index(): string|ResponseInterface
    {
        $table = DataTable::make('med', $this->request)
            ->from(MedicineAdmin::listQuery())
            ->columns([
                Column::make('brand', 'Brand')->sortBy('m.brand_name')->searchIn('m.brand_name', 'm.generic', 'm.manufacturer', 'm.brand_id', 'm.slug')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['brand_name']) . '</b>' . ($r['strength'] ? ' ' . esc($r['strength']) : '')
                        . '<br><small class="muted">' . esc(implode(' · ', array_filter([(string) $r['dosage_form'], 'brand_id ' . $r['brand_id'], (string) $r['type']]))) . '</small>')
                    ->csv(static fn (array $r): string => (string) $r['brand_name']),
                Column::make('generic', 'Generic')->sortBy('m.generic')->cell(static fn (array $r): string => esc((string) ($r['generic'] ?? '—')))->csv(static fn (array $r): string => (string) ($r['generic'] ?? '')),
                Column::make('mfr', 'Manufacturer')->sortBy('m.manufacturer')->cell(static fn (array $r): string => esc((string) ($r['manufacturer'] ?? '—')))->csv(static fn (array $r): string => (string) ($r['manufacturer'] ?? '')),
                Column::make('pack', 'Package')->cell(static fn (array $r): string => esc(trim(($r['package_container'] ?? '') . ' ' . ($r['package_size'] ?? '')) ?: '—'))
                    ->csv(static fn (array $r): string => trim(($r['package_container'] ?? '') . ' ' . ($r['package_size'] ?? ''))),
                Column::make('products', 'Shop products')->num()->sortBy('products')->cell(static fn (array $r): string => esc(number_format((int) $r['products'])))->csv(static fn (array $r): string => (string) (int) $r['products']),
                Column::make('on', 'Status')->sortBy('m.is_active')
                    ->cell(static fn (array $r): string => '<form class="inline" method="post" action="' . esc(site_url('medicines/' . $r['id'] . '/toggle'), 'attr') . '">' . csrf_field()
                        . '<button class="btn sm ' . ((int) $r['is_active'] ? 'primary' : '') . '" type="submit" title="' . ((int) $r['is_active'] ? 'Enabled — click to hide from shops' : 'Disabled — click to enable') . '">'
                        . icon((int) $r['is_active'] ? 'check' : 'pause', 'sm') . ((int) $r['is_active'] ? 'Enabled' : 'Disabled') . '</button></form>')
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'enabled' : 'disabled'),
            ])
            ->defaultSort('brand')
            ->placeholder('Brand, generic, manufacturer, brand_id…')
            ->unit('medicines')
            ->emptyHtml('<span class="muted">The medicine database is empty. Import the dataset (CSV or JSON) — no medicine data ships with ' . \OmniPOS\Ui\Brand::name() . '.</span>')
            ->exportable('medicines');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Medicines\Views\index', ['title' => 'Medicine database', 'table' => $table->render(), 'stats' => MedicineAdmin::stats(),
            'fields' => MedicineImporter::FIELDS, 'required' => MedicineImporter::REQUIRED, 'report' => session()->getFlashdata('medicine_report')]);
    }

    public function import(): RedirectResponse
    {
        $dry = (string) ($this->request->getPost('dry_run') ?? '0') === '1';
        $r = (new MedicineAdmin($this->actor()))->import($this->request->getFile('file'), $dry);
        session()->setFlashdata('medicine_report', $r);
        $msg = sprintf('%s%d row(s): %d new, %d updated, %d unchanged, %d with errors.', $dry ? 'Dry run (nothing saved) — ' : 'Imported — ', $r['rows'], $r['inserted'], $r['updated'], $r['unchanged'], $r['invalid']);

        return $this->done($msg, site_url('medicines'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $on = (new MedicineAdmin($this->actor()))->toggle($id);

        return $this->done($on ? 'Medicine enabled.' : 'Medicine disabled — shops no longer find it (linked products keep working).', site_url('medicines'));
    }

    /** An empty CSV template: the header row only (no medicine data is shipped). */
    public function template(): ResponseInterface
    {
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="medicines-template.csv"')
            ->setBody(implode(',', MedicineImporter::FIELDS) . "\r\n");
    }
}
