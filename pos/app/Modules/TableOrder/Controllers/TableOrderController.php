<?php

declare(strict_types=1);

namespace App\Modules\TableOrder\Controllers;

use App\Controllers\BaseController;
use App\Modules\TableOrder\Services\TableOrderService;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\TenantAccessDeniedException;
use OmniPOS\Ui\Shield\Shield;

/**
 * The public table-ordering page (no sign-in): <pos address>/order/table-5/<secret code>.
 * Everything here is reachable by anyone holding the code — it only ever shows the menu of that one
 * table's outlet and lets a guest send an order to it; no staff data is exposed. Routes: see Config/Routes.php.
 */
final class TableOrderController extends BaseController
{
    public function index(string $label, string $token): ResponseInterface|string
    {
        $svc = $this->open($token, $redirect);
        if ($svc === null) {
            return $redirect;
        }

        return view('App\Modules\TableOrder\Views\page', [
            'title' => $svc->table()['name'] . ' · ' . $svc->business()['name'], 'svc' => $svc, 'info' => $svc->info(), 'base' => $this->base($label, $token),
            'primary' => (string) ($svc->business()['settings']['theme']['primary'] ?? '#4F46E5'), 'logo' => biz_logo_url($svc->business()),
        ]);
    }

    public function menu(string $label, string $token): ResponseInterface
    {
        $svc = $this->open($token, $redirect);

        return $svc === null ? $this->response->setStatusCode(404)->setJSON(['error' => 'This table code is not valid any more.']) : $this->json($svc->menu());
    }

    /** A fresh human check (the old one works once). */
    public function challenge(string $label, string $token): ResponseInterface
    {
        return $this->response->setHeader('Cache-Control', 'no-store')->setBody(shield_field('Human check'));
    }

    public function quote(string $label, string $token): ResponseInterface
    {
        $svc = $this->open($token, $redirect);
        if ($svc === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'This table code is not valid any more.']);
        }

        return $this->json($svc->quote($this->input()));
    }

    public function place(string $label, string $token): ResponseInterface
    {
        $svc = $this->open($token, $redirect);
        if ($svc === null) {
            return $this->response->setStatusCode(404)->setJSON(['error' => 'This table code is not valid any more.']);
        }
        $order = $svc->place($this->input());
        $order['link'] = $this->base($label, $token) . '/o/' . Shield::orderToken($order['reference']);

        return $this->json($order);
    }

    /** The guest's order page — only with the signed link handed out when the order was sent. */
    public function status(string $label, string $token, string $signed): ResponseInterface|string
    {
        $svc = $this->open($token, $redirect);
        $ref = Shield::orderRef($signed);
        if ($svc === null || $ref === null) {
            return $svc === null ? $redirect : redirect()->to($this->base($label, $token));
        }
        $o = $svc->status($ref);
        if ($o === null) {
            return redirect()->to($this->base($label, $token));
        }
        if ($this->request->is('json') || $this->request->isAJAX()) {
            return $this->json($o);
        }

        return view('App\Modules\TableOrder\Views\status', [
            'title' => 'Your order · ' . $svc->business()['name'], 'o' => $o, 'info' => $svc->info(), 'base' => $this->base($label, $token), 'self' => $this->base($label, $token) . '/o/' . $signed,
            'primary' => (string) ($svc->business()['settings']['theme']['primary'] ?? '#4F46E5'), 'logo' => biz_logo_url($svc->business()),
        ]);
    }

    private function base(string $label, string $token): string
    {
        return site_url('order/' . $label . '/' . $token);
    }

    /**
     * The table behind the code, or null with $redirect set to the page to show instead (another POS
     * server's address, or the "not valid" page).
     */
    private function open(string $token, mixed &$redirect): ?TableOrderService
    {
        try {
            return new TableOrderService($token);
        } catch (TenantAccessDeniedException $e) {
            $url = (string) ($e->details()['server_url'] ?? '');
            $redirect = $url !== '' ? redirect()->to(rtrim($url, '/') . '/' . ltrim($this->request->getUri()->getPath(), '/')) : $this->gone($e->getMessage());
        } catch (NotFoundException $e) {
            $redirect = $this->gone($e->getMessage());
        }

        return null;
    }

    private function gone(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(404)->setBody(view('App\Modules\TableOrder\Views\gone', ['message' => $message, 'title' => 'Table ordering']));
    }
}
