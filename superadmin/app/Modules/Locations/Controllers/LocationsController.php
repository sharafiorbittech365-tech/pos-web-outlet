<?php

declare(strict_types=1);

namespace App\Modules\Locations\Controllers;

use App\Controllers\BaseController;
use App\Modules\Locations\Services\LocationAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ValidationException;
use OmniPOS\Core\Services\LocationImporter;
use OmniPOS\Ui\Libraries\Column;
use OmniPOS\Ui\Libraries\DataTable;

/** Super Admin > Locations: countries, cities/districts, areas/thanas, dataset import. */
final class LocationsController extends BaseController
{
    private const COUNTRY_RULES = [
        'code' => 'required|exact_length[2]|alpha',
        'name' => 'required|max_length[80]',
        'name_bn' => 'permit_empty|max_length[80]',
        'phone_code' => 'permit_empty|max_length[8]',
        'city_label' => 'permit_empty|max_length[24]|alpha_space',
        'area_label' => 'permit_empty|max_length[24]|alpha_space',
    ];

    private function admin(): LocationAdmin
    {
        return new LocationAdmin($this->actor());
    }

    private static function toggleButton(string $url, bool $on): string
    {
        return '<form class="inline" method="post" action="' . esc($url, 'attr') . '">' . csrf_field()
            . '<button class="btn sm ' . ($on ? 'primary' : '') . '" type="submit" title="' . ($on ? 'Enabled — click to disable' : 'Disabled — click to enable') . '">'
            . icon($on ? 'check' : 'pause', 'sm') . ($on ? 'Enabled' : 'Disabled') . '</button></form>';
    }

    public function index(): string|ResponseInterface
    {
        $table = DataTable::make('loc', $this->request)
            ->from(LocationAdmin::countriesQuery())
            ->columns([
                Column::make('name', 'Country')->sortBy('k.name')->searchIn('k.name', 'k.code', 'k.name_bn')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('locations/' . $r['code']), 'attr') . '"><b>' . esc($r['name']) . '</b></a> <span class="tag">' . esc($r['code']) . '</span>'
                        . ($r['name_bn'] ? '<br><small class="muted">' . esc($r['name_bn']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => $r['code'] . ' ' . $r['name']),
                Column::make('levels', 'Levels')->cell(static fn (array $r): string => esc(($r['city_label'] ?: 'City') . ' › ' . ($r['area_label'] ?: 'Area')))
                    ->csv(static fn (array $r): string => ($r['city_label'] ?: 'City') . ' > ' . ($r['area_label'] ?: 'Area')),
                Column::make('cities', 'Cities / districts')->num()->sortBy('cities')
                    ->cell(static fn (array $r): string => (int) $r['cities'] === 0 ? '<span class="muted">No dataset</span>' : esc(number_format((int) $r['cities'])) . ((int) $r['cities_on'] < (int) $r['cities'] ? ' <small class="muted">(' . (int) $r['cities_on'] . ' on)</small>' : ''))
                    ->csv(static fn (array $r): string => (string) (int) $r['cities']),
                Column::make('areas', 'Areas / thanas')->num()->sortBy('areas')->cell(static fn (array $r): string => esc(number_format((int) $r['areas'])))
                    ->csv(static fn (array $r): string => (string) (int) $r['areas']),
                Column::make('on', 'Status')->sortBy('k.is_active')
                    ->cell(static fn (array $r): string => self::toggleButton(site_url('locations/' . $r['code'] . '/toggle'), (bool) $r['is_active']))
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'enabled' : 'disabled'),
                Column::make('act', '')->num()->nowrap()->noCsv()
                    ->cell(static fn (array $r): string => '<button class="btn sm" type="button" data-modal-open="cty-' . esc($r['code'], 'attr') . '">' . icon('edit', 'sm') . 'Edit</button>'),
            ])
            ->defaultSort('name')
            ->placeholder('Country name or code…')
            ->unit('countries')
            ->emptyHtml('<span class="muted">No countries yet. Add one, or import a dataset — the country is created with it.</span>')
            ->exportable('location-countries');
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Locations\Views\index', ['title' => 'Locations', 'table' => $table->render(), 'rows' => $table->result()['rows'],
            'columns' => LocationImporter::COLUMNS, 'result' => session()->getFlashdata('import_result')]);
    }

    public function country(string $code): string|ResponseInterface
    {
        $k = LocationAdmin::country($code);
        $table = DataTable::make('cities', $this->request)
            ->from(LocationAdmin::citiesQuery($k['code']))
            ->columns([
                Column::make('name', $k['city_label'] ?: 'City')->sortBy('c.name')->searchIn('c.name', 'c.name_bn', 'c.region', 'c.code')
                    ->cell(static fn (array $r): string => '<a class="link" href="' . esc(site_url('locations/cities/' . $r['id']), 'attr') . '"><b>' . esc($r['name']) . '</b></a>'
                        . ($r['name_bn'] ? '<br><small class="muted">' . esc($r['name_bn']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('kind', 'Kind')->sortBy('c.kind')->cell(static fn (array $r): string => tag_html(ucfirst((string) $r['kind'])))->csv(static fn (array $r): string => (string) $r['kind']),
                Column::make('region', 'Region')->sortBy('c.region')->cell(static fn (array $r): string => $r['region'] !== '' ? esc($r['region']) : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => (string) $r['region']),
                Column::make('code', 'Code')->sortBy('c.code')->cell(static fn (array $r): string => $r['code'] ? '<code>' . esc($r['code']) . '</code>' : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => (string) $r['code']),
                Column::make('areas', $k['area_label'] ?: 'Areas')->num()->sortBy('areas')
                    ->cell(static fn (array $r): string => esc(number_format((int) $r['areas'])) . ((int) $r['areas_on'] < (int) $r['areas'] ? ' <small class="muted">(' . (int) $r['areas_on'] . ' on)</small>' : ''))
                    ->csv(static fn (array $r): string => (string) (int) $r['areas']),
                Column::make('on', 'Status')->sortBy('c.is_active')
                    ->cell(static fn (array $r): string => self::toggleButton(site_url('locations/cities/' . $r['id'] . '/toggle'), (bool) $r['is_active']))
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'enabled' : 'disabled'),
            ])
            ->defaultSort('name')
            ->placeholder('Name, region or code…')
            ->unit('entries')
            ->emptyHtml('<span class="muted">No dataset for this country yet. Import a CSV or JSON file on the Locations page.</span>')
            ->exportable('locations-' . strtolower($k['code']));
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Locations\Views\country', ['title' => $k['name'] . ' · Locations', 'k' => $k, 'table' => $table->render(),
            'back' => [site_url('locations'), 'Locations']]);
    }

    public function city(int $id): string|ResponseInterface
    {
        $c = LocationAdmin::city($id);
        $table = DataTable::make('areas', $this->request)
            ->from(LocationAdmin::areasQuery($id))
            ->columns([
                Column::make('name', $c['area_label'] ?: 'Area')->sortBy('a.name')->searchIn('a.name', 'a.name_bn', 'a.code', 'a.postal_code')
                    ->cell(static fn (array $r): string => '<b>' . esc($r['name']) . '</b>' . ($r['name_bn'] ? '<br><small class="muted">' . esc($r['name_bn']) . '</small>' : ''))
                    ->csv(static fn (array $r): string => (string) $r['name']),
                Column::make('kind', 'Kind')->sortBy('a.kind')->cell(static fn (array $r): string => tag_html(ucfirst((string) $r['kind'])))->csv(static fn (array $r): string => (string) $r['kind']),
                Column::make('postal', 'Postal code')->sortBy('a.postal_code')->cell(static fn (array $r): string => esc((string) ($r['postal_code'] ?? '—')))
                    ->csv(static fn (array $r): string => (string) $r['postal_code']),
                Column::make('code', 'Code')->sortBy('a.code')->cell(static fn (array $r): string => $r['code'] ? '<code>' . esc($r['code']) . '</code>' : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => (string) $r['code']),
                Column::make('pin', 'Centre')->cell(static fn (array $r): string => $r['lat'] !== null ? esc(sprintf('%.5F, %.5F', $r['lat'], $r['lng'])) : '<span class="muted">—</span>')
                    ->csv(static fn (array $r): string => $r['lat'] !== null ? $r['lat'] . ',' . $r['lng'] : ''),
                Column::make('on', 'Status')->sortBy('a.is_active')
                    ->cell(static fn (array $r): string => self::toggleButton(site_url('locations/areas/' . $r['id'] . '/toggle'), (bool) $r['is_active']))
                    ->csv(static fn (array $r): string => (int) $r['is_active'] ? 'enabled' : 'disabled'),
            ])
            ->defaultSort('name')
            ->placeholder('Name, code or postal code…')
            ->unit('areas')
            ->emptyHtml('<span class="muted">No areas for this entry.</span>')
            ->exportable('locations-city-' . $id);
        if ($csv = $table->csvResponse($this->response)) {
            return $csv;
        }

        return $this->page('App\Modules\Locations\Views\city', ['title' => $c['name'] . ' · ' . $c['country_name'], 'c' => $c, 'table' => $table->render(),
            'back' => [site_url('locations/' . $c['country_code']), $c['country_name']]]);
    }

    public function createCountry(): RedirectResponse
    {
        $code = $this->admin()->saveCountry($this->validated(self::COUNTRY_RULES), true);

        return $this->done('Country added.', site_url('locations/' . $code));
    }

    public function updateCountry(string $code): RedirectResponse
    {
        $in = $this->validated(self::COUNTRY_RULES);
        if (strtoupper($in['code']) !== strtoupper($code)) {
            throw new ValidationException('The country code cannot be changed.', ['code' => 'Add a new country instead.']);
        }
        $this->admin()->saveCountry($in, false);

        return $this->done('Country saved.', site_url('locations'));
    }

    public function toggleCountry(string $code): RedirectResponse
    {
        $on = $this->admin()->toggle('country', LocationAdmin::country($code)['code']);

        return $this->done($on ? 'Country enabled.' : 'Country disabled: it is no longer offered at checkout.', site_url('locations'));
    }

    public function toggleCity(int $id): RedirectResponse
    {
        $on = $this->admin()->toggle('city', $id);
        $c = LocationAdmin::city($id);

        return $this->done($on ? $c['name'] . ' enabled.' : $c['name'] . ' disabled.', site_url('locations/' . $c['country_code']));
    }

    public function toggleArea(int $id): RedirectResponse
    {
        $on = $this->admin()->toggle('area', $id);
        $cityId = (int) db_connect()->table('areas')->select('city_id')->where('id', $id)->get()->getRow('city_id');

        return $this->done($on ? 'Area enabled.' : 'Area disabled.', site_url('locations/cities/' . $cityId));
    }

    public function import(): RedirectResponse
    {
        $in = $this->validated([
            'code' => 'required|exact_length[2]|alpha',
            'name' => 'permit_empty|max_length[80]',
            'city_label' => 'permit_empty|max_length[24]|alpha_space',
            'area_label' => 'permit_empty|max_length[24]|alpha_space',
            'dry_run' => 'permit_empty|in_list[0,1]',
        ]);
        $dry = ($in['dry_run'] ?? '0') === '1';
        unset($in['dry_run']);
        $s = $this->admin()->import($in, $this->request->getFile('file'), $dry);
        session()->setFlashdata('import_result', $s);
        $msg = sprintf('%s%s: %d row(s) — cities %d new, %d updated · areas %d new, %d updated · %d unchanged · %d duplicate(s) skipped.',
            $s['dry_run'] ? 'Dry run (nothing saved) — ' : 'Imported ', $s['country'], $s['rows'], $s['cities_created'], $s['cities_updated'], $s['areas_created'], $s['areas_updated'], $s['unchanged'], $s['duplicates']);

        return $this->done($msg, site_url($s['dry_run'] ? 'locations' : 'locations/' . $s['country']));
    }

    /** An empty CSV template: the header row only (no location data is shipped). */
    public function template(): ResponseInterface
    {
        return $this->response->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="locations-template.csv"')
            ->setBody(implode(',', LocationImporter::COLUMNS) . "\r\n");
    }
}
