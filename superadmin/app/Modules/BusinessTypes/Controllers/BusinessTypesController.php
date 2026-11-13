<?php

declare(strict_types=1);

namespace App\Modules\BusinessTypes\Controllers;

use App\Controllers\BaseController;
use App\Modules\BusinessTypes\Services\BusinessTypeAdmin;
use CodeIgniter\HTTP\RedirectResponse;

final class BusinessTypesController extends BaseController
{
    public function index(): string
    {
        $types = db_connect()->table('business_types')->orderBy('sort_order')->get()->getResultArray();
        $admin = new BusinessTypeAdmin($this->actor());

        return $this->page('App\Modules\BusinessTypes\Views\index', [
            'title' => 'Business types', 'types' => array_map(static fn (array $t): array => $admin->find((string) $t['code']), $types), 'usage' => BusinessTypeAdmin::usage(),
        ]);
    }

    public function edit(string $code): string
    {
        $t = (new BusinessTypeAdmin($this->actor()))->find($code);

        return $this->page('App\Modules\BusinessTypes\Views\form', [
            'title' => 'Business type · ' . $t['name'], 'back' => [site_url('business-types'), 'Business types'], 't' => $t, 'usage' => BusinessTypeAdmin::usage()[$code] ?? ['tenants' => 0, 'outlets' => 0],
        ]);
    }

    public function update(string $code): RedirectResponse
    {
        $this->validated([
            'name' => 'required|max_length[80]', 'name_bn' => 'permit_empty|max_length[80]', 'icon' => 'required|regex_match[/^[a-z0-9-]{2,40}$/]',
            'store_kind' => 'required|in_list[shop,menu,booking,service]', 'booking_kind' => 'permit_empty|alpha_dash|max_length[20]',
            'sort_order' => 'permit_empty|is_natural|less_than_equal_to[1000]', 'is_active' => 'permit_empty|in_list[0,1]',
        ]);
        (new BusinessTypeAdmin($this->actor()))->save($code, (array) $this->request->getPost());

        return $this->done('Business type saved. New outlets of this type start with this setup.', site_url('business-types/' . $code));
    }

    public function reset(string $code): RedirectResponse
    {
        $name = (new BusinessTypeAdmin($this->actor()))->reset($code);

        return $this->done($name . ' set back to the Bangladesh defaults.', site_url('business-types/' . $code));
    }
}
