<?php

declare(strict_types=1);

namespace App\Modules\Floor\Controllers;

use App\Controllers\BaseController;
use App\Modules\Floor\Services\TableAdmin;
use CodeIgniter\HTTP\RedirectResponse;
use OmniPOS\Core\Exceptions\NotFoundException;

/** Tables › Manage, and the printable table QR codes (Table QR ordering). */
final class TablesController extends BaseController
{
    private function labels(): array
    {
        $b = service('posSession')->business();
        if (empty($b['settings']['features']['seats'])) {
            throw new NotFoundException('This outlet has no tables or rooms.');
        }

        return \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) ($b['business_type'] ?? ''), (array) ($b['settings']['labels'] ?? []));
    }

    public function index(): string
    {
        $labels = $this->labels();
        $admin = TableAdmin::forCurrent();
        $qrOn = service('posSession')->access()->has('tableqr');

        return $this->page('App\Modules\Floor\Views\manage', [
            'title' => 'Manage ' . strtolower((string) ($labels['floor'] ?? 'Tables')), 'labels' => $labels, 'tables' => $admin->all(),
            'qrOn' => $qrOn, 'siteBase' => $qrOn ? $admin->siteBase() : null, 'hourly' => ! empty(service('posSession')->business()['settings']['features']['hourly']),
        ]);
    }

    public function store(): RedirectResponse
    {
        $this->labels();
        $id = TableAdmin::forCurrent()->save(null, (array) $this->request->getPost());

        return $this->done('Added ' . trim((string) $this->request->getPost('name')) . '.', site_url('floor/manage') . '#t' . $id);
    }

    public function update(int $id): RedirectResponse
    {
        $this->labels();
        TableAdmin::forCurrent()->save($id, (array) $this->request->getPost());

        return $this->done('Saved.', site_url('floor/manage') . '#t' . $id);
    }

    public function delete(int $id): RedirectResponse
    {
        $this->labels();
        $name = TableAdmin::forCurrent()->delete($id);

        return $this->done('Removed ' . $name . '.', site_url('floor/manage'));
    }

    public function newQr(int $id): RedirectResponse
    {
        $this->labels();
        $name = TableAdmin::forCurrent()->newQr($id);

        return $this->done('New QR code for ' . $name . ' — the old printed QR no longer works. Print the new one.', site_url('floor/manage') . '#t' . $id);
    }

    /** One card per table (or ?ids[]=…) with its QR code, ready to print. */
    public function qr(): string
    {
        $labels = $this->labels();
        $admin = TableAdmin::forCurrent();
        $ids = array_map('intval', (array) ($this->request->getGet('ids') ?? []));
        $cards = [];
        foreach ($admin->all() as $t) {
            if ($ids !== [] && ! in_array((int) $t['id'], $ids, true)) {
                continue;
            }
            $cards[] = ['name' => $t['name'], 'area' => $t['area'], 'url' => $admin->orderUrl((string) $t['qr_token'], (string) $t['name'])];
        }

        return $this->page('App\Modules\Floor\Views\qr', ['title' => 'Table QR codes', 'labels' => $labels, 'cards' => $cards, 'siteBase' => $admin->siteBase()]);
    }
}
