<?php

declare(strict_types=1);

namespace App\Exceptions;

use OmniPOS\Core\Exceptions\ConflictException;

/** An ordered item is no longer in stock (409 out_of_stock). */
final class OutOfStockException extends ConflictException
{
    public const DEFAULT_MESSAGE = 'An item in this order is out of stock.';

    public function errorCode(): string
    {
        return 'out_of_stock';
    }
}
