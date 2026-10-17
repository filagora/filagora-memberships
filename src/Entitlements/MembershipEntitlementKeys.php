<?php

declare(strict_types=1);

namespace Filagora\Memberships\Entitlements;

use Filagora\Entitlements\Contracts\EntitlementKeySource;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Support\Models;

/**
 * Every entitlement key any plan grants, so the definitions screen lists them.
 */
final class MembershipEntitlementKeys implements EntitlementKeySource
{
    public function keys(): array
    {
        $keys = [];

        foreach (Models::class('membership_plan')::query()->get(['entitlements']) as $plan) {
            if ($plan instanceof MembershipPlan) {
                array_push($keys, ...$plan->entitlementList());
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }
}
