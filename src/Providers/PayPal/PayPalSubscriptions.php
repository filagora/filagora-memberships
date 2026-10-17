<?php

declare(strict_types=1);

namespace Filagora\Memberships\Providers\PayPal;

use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps a membership in step with the PayPal subscription behind it, and links a subscription
 * that predates the package (made on the old site, so no membership exists for it) to a person.
 */
class PayPalSubscriptions
{
    public function __construct(
        private readonly PayPalClient $client,
        private readonly Memberships $memberships,
    ) {}

    /**
     * Apply what PayPal says about a subscription to the membership that holds it. A subscription
     * no membership claims is ignored: it is found by its PayPal id, or by the `custom_id` this
     * package sets when it creates one.
     *
     * @param  array<string, mixed>  $subscription  PayPal's subscription resource.
     */
    public function sync(array $subscription, string $eventType): ?UserMembership
    {
        $subscriptionId = trim((string) Arr::get($subscription, 'id', ''));

        if ($subscriptionId === '') {
            return null;
        }

        $membership = $this->membershipFor($subscription, $subscriptionId);
        $plan = $membership?->plan;

        if (! $membership instanceof UserMembership || ! $plan instanceof MembershipPlan) {
            return null;
        }

        $startTime = $this->time(Arr::get($subscription, 'start_time'));
        $updateTime = $this->time(Arr::get($subscription, 'status_update_time'));
        $paidThrough = $this->time(Arr::get($subscription, 'billing_info.next_billing_time'));
        $ending = in_array($eventType, ['BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.EXPIRED'], true)
            || strtoupper(trim((string) Arr::get($subscription, 'status', ''))) === 'CANCELLED';

        if ($ending) {
            $paidThrough ??= $membership->current_period_end;
        }

        $status = $this->status((string) Arr::get($subscription, 'status', ''), $eventType, $paidThrough);
        $closing = in_array($status, [MembershipStatus::CANCELING, MembershipStatus::CANCELED], true);

        $membership->fill([
            'membership_plan_id' => $plan->getKey(),
            'status' => $status->value,
            'provider' => 'paypal',
            'subscription_name' => (string) data_get($membership->meta, 'subscription_name', $this->memberships->subscriptionNameFor($plan)),
            'provider_subscription_id' => $subscriptionId,
            'starts_at' => $membership->starts_at ?? $startTime ?? now(),
            'current_period_start' => $startTime ?? $membership->current_period_start,
            'current_period_end' => $paidThrough,
            'ends_at' => match ($status) {
                MembershipStatus::CANCELED, MembershipStatus::UNPAID => $updateTime ?? now(),
                MembershipStatus::CANCELING => $paidThrough,
                default => null,
            },
            'canceled_at' => $closing ? ($membership->canceled_at ?? $updateTime ?? now()) : null,
            'granted_entitlements_snapshot' => $plan->entitlementList(),
            'meta' => array_filter([
                ...($membership->meta ?? []),
                'provider_price_id' => trim((string) Arr::get($subscription, 'plan_id', (string) data_get($membership->meta, 'provider_price_id', ''))),
                'payer_id' => trim((string) Arr::get($subscription, 'subscriber.payer_id', '')),
                'custom_id' => trim((string) Arr::get($subscription, 'custom_id', '')),
                'event_type' => $eventType,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
        $membership->save();

        return $membership;
    }

    /**
     * Link a PayPal subscription made before this package to a person, from what PayPal says about
     * it. The plan is the one with a price at that PayPal plan. From then on its webhooks find the
     * membership by the subscription id.
     *
     * @throws SubscriptionRefused
     */
    public function link(Model $user, string $subscriptionId, ?Model $actor = null): UserMembership
    {
        $subscriptionId = trim($subscriptionId);

        if ($subscriptionId === '') {
            throw new SubscriptionRefused(SubscriptionRefusal::UNKNOWN_SUBSCRIPTION);
        }

        if (Models::class('user_membership')::query()->where('provider', 'paypal')->where('provider_subscription_id', $subscriptionId)->exists()) {
            throw new SubscriptionRefused(SubscriptionRefusal::ALREADY_LINKED);
        }

        try {
            $subscription = $this->client->getSubscription($subscriptionId);
        } catch (RequestException $exception) {
            throw new SubscriptionRefused($exception->response->status() === 404 ? SubscriptionRefusal::UNKNOWN_SUBSCRIPTION : SubscriptionRefusal::PROVIDER_FAILED);
        } catch (Throwable) {
            throw new SubscriptionRefused(SubscriptionRefusal::PROVIDER_FAILED);
        }

        $planId = trim((string) Arr::get($subscription, 'plan_id', ''));
        $plan = $planId !== '' ? $this->planForPayPalPlan($planId) : null;

        if (! $plan instanceof MembershipPlan) {
            throw new SubscriptionRefused(SubscriptionRefusal::UNKNOWN_PLAN);
        }

        return DB::transaction(function () use ($user, $plan, $subscriptionId, $subscription, $actor): UserMembership {
            /** @var UserMembership $membership */
            $membership = new (Models::class('user_membership'))([
                'user_id' => $user->getKey(),
                'membership_plan_id' => $plan->getKey(),
                'provider' => 'paypal',
                'provider_subscription_id' => $subscriptionId,
                'status' => MembershipStatus::PENDING->value,
                'meta' => array_filter([
                    'linked_via' => 'dashboard_legacy_link',
                    'linked_by_user_id' => $actor?->getKey(),
                    'linked_at' => now()->toIso8601String(),
                ], static fn (mixed $value): bool => $value !== null),
            ]);
            $membership->save();

            $this->sync($subscription, 'ADMIN.SUBSCRIPTION.LINKED');

            return $membership->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function membershipFor(array $subscription, string $subscriptionId): ?UserMembership
    {
        $query = Models::class('user_membership')::query()->where('provider', 'paypal');

        /** @var UserMembership|null $membership */
        $membership = (clone $query)->where('provider_subscription_id', $subscriptionId)->latest('id')->first();

        if ($membership !== null) {
            return $membership;
        }

        $customId = trim((string) Arr::get($subscription, 'custom_id', ''));

        if (preg_match('/^membership:(\d+)$/', $customId, $found) !== 1) {
            return null;
        }

        /** @var UserMembership|null */
        return $query->whereKey((int) $found[1])->first();
    }

    private function planForPayPalPlan(string $planId): ?MembershipPlan
    {
        /** @var MembershipPlan|null */
        return Models::class('membership_plan')::query()
            ->whereHas('prices', fn ($query) => $query->where('provider_plan_id', $planId))
            ->first();
    }

    /**
     * A cancelled subscription keeps its access to the end of the period already paid for, so it
     * is canceling until then and canceled after; an expired one is canceled at once.
     */
    private function status(string $paypalStatus, string $eventType, ?Carbon $paidThrough): MembershipStatus
    {
        $status = strtoupper(trim($paypalStatus));

        if ($eventType === 'BILLING.SUBSCRIPTION.EXPIRED' || $status === 'EXPIRED') {
            return MembershipStatus::CANCELED;
        }

        if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED' || $status === 'CANCELLED') {
            return $paidThrough?->isFuture() ? MembershipStatus::CANCELING : MembershipStatus::CANCELED;
        }

        return match ($status) {
            'ACTIVE' => MembershipStatus::ACTIVE,
            'SUSPENDED' => MembershipStatus::PAST_DUE,
            default => MembershipStatus::PENDING,
        };
    }

    private function time(mixed $value): ?Carbon
    {
        return is_string($value) && trim($value) !== '' ? Carbon::parse($value) : null;
    }
}
