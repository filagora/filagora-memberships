<?php

declare(strict_types=1);

use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\MembershipTransitions;
use Filagora\Memberships\Tests\Support\TestUser;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->transitions = app(MembershipTransitions::class);
    $this->admin = TestUser::make('Admin');
    $this->plan = MembershipPlan::create(['code' => 'p', 'name' => 'P', 'is_free' => true]);
});

function transitionMembership(array $attributes = []): UserMembership
{
    return UserMembership::create(array_merge([
        'user_id' => TestUser::make()->id,
        'membership_plan_id' => MembershipPlan::first()->id,
        'status' => 'active',
        'provider' => 'paypal',
        'provider_subscription_id' => 'I-'.uniqid(),
    ], $attributes));
}

it('marks a membership canceling until a chosen date, and records who did it', function () {
    $membership = transitionMembership(['meta' => ['keep' => 'me']]);

    $result = $this->transitions->markCanceling($membership, now()->addDays(4), $this->admin);

    expect($result->status)->toBe('canceling')
        ->and($result->ends_at->equalTo(now()->addDays(4)))->toBeTrue()
        ->and($result->canceled_at->equalTo(now()))->toBeTrue()
        ->and($result->meta)->toMatchArray(['keep' => 'me', 'dashboard_last_action' => 'dashboard_mark_canceling', 'dashboard_last_action_by_user_id' => $this->admin->id])
        ->and($result->isCurrentlyActive())->toBeTrue();
});

it('defaults the end of a canceling membership to the paid period, then to its end date', function () {
    $paid = transitionMembership(['current_period_end' => now()->addDays(9), 'ends_at' => now()->addDays(2)]);
    $dated = transitionMembership(['ends_at' => now()->addDays(2)]);

    expect($this->transitions->markCanceling($paid)->ends_at->equalTo(now()->addDays(9)))->toBeTrue()
        ->and($this->transitions->markCanceling($dated)->ends_at->equalTo(now()->addDays(2)))->toBeTrue();
});

it('refuses to mark a membership canceling without any end date', function () {
    $reason = null;

    try {
        $this->transitions->markCanceling(transitionMembership());
    } catch (SubscriptionRefused $refused) {
        $reason = $refused->reason;
    }

    expect($reason)->toBe(SubscriptionRefusal::END_DATE_REQUIRED);
});

it('keeps the first cancellation time when marking canceling again', function () {
    $first = now()->subDays(2);
    $membership = transitionMembership(['canceled_at' => $first]);

    expect($this->transitions->markCanceling($membership, now()->addDay())->canceled_at->equalTo($first))->toBeTrue();
});

it('marks a membership canceled, keeping an end date it already has', function () {
    $dated = transitionMembership(['ends_at' => now()->addDays(3)]);
    $open = transitionMembership();

    $a = $this->transitions->markCanceled($dated, $this->admin);
    $b = $this->transitions->markCanceled($open);

    expect($a->status)->toBe('canceled')
        ->and($a->ends_at->equalTo(now()->addDays(3)))->toBeTrue()
        ->and($a->meta['dashboard_last_action'])->toBe('dashboard_mark_canceled')
        ->and($b->ends_at->equalTo(now()))->toBeTrue()
        ->and($b->canceled_at->equalTo(now()))->toBeTrue();
});

it('ends a membership immediately whatever was paid for', function () {
    $membership = transitionMembership(['current_period_end' => now()->addDays(20), 'ends_at' => now()->addDays(20)]);

    $result = $this->transitions->endNow($membership, $this->admin);

    expect($result->status)->toBe('canceled')
        ->and($result->ends_at->equalTo(now()))->toBeTrue()
        ->and($result->current_period_end->equalTo(now()))->toBeTrue()
        ->and($result->isCurrentlyActive())->toBeFalse()
        ->and($result->meta['dashboard_last_action'])->toBe('dashboard_end_now');
});

it('leaves the actor out of the record when there is none', function () {
    $result = $this->transitions->markCanceled(transitionMembership());

    expect($result->meta)->not->toHaveKey('dashboard_last_action_by_user_id');
});

it('gives the user model its memberships, plans, and the two membership questions', function () {
    $user = TestUser::make('Member');
    $other = MembershipPlan::create(['code' => 'other', 'name' => 'Other', 'is_free' => true]);
    $membership = UserMembership::create(['user_id' => $user->id, 'membership_plan_id' => $this->plan->id, 'status' => 'active', 'provider' => 'local']);

    expect($user->userMemberships->pluck('id')->all())->toBe([$membership->id])
        ->and($user->membershipPlans->pluck('id')->all())->toBe([$this->plan->id])
        ->and($user->membershipPlans->first()->pivot->status)->toBe('active')
        ->and($user->hasActiveMembership())->toBeTrue()
        ->and($user->hasActiveMembershipForPlan($this->plan->id))->toBeTrue()
        ->and($user->hasActiveMembershipForPlan($other->id))->toBeFalse()
        ->and(TestUser::make('Nobody')->hasActiveMembership())->toBeFalse();

    $membership->update(['status' => 'canceled', 'ends_at' => now()->subDay()]);

    expect($user->refresh()->hasActiveMembership())->toBeFalse();
});
