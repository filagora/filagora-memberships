<?php

declare(strict_types=1);

namespace Filagora\Memberships\Exceptions;

use Filagora\Memberships\Enums\SubscriptionRefusal;
use RuntimeException;

final class SubscriptionRefused extends RuntimeException
{
    public function __construct(public readonly SubscriptionRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
