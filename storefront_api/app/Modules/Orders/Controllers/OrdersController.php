<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Controllers\ApiController;
use App\Modules\Orders\Services\OrderPlacement;
use App\Modules\Orders\Services\OrderQueries;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\NotFoundException;
use OmniPOS\Core\Exceptions\PermissionDeniedException;

/**
 * POST /v1/orders                 orders:create (Idempotency-Key)
 * GET  /v1/orders/{reference}     orders:create — tracking ("W…" order or "BK…" booking reference)
 * GET  /v1/orders                 orders:read
 * GET  /v1/orders/{id}            orders:read   (numeric id)
 * GET  /v1/tables/{code}          orders:create — the table behind a table QR code (Table QR ordering)
 */
final class OrdersController extends ApiController
{
    public function create(): ResponseInterface
    {
        $body = $this->input();
        $placement = new OrderPlacement($this->site(), $this->caller(), service('orders'));

        return $this->idempotent($body, static fn (): array => $placement->place($body));
    }

    /** The table a QR code belongs to (this site's outlet only); 404 for any other code. */
    public function table(string $code): ResponseInterface
    {
        $t = (new OrderPlacement($this->site(), $this->caller(), service('orders')))->table($code)
            ?? throw new NotFoundException('This table code is not valid for this shop.');

        return $this->ok(['name' => $t['name'], 'area' => $t['area'], 'seats' => $t['seats']]);
    }

    public function index(): ResponseInterface
    {
        [$page, $per] = $this->paging();
        $result = $this->queries()->list($this->query(), $page, $per);

        return $this->ok($result['data'], $result['meta']);
    }

    /** One path for both reads: a numeric id is the integrator detail, anything else a tracking reference. */
    public function show(string $ref): ResponseInterface
    {
        if (ctype_digit($ref)) {
            $this->requireScope('orders:read');

            return $this->ok($this->queries()->detail((int) $ref));
        }
        $this->requireScope('orders:create');

        return $this->ok($this->queries()->track($ref));
    }

    private function queries(): OrderQueries
    {
        return new OrderQueries(service('orders'));
    }

    private function requireScope(string $scope): void
    {
        if (! $this->caller()->can($scope)) {
            throw new PermissionDeniedException('This key lacks the "' . $scope . '" scope.');
        }
    }
}
