<?php

declare(strict_types=1);

namespace App\Modules\System\Controllers;

use App\Controllers\BaseController;
use App\Modules\System\Services\GatewayAdmin;
use App\Modules\System\Services\PlatformSettings;
use CodeIgniter\HTTP\RedirectResponse;

final class SystemController extends BaseController
{
    public function index(): string
    {
        return $this->page('App\Modules\System\Views\index', [
            'title' => 'System',
            'settings' => PlatformSettings::current(),
            'plans' => array_values(service('catalog')->plans()),
            'info' => PlatformSettings::info(),
            'brand' => ['stored' => \OmniPOS\Ui\Brand::stored(), 'name' => \OmniPOS\Ui\Brand::name(), 'baseUrl' => \OmniPOS\Ui\Brand::baseUrl(), 'siteHost' => \OmniPOS\Ui\Brand::siteHost(),
                'company' => \OmniPOS\Ui\Brand::company(), 'address' => \OmniPOS\Ui\Brand::address(), 'writable' => \OmniPOS\Ui\Brand::storePath() !== null],
            'gateway' => service('storefrontGateway')->status(),
            'newToken' => session()->getFlashdata('gatewayToken'),
        ]);
    }

    /** New storefront gateway token, shown once on the System page. */
    public function rotateGateway(): RedirectResponse
    {
        $data = $this->validated(['grace' => 'required|is_natural']);
        $token = (new GatewayAdmin($this->actor()))->rotate((int) $data['grace']);
        session()->setFlashdata('gatewayToken', $token);

        return $this->done('New storefront gateway token generated — copy it now, it is shown once.', site_url('system') . '#gateway');
    }

    public function disableGateway(): RedirectResponse
    {
        (new GatewayAdmin($this->actor()))->disable();

        return $this->done('Storefront gateway disabled — hosted storefronts are offline until a new token is generated.', site_url('system') . '#gateway');
    }

    public function saveBrand(): RedirectResponse
    {
        $this->validated([
            'brandName' => 'permit_empty|max_length[60]', 'baseUrl' => 'permit_empty|max_length[190]', 'siteHost' => 'permit_empty|max_length[190]',
            'company' => 'permit_empty|max_length[120]', 'address' => 'permit_empty|max_length[255]',
        ]);
        (new PlatformSettings($this->actor()))->saveBrand(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', (array) $this->request->getPost()));

        return $this->done('Brand saved — every app uses the new name and domain on its next page load.', site_url('system') . '#brand');
    }

    public function save(): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[80]',
            'supportEmail' => 'required|valid_email|max_length[160]',
            'supportPhone' => 'permit_empty|max_length[32]',
            'trialDays' => 'required|is_natural|less_than_equal_to[90]',
            'trialPlan' => 'required|alpha_dash|max_length[32]',
            'graceDays' => 'required|is_natural|less_than_equal_to[60]',
            'vat' => 'required|decimal|greater_than_equal_to[0]|less_than_equal_to[50]',
            'signup' => 'permit_empty|in_list[0,1]',
            'yearlyMonths' => 'required|is_natural_no_zero|less_than_equal_to[12]',
        ]);
        $n = (new PlatformSettings($this->actor()))->save($data);

        return $this->done('Settings saved (' . $n . ' change' . ($n === 1 ? '' : 's') . ').', site_url('system'));
    }
}
