<?php

declare(strict_types=1);

namespace Filagora\Memberships\Contracts;

use Filagora\Memberships\Billing\ProviderSubscription;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;

/**
 * A payment provider a paid plan can use. The plan names its provider by key
 * (`membership_plans.provider`); the package ships PayPal, and another package can register
 * more with the `ProviderRegistry`.
 */
interface BillingProvider
{
    /**
     * The value stored in `membership_plans.provider` and `user_memberships.provider`.
     */
    public function key(): string;

    public function label(): string;

    /**
     * Whether the provider has what it needs (credentials, webhook id) to take payments.
     */
    public function isConfigured(): bool;

    /**
     * Start a subscription for the pending membership at the price's provider plan.
     *
     * @throws ProviderFailed
     */
    public function subscribe(UserMembership $membership, MembershipPlanPrice $price, string $returnUrl, string $cancelUrl): ProviderSubscription;

    /**
     * Ask the provider to stop billing the membership's subscription.
     *
     * @throws ProviderFailed
     */
    public function cancel(UserMembership $membership): void;
}
