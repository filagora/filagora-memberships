<?php

declare(strict_types=1);

namespace Filagora\Memberships\Entitlements;

use Filagora\Entitlements\Support\EntitlementCache;
use Filagora\Memberships\Events\MembershipChanged;

/**
 * A membership that changed changes what its owner holds, so their cached entitlements go.
 */
final class BumpEntitlementsCache
{
    public function __construct(private readonly EntitlementCache $cache) {}

    public function handle(MembershipChanged $event): void
    {
        $this->cache->bumpUserVersion((int) $event->membership->user_id);
    }
}
