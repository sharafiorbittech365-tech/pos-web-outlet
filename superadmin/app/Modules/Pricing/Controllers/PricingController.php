<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Controllers;

use App\Controllers\BaseController;
use App\Modules\Pricing\Services\PricingAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Exceptions\NotFoundException;

final class PricingController extends BaseController
{
    private function admin(): PricingAdmin
    {
        return new PricingAdmin($this->actor());
    }

    public function index(): string
    {
        $engine = service('pricing');
        $engine->ensureModuleItems();
        $db = db_connect();
        $cfg = $engine->config();
        // a worked example for the preview card: 1, 3, 5 and 10 outlets
        $example = [];
        foreach ([1, 3, 5, 10, 12] as $n) {
            $example[$n] = \OmniPOS\Core\Services\PricingEngine::compute($cfg, ['outlets' => $n, 'website' => true, 'multisite' => false, 'modules' => []]);
        }

        return $this->page('App\Modules\Pricing\Views\index', [
            'title' => 'Pricing', 'cfg' => $cfg, 'modules' => service('catalog')->modules(), 'example' => $example,
            'packages' => $db->table('pricing_packages')->orderBy('name')->get()->getResultArray(),
            'pkgUse' => array_column($db->table('tenant_pricing')->select('package_id, COUNT(*) AS n')->where('package_id IS NOT NULL')->groupBy('package_id')->get()->getResultArray(), 'n', 'package_id'),
            'custom' => (int) $db->table('tenant_pricing')->groupStart()->where('discount_type !=', 'none')->orWhere('package_id IS NOT NULL')->groupEnd()->countAllResults(),
        ]);
    }

    public function saveItems(): RedirectResponse
    {
        $n = $this->admin()->saveItems((array) $this->request->getPost('items'));

        return $this->done('Prices saved (' . $n . ' change' . ($n === 1 ? '' : 's') . ').', site_url('pricing'));
    }

    public function saveTiers(): RedirectResponse
    {
        $this->admin()->saveTiers((array) ($this->request->getPost('tiers') ?? []), (array) ($this->request->getPost('new') ?? []));

        return $this->done('Outlet discounts saved.', site_url('pricing#discounts'));
    }

    public function savePackage(?int $id = null): RedirectResponse
    {
        $in = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', (array) $this->request->getPost());
        $items = array_values(array_map('strval', (array) ($this->request->getPost('items') ?? [])));
        $this->admin()->savePackage($id, $in, $items);

        return $this->done($id === null ? 'Package created.' : 'Package saved.', site_url('pricing#packages'));
    }

    public function deletePackage(int $id): RedirectResponse
    {
        return $this->done($this->admin()->deletePackage($id) . ' deleted.', site_url('pricing#packages'));
    }

    public function customer(int $id): string
    {
        $db = db_connect();
        $t = $db->table('tenants')->select('id, name, owner_name, business_type')->where('id', $id)->where('deleted_at', null)->get()->getRowArray() ?? throw new NotFoundException('Customer not found.');
        $engine = service('pricing');
        $engine->ensureModuleItems();

        return $this->page('App\Modules\Pricing\Views\customer', [
            'title' => 'Pricing · ' . $t['name'], 'back' => [site_url('tenants/' . $id), $t['name']], 't' => $t, 'cfg' => $engine->config(), 'tc' => $engine->tenantConfig($id),
            'quote' => $engine->quote($id), 'packages' => $db->table('pricing_packages')->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function saveCustomer(int $id): RedirectResponse
    {
        $in = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', (array) $this->request->getPost());
        $over = array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', (array) ($this->request->getPost('prices') ?? []));
        $this->admin()->saveCustomer($id, $in, $over);

        return $this->done('Customer pricing saved.', site_url('pricing/customer/' . $id));
    }
}
