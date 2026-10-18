<?php

declare(strict_types=1);

namespace Filagora\Memberships\Services;

use DateTimeInterface;
use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\UserMembership;
use Illuminate\Database\Eloquent\Model;

/**
 * The changes staff make to a membership by hand. They change the membership here only: a
 * subscription at a billing provider is not touched, so ending one that is still billing leaves
 * the provider billing until it is cancelled there.
 */
class MembershipTransitions
{
    /**
     * Keep access until `$endsAt` (by default the end of the paid period) and no longer.
     *
     * @throws SubscriptionRefused
     */
    public function markCanceling(UserMembership $membership, ?DateTimeInterface $endsAt = null, ?Model $actor = null): UserMembership
    {
        $effective = $endsAt ?? $membership->current_period_end ?? $membership->ends_at;

        if (! $effective instanceof DateTimeInterface) {
            throw new SubscriptionRefused(SubscriptionRefusal::END_DATE_REQUIRED);
        }

        $membership->fill([
            'status' => MembershipStatus::CANCELING->value,
            'canceled_at' => $membership->canceled_at ?? now(),
            'ends_at' => $effective,
            'meta' => $this->metaWithActor($membership, 'dashboard_mark_canceling', $actor),
        ])->save();

        return $membership->refresh();
    }

    public function markCanceled(UserMembership $membership, ?Model $actor = null): UserMembership
    {
        $membership->fill([
            'status' => MembershipStatus::CANCELED->value,
            'canceled_at' => $membership->canceled_at ?? now(),
            'ends_at' => $membership->ends_at ?? now(),
            'meta' => $this->metaWithActor($membership, 'dashboard_mark_canceled', $actor),
        ])->save();

        return $membership->refresh();
    }

    /**
     * End access immediately, whatever was paid for.
     */
    public function endNow(UserMembership $membership, ?Model $actor = null): UserMembership
    {
        $endedAt = now();

        $membership->fill([
            'status' => MembershipStatus::CANCELED->value,
            'canceled_at' => $membership->canceled_at ?? $endedAt,
            'ends_at' => $endedAt,
            'current_period_end' => $endedAt,
            'meta' => $this->metaWithActor($membership, 'dashboard_end_now', $actor),
        ])->save();

        return $membership->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function metaWithActor(UserMembership $membership, string $action, ?Model $actor): array
    {
        return array_filter([
            ...($membership->meta ?? []),
            'dashboard_last_action' => $action,
            'dashboard_last_action_by_user_id' => $actor?->getKey(),
            'dashboard_last_action_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
