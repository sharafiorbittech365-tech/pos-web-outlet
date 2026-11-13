<?php

declare(strict_types=1);

namespace App\Exceptions;

use OmniPOS\Core\Exceptions\ConflictException;

/** The requested booking time is no longer free (409 slot_taken). */
final class SlotTakenException extends ConflictException
{
    public const DEFAULT_MESSAGE = 'That time was just taken. Please pick another slot.';

    public function errorCode(): string
    {
        return 'slot_taken';
    }
}
