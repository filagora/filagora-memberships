<?php

declare(strict_types=1);

namespace Filagora\Memberships\Services;

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Starts, cancels and answers questions about memberships. A free plan becomes active at once; a
 * paid plan creates a pending membership and hands the person to the plan's billing provider, and
 * stays pending until the provider's webhook confirms it. Anything refused is a
 * `SubscriptionRefused` with a reason the host can show.
 */
class Memberships
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    /**
     * Active plans, in the order they are shown.
     *
     * @return Collection<int, MembershipPlan>
     */
    public function availablePlans(): Collection
    {
        /** @var Collection<int, MembershipPlan> */
        return Models::class('membership_plan')::query()
            ->where('is_active', true)
            ->with('prices')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * The memberships that currently give the person access.
     *
     * @return Collection<int, UserMembership>
     */
    public function currentFor(Model $user): Collection
    {
        /** @var Collection<int, UserMembership> */
        return Models::class('user_membership')::query()
            ->where('user_id', $user->getKey())
            ->with('plan')
            ->get()
            ->filter(fn (Model $membership): bool => $membership instanceof UserMembership && $membership->isCurrentlyActive())
            ->values();
    }

    public function hasActive(Model $user): bool
    {
        return $this->currentFor($user)->isNotEmpty();
    }

    /**
     * Start a membership on a plan. A free plan is active when this returns; for a paid plan send
     * the person to `redirectUrl`.
     *
     * @throws SubscriptionRefused
     */
    public function subscribe(Model $user, MembershipPlan $plan, ?string $currency = null, ?string $returnUrl = null, ?string $cancelUrl = null): SubscribeResult
    {
        if (! $plan->is_active) {
            throw new SubscriptionRefused(SubscriptionRefusal::PLAN_INACTIVE);
        }

        if ($plan->is_free) {
            return new SubscribeResult($this->writeLocalMembership($user, $plan, ['subscribed_via' => 'self_service']));
        }

        if (! $plan->isCheckoutReady()) {
            throw new SubscriptionRefused(SubscriptionRefusal::NOT_CONFIGURED);
        }

        $price = $currency !== null ? $plan->priceFor($currency) : null;
        $price ??= $plan->defaultPrice();

        if (! $price instanceof MembershipPlanPrice || ! $price->isSyncedWithProvider()) {
            throw new SubscriptionRefused(SubscriptionRefusal::NOT_CONFIGURED);
        }

        $provider = $this->providers->get($plan->provider)
            ?? throw new SubscriptionRefused(SubscriptionRefusal::UNSUPPORTED_PROVIDER);

        if (! $provider->isConfigured()) {
            throw new SubscriptionRefused(SubscriptionRefusal::PROVIDER_NOT_CONFIGURED);
        }

        $subscriptionName = $this->subscriptionNameFor($plan);

        /** @var UserMembership $membership */
        $membership = Models::class('user_membership')::query()->updateOrCreate(
            [
                'user_id' => $user->getKey(),
                'membership_plan_id' => $plan->getKey(),
                'provider' => $provider->key(),
                'subscription_name' => $subscriptionName,
            ],
            [
                'status' => MembershipStatus::PENDING->value,
                'starts_at' => now(),
                'granted_entitlements_snapshot' => $plan->entitlementList(),
                'meta' => [
                    'provider_price_id' => trim((string) $price->provider_plan_id),
                    'currency' => $price->currency,
                    'subscription_name' => $subscriptionName,
                    'subscribed_via' => $provider->key().'_checkout',
                ],
            ],
        );

        try {
            $subscription = $provider->subscribe(
                $membership,
                $price,
                $this->checkoutUrl($returnUrl, 'return_url'),
                $this->checkoutUrl($cancelUrl, 'cancel_url'),
            );
        } catch (ProviderFailed $failure) {
            report($failure);

            throw new SubscriptionRefused(SubscriptionRefusal::PROVIDER_FAILED);
        }

        $membership->forceFill([
            'provider_subscription_id' => $subscription->id !== '' ? $subscription->id : null,
            'meta' => [...($membership->meta ?? []), ...$subscription->meta],
        ])->save();

        return new SubscribeResult($membership, $subscription->redirectUrl);
    }

    /**
     * Cancel one of the person's own memberships. A provider-billed one is cancelled at the
     * provider and keeps its access to the end of the period already paid for; a free or local
     * one ends now. A membership whose provider is not registered is refused rather than marked
     * cancelled while the provider keeps billing.
     *
     * @throws SubscriptionRefused
     */
    public function cancel(Model $user, UserMembership $membership): UserMembership
    {
        if ((string) $membership->user_id !== (string) $user->getKey()) {
            throw new SubscriptionRefused(SubscriptionRefusal::NOT_YOUR_MEMBERSHIP);
        }

        if (filled($membership->provider_subscription_id)) {
            $provider = $this->providers->get($membership->provider)
                ?? throw new SubscriptionRefused(SubscriptionRefusal::UNSUPPORTED_PROVIDER);

            try {
                $provider->cancel($membership);
            } catch (ProviderFailed $failure) {
                report($failure);

                throw new SubscriptionRefused(SubscriptionRefusal::PROVIDER_FAILED);
            }

            $membership->fill([
                'status' => MembershipStatus::CANCELING->value,
                'canceled_at' => now(),
                'ends_at' => $membership->current_period_end ?? $membership->ends_at,
            ])->save();

            return $membership;
        }

        $membership->fill([
            'status' => MembershipStatus::CANCELED->value,
            'canceled_at' => now(),
            'ends_at' => now(),
        ])->save();

        return $membership;
    }

    /**
     * Give a person a free plan from the dashboard.
     *
     * @throws SubscriptionRefused
     */
    public function activateLocal(Model $user, MembershipPlan $plan, ?Model $actor = null): UserMembership
    {
        if (! $plan->is_active || ! $plan->is_free) {
            throw new SubscriptionRefused($plan->is_free ? SubscriptionRefusal::PLAN_INACTIVE : SubscriptionRefusal::PLAN_NOT_FREE);
        }

        return $this->writeLocalMembership($user, $plan, array_filter([
            'activated_via' => 'dashboard',
            'activated_by_user_id' => $actor?->getKey(),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * The name a plan's subscription goes by at the provider and on the membership: the
     * configured prefix and the plan code.
     */
    public function subscriptionNameFor(MembershipPlan $plan): string
    {
        $prefix = trim((string) config('filagora-memberships.subscription_prefix', 'membership'));
        $code = Str::slug($plan->code, '_');

        if ($code === '') {
            $code = 'plan_'.$plan->getKey();
        }

        return $prefix === '' ? $code : $prefix.'_'.$code;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeLocalMembership(Model $user, MembershipPlan $plan, array $meta): UserMembership
    {
        return DB::transaction(function () use ($user, $plan, $meta): UserMembership {
            /** @var UserMembership $membership */
            $membership = Models::class('user_membership')::query()
                ->where('user_id', $user->getKey())
                ->where('membership_plan_id', $plan->getKey())
                ->where('provider', 'local')
                ->latest('id')
                ->first() ?? new (Models::class('user_membership'))([
                    'user_id' => $user->getKey(),
                    'membership_plan_id' => $plan->getKey(),
                    'provider' => 'local',
                ]);

            $membership->fill([
                'status' => MembershipStatus::ACTIVE->value,
                'subscription_name' => $this->subscriptionNameFor($plan),
                'provider_subscription_id' => null,
                'starts_at' => $membership->starts_at ?? now(),
                'trial_ends_at' => null,
                'current_period_start' => now(),
                'current_period_end' => null,
                'ends_at' => null,
                'canceled_at' => null,
                'granted_entitlements_snapshot' => $plan->entitlementList(),
                'meta' => [...($membership->meta ?? []), ...$meta],
            ]);
            $membership->save();

            return $membership;
        });
    }

    private function checkoutUrl(?string $explicit, string $key): string
    {
        $value = trim((string) ($explicit ?? config("filagora-memberships.checkout.{$key}", '')));

        if ($value === '') {
            return url('/');
        }

        return Route::has($value) ? route($value) : $value;
    }
}
