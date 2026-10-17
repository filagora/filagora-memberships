<?php

declare(strict_types=1);

namespace Filagora\Memberships\Contracts;

use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Models\MembershipPlan;

/**
 * A provider whose catalogue products and plans are created from the package's plans, rather
 * than typed in by hand.
 */
interface SyncsPlans
{
    /**
     * Create what is missing at the provider and store its ids on the plan and its prices.
     *
     * @return list<string> The currencies that were created.
     *
     * @throws ProviderFailed
     */
    public function syncPlan(MembershipPlan $plan): array;
}
