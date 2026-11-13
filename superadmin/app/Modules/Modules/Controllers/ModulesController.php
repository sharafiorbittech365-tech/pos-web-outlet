<?php

declare(strict_types=1);

namespace App\Modules\Modules\Controllers;

use App\Controllers\BaseController;
use App\Modules\Modules\Services\ModuleAdmin;
use CodeIgniter\HTTP\RedirectResponse;

final class ModulesController extends BaseController
{
    public function index(): string
    {
        $catalog = service('catalog');
        $types = $catalog->businessTypes();
        $type = (string) $this->request->getGet('type');
        if (! isset($types[$type])) {
            $type = (string) array_key_first($types);
        }

        return $this->page('App\Modules\Modules\Views\index', [
            'title' => 'Modules & pricing', 'types' => $types, 'type' => $type, 'modules' => $catalog->modules(),
            'plans' => array_values($catalog->plans()), 'addonCounts' => ModuleAdmin::addonCounts($type),
            'graph' => \OmniPOS\Core\Services\ModuleRules::graph($catalog->modules()), 'recommended' => $catalog->recommended($type), 'features' => $catalog->features(),
        ]);
    }

    public function prices(): RedirectResponse
    {
        $data = $this->validated(['type' => 'required|alpha_dash|max_length[32]', 'prices' => 'required']);
        $n = (new ModuleAdmin($this->actor()))->savePrices($data['type'], (array) $this->request->getPost('prices'));

        return $this->done('Module prices saved (' . $n . ' change' . ($n === 1 ? '' : 's') . ').', site_url('modules') . '?type=' . rawurlencode($data['type']));
    }

    public function recommended(): RedirectResponse
    {
        $data = $this->validated(['type' => 'required|alpha_dash|max_length[32]', 'recommended.*' => 'permit_empty|alpha_dash|max_length[32]']);
        $list = (new ModuleAdmin($this->actor()))->saveRecommended($data['type'], (array) ($this->request->getPost('recommended') ?? []));

        return $this->done('Recommended modules saved (' . count($list) . ').', site_url('modules') . '?type=' . rawurlencode($data['type']));
    }

    /** POST modules/features (new) or modules/features/{code} (edit) */
    public function saveFeature(?string $code = null): RedirectResponse
    {
        $data = $this->validated([
            'code' => $code === null ? 'required|max_length[48]' : 'permit_empty', 'module_code' => 'required|alpha_dash|max_length[32]', 'name' => 'required|max_length[80]',
            'description' => 'permit_empty|max_length[255]', 'price' => 'required|decimal', 'is_default' => 'permit_empty|in_list[0,1]', 'self_select' => 'permit_empty|in_list[0,1]',
            'type' => 'permit_empty|alpha_dash|max_length[32]', 'type_price' => 'permit_empty|decimal', 'not_offered' => 'permit_empty|in_list[0,1]',
        ]);
        $data['code'] = $code ?? (string) $data['code'];
        $saved = (new ModuleAdmin($this->actor()))->saveFeature($data, $code === null);

        return $this->done('Feature ' . $saved . ' saved.', site_url('modules') . '?type=' . rawurlencode((string) ($data['type'] ?? '')) . '#features');
    }

    public function deleteFeature(string $code): RedirectResponse
    {
        (new ModuleAdmin($this->actor()))->deleteFeature($code);

        return $this->done('Feature removed.', site_url('modules') . '?type=' . rawurlencode((string) $this->request->getPost('type')) . '#features');
    }

    public function update(string $code): RedirectResponse
    {
        $data = $this->validated([
            'name' => 'required|max_length[80]', 'description' => 'permit_empty|max_length[255]', 'is_opt_in' => 'permit_empty|in_list[0,1]', 'self_select' => 'permit_empty|in_list[0,1]', 'type' => 'permit_empty|alpha_dash',
            'base_price' => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[1000000]', 'rules' => 'permit_empty|in_list[1]',
            'needs.*' => 'permit_empty|alpha_dash|max_length[32]', 'conflicts.*' => 'permit_empty|alpha_dash|max_length[32]', 'types.*' => 'permit_empty|alpha_dash|max_length[32]',
        ]);
        $rules = ($data['rules'] ?? '') === '1' ? [
            'needs' => (array) ($this->request->getPost('needs') ?? []), 'conflicts' => (array) ($this->request->getPost('conflicts') ?? []),
            'types' => (array) ($this->request->getPost('types') ?? []), 'prices' => (array) ($this->request->getPost('tprices') ?? []),
        ] : null;
        (new ModuleAdmin($this->actor()))->update($code, $data, $rules);

        return $this->done('Module saved.', site_url('modules') . '?type=' . rawurlencode((string) ($data['type'] ?? '')));
    }
}
