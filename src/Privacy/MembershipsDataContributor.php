<?php

declare(strict_types=1);

namespace Filagora\Memberships\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\BillingEvents;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A person's memberships for their data export, and the personal details the billing log holds
 * about their subscriptions for erasure. The memberships themselves are kept on erasure, as a
 * record of what was paid for and granted; erasure does not cancel a subscription still being
 * billed at a provider, which has to be cancelled first.
 */
final class MembershipsDataContributor implements PrivacyContributor
{
    public function __construct(private readonly BillingEvents $events) {}

    public function key(): string
    {
        return 'memberships';
    }

    public function export(Model $user): array
    {
        $memberships = $this->membershipsOf($user);

        return [
            'memberships' => $memberships->map(fn (UserMembership $membership): array => [
                'plan' => $membership->plan?->name,
                'plan_code' => $membership->plan?->code,
                'status' => $membership->status,
                'provider' => $membership->provider,
                'provider_subscription_id' => $membership->provider_subscription_id,
                'starts_at' => $membership->starts_at?->toIso8601String(),
                'trial_ends_at' => $membership->trial_ends_at?->toIso8601String(),
                'current_period_start' => $membership->current_period_start?->toIso8601String(),
                'current_period_end' => $membership->current_period_end?->toIso8601String(),
                'ends_at' => $membership->ends_at?->toIso8601String(),
                'canceled_at' => $membership->canceled_at?->toIso8601String(),
                'granted_entitlements' => $membership->granted_entitlements_snapshot ?? [],
            ])->all(),
            'billing_events' => $this->events->forSubscriptions($memberships->pluck('provider_subscription_id')->filter()->all())
                ->map(fn ($event): array => [
                    'provider' => $event->provider,
                    'event_type' => $event->event_type,
                    'received_at' => $event->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    public function erase(Model $user): void
    {
        $memberships = $this->membershipsOf($user);

        foreach ($this->events->forSubscriptions($memberships->pluck('provider_subscription_id')->filter()->all()) as $event) {
            $this->events->scrubPersonalDetails($event);
        }

        foreach ($memberships as $membership) {
            $meta = $membership->meta ?? [];
            unset($meta['payer_id']);
            $membership->forceFill(['meta' => $meta])->save();
        }
    }

    /**
     * @return Collection<int, UserMembership>
     */
    private function membershipsOf(Model $user): Collection
    {
        /** @var Collection<int, UserMembership> */
        return Models::class('user_membership')::query()
            ->where('user_id', $user->getKey())
            ->with('plan')
            ->orderBy('id')
            ->get();
    }
}
