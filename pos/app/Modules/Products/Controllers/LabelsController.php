<?php

declare(strict_types=1);

namespace App\Modules\Products\Controllers;

use App\Controllers\BaseController;
use App\Modules\Products\Services\LabelService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Products › Print labels: pick products (or come from the product list's selection), set how many
 * labels each, design the label, print. The labels are drawn in the browser (assets/pos/labels.js).
 */
final class LabelsController extends BaseController
{
    public function index(): string
    {
        $s = service('posSession');
        $ids = array_map('intval', (array) ($this->request->getGet('ids') ?? []));

        return $this->page('App\Modules\Products\Views\labels', [
            'title' => 'Print labels',
            'items' => LabelService::forCurrent()->items(),
            'selected' => $ids,
            'template' => LabelService::template($s->business()),
            'canManage' => $s->can('products.manage'),
        ]);
    }

    public function saveTemplate(): ResponseInterface
    {
        $t = LabelService::templateFrom($this->input());
        LabelService::forCurrent()->saveTemplate($t);

        return $this->json($t);
    }

    public function createCodes(): RedirectResponse
    {
        $keys = array_values(array_filter(array_map('strval', (array) ($this->request->getPost('keys') ?? []))));
        $n = LabelService::forCurrent()->createMissingCodes($keys);
        $back = site_url('products/labels') . '?' . http_build_query(['ids' => array_map('intval', (array) ($this->request->getPost('ids') ?? []))]);

        return $this->done($n > 0 ? $n . ' code' . ($n === 1 ? '' : 's') . ' created — these labels now scan at the till.' : 'Every selected item already has a code.', $back);
    }
}
