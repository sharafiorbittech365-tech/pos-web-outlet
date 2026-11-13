<?php

declare(strict_types=1);

namespace App\Modules\Floor\Controllers;

use App\Controllers\BaseController;
use App\Modules\Floor\Services\FloorService;
use OmniPOS\Core\Exceptions\NotFoundException;

final class FloorController extends BaseController
{
    public function index(): string
    {
        $s = service('posSession');
        $b = $s->business();
        if (empty($b['settings']['features']['seats'])) {
            throw new NotFoundException('This outlet has no tables or rooms.');
        }
        $labels = \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));

        return $this->page('App\Modules\Floor\Views\index', [
            'title' => (string) ($labels['floor'] ?? 'Tables'), 'tables' => (new FloorService($s->businessId()))->tables(),
            'labels' => $labels, 'hourly' => ! empty($b['settings']['features']['hourly']), 'cur' => $s->currency(),
        ]);
    }
}
