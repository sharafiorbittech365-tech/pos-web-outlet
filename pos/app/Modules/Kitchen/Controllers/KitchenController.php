<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Controllers;

use App\Controllers\BaseController;
use App\Modules\Kitchen\Services\KitchenService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;

/**
 * Kitchen board. The page polls kitchen/feed every 10 s (JSON with the board markup and counts);
 * ticking a line or changing a ticket is a POST (fetch with the CSRF header, or a plain form).
 */
final class KitchenController extends BaseController
{
    private function svc(): KitchenService
    {
        $b = service('posSession')->business();
        if (empty($b['settings']['features']['kitchen'])) {
            throw new NotFoundException('This outlet has no kitchen board.');
        }

        return new KitchenService(service('posSession')->businessId());
    }

    private function filter(): string
    {
        $f = (string) ($this->request->getGet('f') ?? 'active');

        return in_array($f, KitchenService::FILTERS, true) ? $f : 'active';
    }

    /** @return array{html: string, counts: array<string, int>, left: float} */
    private function board(string $filter): array
    {
        $svc = $this->svc();
        $tickets = $svc->tickets();
        $backlog = $svc->backlog($tickets);
        $shown = array_values(array_filter($tickets, static fn ($t) => $filter === 'active' ? $t['kitchen_status'] !== 'served' : $t['state'] === $filter));

        return [
            'html' => view('App\Modules\Kitchen\Views\_board', ['tickets' => $shown, 'all' => $tickets, 'backlog' => $backlog, 'filter' => $filter, 'labels' => \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) (service('posSession')->business()['business_type'] ?? ''), (array) (service('posSession')->business()['settings']['labels'] ?? []))]),
            'counts' => $svc->counts($tickets),
            'left' => array_sum(array_column($backlog, 'qty')),
        ];
    }

    public function index(): string
    {
        $filter = $this->filter();
        $b = $this->board($filter);

        return $this->page('App\Modules\Kitchen\Views\index', ['title' => \OmniPOS\Core\Services\BusinessTypeSetup::terms((string) (service('posSession')->business()['business_type'] ?? ''), (array) (service('posSession')->business()['settings']['labels'] ?? []))['kitchen'], 'board' => $b, 'filter' => $filter]);
    }

    public function feed(): ResponseInterface
    {
        return $this->json($this->board($this->filter()));
    }

    public function toggle(int $itemId): ResponseInterface|RedirectResponse
    {
        $in = $this->input();
        $r = $this->svc()->toggle($itemId, filter_var($in['done'] ?? false, FILTER_VALIDATE_BOOL));
        if ($this->request->is('json') || $this->request->isAJAX()) {
            return $this->json($r);
        }

        return redirect()->back();
    }

    public function status(int $orderId): ResponseInterface|RedirectResponse
    {
        $in = $this->input();
        $st = $this->svc()->setStatus($orderId, (string) ($in['status'] ?? ''));
        if ($this->request->is('json') || $this->request->isAJAX()) {
            return $this->json(['order_id' => $orderId, 'status' => $st]);
        }

        return redirect()->to(site_url('kitchen'));
    }
}
