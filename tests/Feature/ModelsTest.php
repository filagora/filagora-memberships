<?php

declare(strict_types=1);

use Filagora\Memberships\Enums\BillingInterval;
use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Support\Models;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
});

function plan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::create(array_merge([
        'code' => 'plan_'.uniqid(),
        'name' => 'Plan',
        'is_free' => false,
        'provider' => 'paypal',
        'billing_interval' => 'monthly',
    ], $attributes));
}

function priceOf(MembershipPlan $plan, string $currency, string $amount, array $attributes = []): MembershipPlanPrice
{
    return $plan->prices()->create(array_merge(['currency' => $currency, 'amount' => $amount], $attributes));
}

function membershipFor(MembershipPlan $plan, array $attributes = []): UserMembership
{
    return UserMembership::create(array_merge([
        'user_id' => TestUser::make()->id,
        'membership_plan_id' => $plan->id,
        'status' => 'active',
    ], $attributes));
}

it('finds a plan by its code', function () {
    $plan = plan(['code' => 'chwm']);

    expect($plan->getRouteKeyName())->toBe('code')
        ->and(MembershipPlan::where('code', 'chwm')->first()->is($plan))->toBeTrue();
});

it('cleans the entitlement and feature lists', function () {
    $plan = plan(['entitlements' => [' forums.a.view ', '', 'forums.b.reply'], 'feature_list' => ['  Fast  ', ' ']]);

    expect($plan->entitlementList())->toBe(['forums.a.view', 'forums.b.reply'])
        ->and($plan->featureList())->toBe(['Fast'])
        ->and(plan()->entitlementList())->toBe([]);
});

it('offers the default price, or the first when none is marked, and a price by currency in any case', function () {
    $plan = plan();
    priceOf($plan, 'USD', '5.00');
    $euro = priceOf($plan, 'EUR', '4.50', ['is_default' => true]);

    expect($plan->defaultPrice()->is($euro))->toBeTrue()
        ->and($plan->priceFor(' usd ')->currency)->toBe('USD')
        ->and($plan->priceFor('GBP'))->toBeNull();

    $unmarked = plan();
    $first = priceOf($unmarked, 'USD', '1.00');
    priceOf($unmarked, 'EUR', '1.00');

    expect($unmarked->defaultPrice()->is($first))->toBeTrue();
});

it('keeps one price per currency on a plan', function () {
    $plan = plan();
    priceOf($plan, 'USD', '5.00');

    expect(fn () => priceOf($plan, 'USD', '6.00'))->toThrow(QueryException::class);
});

it('formats and reports the state of a price', function () {
    $price = priceOf(plan(), 'usd', '5');

    expect($price->formattedAmount())->toBe('USD 5.00')
        ->and($price->isSyncedWithProvider())->toBeFalse();

    $price->update(['provider_plan_id' => ' P-1 ']);

    expect($price->isSyncedWithProvider())->toBeTrue();
});

it('is ready for checkout when free, or when active with a provider and a price that exists there', function () {
    expect(plan(['is_free' => true, 'is_active' => false, 'provider' => null])->isCheckoutReady())->toBeTrue();

    $paid = plan(['is_active' => true]);
    expect($paid->isCheckoutReady())->toBeFalse();

    priceOf($paid, 'USD', '5.00');
    expect($paid->isCheckoutReady())->toBeFalse();

    $paid->prices()->update(['provider_plan_id' => '']);
    expect($paid->isCheckoutReady())->toBeFalse();

    $paid->prices()->update(['provider_plan_id' => 'P-1']);
    expect($paid->isCheckoutReady())->toBeTrue();

    $paid->update(['is_active' => false]);
    expect($paid->isCheckoutReady())->toBeFalse();

    $paid->update(['is_active' => true, 'provider' => null]);
    expect($paid->isCheckoutReady())->toBeFalse();
});

it('labels the billing interval, keeping a value it does not know', function () {
    expect(plan(['billing_interval' => 'quarterly'])->billingIntervalLabel())->toBe('Quarterly')
        ->and(plan(['billing_interval' => 'fortnightly'])->billingIntervalLabel())->toBe('Fortnightly')
        ->and(plan(['billing_interval' => null])->billingIntervalLabel())->toBeNull();
});

it('treats active and trialing memberships as current until they end', function () {
    $plan = plan();

    expect(membershipFor($plan, ['status' => 'active'])->isCurrentlyActive())->toBeTrue()
        ->and(membershipFor($plan, ['status' => 'trialing'])->isCurrentlyActive())->toBeTrue()
        ->and(membershipFor($plan, ['status' => 'active', 'ends_at' => now()->subMinute()])->isCurrentlyActive())->toBeFalse()
        ->and(membershipFor($plan, ['status' => 'active', 'ends_at' => now()->addDay()])->isCurrentlyActive())->toBeTrue();
});

it('keeps a canceling membership until the period it paid for ends', function () {
    $plan = plan();

    expect(membershipFor($plan, ['status' => 'canceling', 'current_period_end' => now()->addDays(3)])->isCurrentlyActive())->toBeTrue()
        ->and(membershipFor($plan, ['status' => 'canceling', 'current_period_end' => now()->subDay()])->isCurrentlyActive())->toBeFalse()
        ->and(membershipFor($plan, ['status' => 'canceling', 'ends_at' => now()->addDay()])->isCurrentlyActive())->toBeTrue()
        ->and(membershipFor($plan, ['status' => 'canceling', 'ends_at' => now()->subDay()])->isCurrentlyActive())->toBeFalse();
});

it('does not count pending, canceled, past due or unpaid memberships', function () {
    $plan = plan();

    foreach (['pending', 'canceled', 'past_due', 'unpaid', 'incomplete', 'incomplete_expired'] as $status) {
        expect(membershipFor($plan, ['status' => $status])->isCurrentlyActive())->toBeFalse($status);
    }
});

it('selects the same memberships in the active scope as the instance check', function () {
    $plan = plan();
    $cases = [
        ['status' => 'active'],
        ['status' => 'active', 'ends_at' => now()->subHour()],
        ['status' => 'trialing'],
        ['status' => 'canceling', 'current_period_end' => now()->addDay(), 'ends_at' => now()->addDay()],
        ['status' => 'canceling', 'current_period_end' => now()->subDay(), 'ends_at' => now()->subDay()],
        ['status' => 'pending'],
        ['status' => 'canceled', 'ends_at' => now()->subDay()],
    ];

    $memberships = array_map(fn (array $attributes) => membershipFor($plan, $attributes), $cases);
    $inScope = UserMembership::active()->pluck('id')->all();

    foreach ($memberships as $membership) {
        expect(in_array($membership->id, $inScope, true))->toBe($membership->isCurrentlyActive(), $membership->status.' #'.$membership->id);
    }
});

it('reaches the plan and the person from a membership, and removes memberships with their plan', function () {
    $plan = plan();
    $membership = membershipFor($plan);

    expect($membership->plan->is($plan))->toBeTrue()
        ->and($membership->user)->toBeInstanceOf(TestUser::class)
        ->and($plan->userMemberships()->count())->toBe(1);

    $plan->delete();

    expect(UserMembership::count())->toBe(0);
});

it('keeps a provider subscription id on one membership only', function () {
    $plan = plan();
    membershipFor($plan, ['provider' => 'paypal', 'provider_subscription_id' => 'I-1']);

    expect(fn () => membershipFor($plan, ['provider' => 'paypal', 'provider_subscription_id' => 'I-1']))->toThrow(QueryException::class);

    membershipFor($plan, ['provider' => 'local']);
    membershipFor($plan, ['provider' => 'local']);

    expect(UserMembership::whereNull('provider_subscription_id')->count())->toBe(2);
});

it('keeps a billing event once per provider, and reads ids out of a PayPal payload', function () {
    $log = BillingEventLog::create([
        'provider' => 'paypal',
        'event_id' => 'WH-1',
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'payload' => ['resource' => ['id' => ' I-9 ', 'custom_id' => 'membership:4']],
    ]);

    expect($log->processing_status)->toBeNull();
    $log->refresh();

    expect($log->processing_status)->toBe('pending')
        ->and($log->paypalResourceId())->toBe('I-9')
        ->and($log->paypalCustomId())->toBe('membership:4')
        ->and(fn () => BillingEventLog::create(['provider' => 'paypal', 'event_id' => 'WH-1', 'event_type' => 'x', 'payload' => []]))->toThrow(QueryException::class);

    BillingEventLog::create(['provider' => 'stripe', 'event_id' => 'WH-1', 'event_type' => 'x', 'payload' => []]);

    expect(BillingEventLog::count())->toBe(2)
        ->and(BillingEventLog::create(['provider' => 'paypal', 'event_id' => 'WH-2', 'event_type' => 'x', 'payload' => []])->paypalResourceId())->toBeNull();
});

it('lists the enum values as options', function () {
    expect(MembershipStatus::options())->toHaveKeys(['pending', 'active', 'trialing', 'canceling', 'canceled', 'past_due', 'unpaid', 'incomplete', 'incomplete_expired'])
        ->and(BillingInterval::options())->toBe(['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'])
        ->and(EventStatus::PROCESSED->label())->toBe('Processed');
});

it('uses a host subclass registered under the model key', function () {
    expect(Models::class('membership_plan'))->toBe(MembershipPlan::class);

    config(['filagora.models.membership_plan' => HostPlan::class]);

    expect(Models::class('membership_plan'))->toBe(HostPlan::class)
        ->and(membershipFor(plan())->plan)->toBeInstanceOf(HostPlan::class);

    expect(fn () => Models::class('nonsense'))->toThrow(InvalidArgumentException::class);
});

class HostPlan extends MembershipPlan {}
