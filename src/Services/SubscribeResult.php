<?php

declare(strict_types=1);

namespace Filagora\Memberships\Services;

use Filagora\Memberships\Models\UserMembership;

/**
 * The outcome of starting a membership: active at once (a free plan), or waiting for the person
 * to approve it at the provider, in which case `redirectUrl` says where to send them. A paid
 * membership stays pending until the provider's webhook confirms it.
 */
final readonly class SubscribeResult
{
    public function __construct(
        public UserMembership $membership,
        public ?string $redirectUrl = null,
    ) {}

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
