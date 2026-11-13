<?php

declare(strict_types=1);

namespace App\Modules\Plans\Controllers;

use App\Controllers\BaseController;
use App\Modules\Plans\Services\PlanAdmin;
use CodeIgniter\HTTP\RedirectResponse;

final class PlansController extends BaseController
{
    private const RULES = [
        'name' => 'required|max_length[80]',
        'tagline' => 'permit_empty|max_length[160]',
        'color' => 'required|regex_match[/^#[0-9A-Fa-f]{6}$/]',
        'limit_outlets' => 'required|is_natural|less_than_equal_to[10000]',
        'limit_users' => 'required|is_natural|less_than_equal_to[10000]',
        'all_modules' => 'permit_empty|in_list[0,1]',
        'is_active' => 'permit_empty|in_list[0,1]',
        'is_popular' => 'permit_empty|in_list[0,1]',
        'sort_order' => 'permit_empty|is_natural|less_than_equal_to[1000]',
        'modules' => 'permit_empty',
        'modules.*' => 'permit_empty|alpha_dash|max_length[32]',
        'features' => 'permit_empty',
        'features.*' => 'permit_empty|alpha_numeric|max_length[32]',
    ];

    public function index(): string
    {
        $catalog = service('catalog');
        $types = $catalog->businessTypes();
        $type = (string) $this->request->getGet('type');
        if (! isset($types[$type])) {
            $type = (string) array_key_first($types);
        }

        return $this->page('App\Modules\Plans\Views\index', [
            'title' => 'Plans', 'plans' => array_values($catalog->plans()), 'types' => $types, 'type' => $type,
            'counts' => PlanAdmin::tenantCounts(), 'modules' => $catalog->modules(), 'yearlyMonths' => (int) $catalog->setting('yearlyMonths', 10),
        ]);
    }

    public function new(): string
    {
        return $this->form(null);
    }

    public function edit(int $id): string
    {
        return $this->form(service('catalog')->plan($id));
    }

    private function form(?array $plan): string
    {
        return $this->page('App\Modules\Plans\Views\form', [
            'title' => $plan ? 'Edit plan · ' . $plan['name'] : 'New plan', 'back' => [site_url('plans'), 'Plans'],
            'plan' => $plan, 'modules' => service('catalog')->modules(),
        ]);
    }

    private function save(?int $id): RedirectResponse
    {
        $rules = self::RULES + ($id === null ? ['code' => 'required|alpha_dash|max_length[32]'] : []);
        $data = $this->validated($rules);
        $planId = (new PlanAdmin($this->actor()))->save(
            $id,
            $data,
            array_values(array_map('strval', (array) ($this->request->getPost('modules') ?? []))),
            array_values(array_map('strval', (array) ($this->request->getPost('features') ?? []))),
        );

        return $this->done($id === null ? 'Plan created. Set its prices in the price list.' : 'Plan saved.', site_url('plans') . '#plan-' . $planId);
    }

    public function create(): RedirectResponse
    {
        return $this->save(null);
    }

    public function update(int $id): RedirectResponse
    {
        service('catalog')->plan($id);

        return $this->save($id);
    }

    public function delete(int $id): RedirectResponse
    {
        $name = (new PlanAdmin($this->actor()))->delete($id);

        return $this->done($name . ' deleted.', site_url('plans'));
    }

    public function prices(): RedirectResponse
    {
        $this->validated(['prices' => 'required', 'type' => 'permit_empty|alpha_dash|max_length[32]']);
        $n = (new PlanAdmin($this->actor()))->savePrices((array) $this->request->getPost('prices'));

        return $this->done('Price list saved (' . $n . ' change' . ($n === 1 ? '' : 's') . ').', site_url('plans') . '?type=' . rawurlencode((string) $this->request->getPost('type')));
    }
}
