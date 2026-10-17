<?php

declare(strict_types=1);

namespace Filagora\Memberships\Entitlements;

use Filagora\Entitlements\Services\EntitlementDefinitionCatalog;
use Filagora\Memberships\Models\MembershipPlan;

/**
 * When a plan is saved, make sure each entitlement it grants has a definition.
 */
final class SyncPlanDefinitions
{
    public function __construct(private readonly EntitlementDefinitionCatalog $catalog) {}

    public function saved(MembershipPlan $plan): void
    {
        $this->catalog->materialize($plan->entitlementList());
    }
}
