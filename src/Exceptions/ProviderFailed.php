<?php

declare(strict_types=1);

namespace Filagora\Memberships\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A billing provider could not do what it was asked. Providers throw this; the service reports
 * the cause and turns it into a refusal the host can show.
 */
final class ProviderFailed extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
