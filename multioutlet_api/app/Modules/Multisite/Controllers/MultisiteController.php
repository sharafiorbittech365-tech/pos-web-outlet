<?php

declare(strict_types=1);

namespace App\Modules\Multisite\Controllers;

use App\Controllers\ApiController;
use App\Modules\Multisite\Services\MultisiteCatalog;
use App\Modules\Multisite\Services\MultisiteDirectory;
use App\Modules\Multisite\Services\MultisiteOrders;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET  /v1/multisite                 website profile, brand, outlets, merged categories
 * GET  /v1/multisite/outlets         authorised outlets (address, area, phone, hours, location)
 * GET  /v1/multisite/outlets/{id}    one outlet
 * GET  /v1/multisite/categories      categories merged across the outlets
 * GET  /v1/multisite/products        items across outlets with per-outlet price & stock (paginated)
 * GET  /v1/multisite/products/{id}   one item with every outlet's offer
 * POST /v1/multisite/orders          order to ONE outlet (outlet_id) — Idempotency-Key
 * GET  /v1/multisite/orders/{ref}    tracking
 */
final class MultisiteController extends ApiController
{
    private function dir(): MultisiteDirectory
    {
        return service('multisite');
    }

    private function catalog(): MultisiteCatalog
    {
        return new MultisiteCatalog($this->dir());
    }

    public function profile(): ResponseInterface
    {
        return $this->cached($this->dir()->profile($this->catalog()->categories()));
    }

    public function outlets(): ResponseInterface
    {
        return $this->cached(array_map(static fn (array $o): array => MultisiteDirectory::present($o), $this->dir()->outlets()));
    }

    public function outlet(string $id): ResponseInterface
    {
        return $this->cached(MultisiteDirectory::present($this->dir()->outlet((int) $id)));
    }

    public function categories(): ResponseInterface
    {
        return $this->cached($this->catalog()->categories());
    }

    public function products(): ResponseInterface
    {
        [$page, $per] = $this->paging();
        $result = $this->catalog()->products($this->query(), $page, $per);

        return $this->cached($result['data'], $result['meta']);
    }

    public function product(string $id): ResponseInterface
    {
        // stock is part of the answer: revalidate every time (ETag → usually 304)
        return $this->cached($this->catalog()->product((int) $id), [], 0);
    }

    public function createOrder(): ResponseInterface
    {
        $body = $this->input();
        $orders = new MultisiteOrders($this->dir(), $this->caller());

        return $this->idempotent($body, static fn (): array => $orders->place($body));
    }

    public function order(string $ref): ResponseInterface
    {
        return $this->ok((new MultisiteOrders($this->dir(), $this->caller()))->track($ref));
    }
}
