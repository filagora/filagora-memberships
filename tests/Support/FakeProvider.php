<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Support;

use Filagora\Memberships\Billing\ProviderSubscription;
use Filagora\Memberships\Contracts\BillingProvider;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;

class FakeProvider implements BillingProvider
{
    public bool $configured = true;

    public bool $failing = false;

    /** @var list<array{membership: int, price: string, return: string, cancel: string}> */
    public array $subscribed = [];

    /** @var list<int> */
    public array $canceled = [];

    public function __construct(private readonly string $key = 'fake') {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function subscribe(UserMembership $membership, MembershipPlanPrice $price, string $returnUrl, string $cancelUrl): ProviderSubscription
    {
        if ($this->failing) {
            throw new ProviderFailed('The provider is down.');
        }

        $this->subscribed[] = ['membership' => $membership->id, 'price' => (string) $price->provider_plan_id, 'return' => $returnUrl, 'cancel' => $cancelUrl];

        return new ProviderSubscription('SUB-'.$membership->id, 'https://pay.test/approve/'.$membership->id, ['fake_status' => 'APPROVAL_PENDING']);
    }

    public function cancel(UserMembership $membership): void
    {
        if ($this->failing) {
            throw new ProviderFailed('The provider is down.');
        }

        $this->canceled[] = $membership->id;
    }
}
