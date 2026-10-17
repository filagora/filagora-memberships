<?php

declare(strict_types=1);

namespace Filagora\Memberships\Billing;

/**
 * What a billing provider hands back when a subscription is started: its own id for it, where the
 * person must go to approve it, and anything worth keeping on the membership.
 */
final readonly class ProviderSubscription
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $id,
        public string $redirectUrl,
        public array $meta = [],
    ) {}
}
