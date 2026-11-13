<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Controllers;

use App\Controllers\ApiController;
use App\Modules\Bookings\Services\BookingPlacement;
use CodeIgniter\HTTP\ResponseInterface;
use OmniPOS\Core\Exceptions\ValidationException;

/** GET /v1/bookings/slots, POST /v1/bookings (bookings:create) */
final class BookingsController extends ApiController
{
    private function placement(): BookingPlacement
    {
        return new BookingPlacement($this->site(), $this->caller(), service('bookings'));
    }

    public function slots(): ResponseInterface
    {
        $q = $this->query();
        $pid = (string) ($q['product_id'] ?? '');
        if ($pid !== '' && ! ctype_digit($pid)) {
            throw new ValidationException('Invalid product_id.', ['product_id' => 'Use a service id.']);
        }
        $result = $this->placement()->slots((string) ($q['date'] ?? date('Y-m-d')), $pid !== '' ? (int) $pid : null, (string) ($q['staff'] ?? ''));

        return $this->ok($result['data'], $result['meta']);
    }

    public function create(): ResponseInterface
    {
        $body = $this->input();
        $placement = $this->placement();
        $placement->assertEnabled();

        return $this->idempotent($body, static fn (): array => $placement->create($body));
    }
}
