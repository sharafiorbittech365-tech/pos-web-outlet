<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Controllers;

use App\Controllers\ApiController;
use App\Modules\Storefront\Services\StorefrontService;
use CodeIgniter\HTTP\ResponseInterface;

/** GET /v1/storefront */
final class StorefrontController extends ApiController
{
    public function show(): ResponseInterface
    {
        return $this->cached((new StorefrontService($this->site()))->profile());
    }

    public function sitePage(string $slug): ResponseInterface
    {
        return $this->cached((new StorefrontService($this->site()))->page($slug));
    }
}
