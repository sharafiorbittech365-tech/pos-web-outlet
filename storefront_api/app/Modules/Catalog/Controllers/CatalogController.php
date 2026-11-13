<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Controllers\ApiController;
use App\Modules\Catalog\Services\CatalogService;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ValidationException;

/** GET /v1/categories, /v1/products, /v1/products/{id}, /v1/inventory */
final class CatalogController extends ApiController
{
    private function catalog(): CatalogService
    {
        return new CatalogService($this->site());
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
        return $this->cached($this->catalog()->product((int) $id));
    }

    public function reviews(string $id): ResponseInterface
    {
        [$page, $per] = $this->paging(50);
        $result = $this->catalog()->reviews((int) $id, $page, $per);

        return $this->cached($result['data'], $result['meta'], 30);
    }

    public function addReview(string $id): ResponseInterface
    {
        $body = $this->input();
        $catalog = $this->catalog();

        return $this->idempotent($body, static fn (): array => $catalog->addReview((int) $id, $body));
    }

    public function inventory(): ResponseInterface
    {
        $raw = trim((string) ($this->request->getGet('product_ids') ?? ''));
        $ids = [];
        if ($raw !== '') {
            foreach (explode(',', $raw) as $part) {
                if (! ctype_digit(trim($part))) {
                    throw new ValidationException('Invalid product_ids.', ['product_ids' => 'Use comma-separated product ids.']);
                }
                $ids[] = (int) trim($part);
            }
        }
        [$page, $per] = $this->paging(100);
        $result = $this->catalog()->inventory(array_values(array_unique($ids)), $page, $per);

        // stock changes every sale: revalidate on every call
        return $this->cached($result['data'], $result['meta'], 0);
    }
}
