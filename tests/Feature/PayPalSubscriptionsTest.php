<?php

declare(strict_types=1);

use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Providers\PayPal\PayPalSubscriptions;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    PayPal::configure();
    $this->travelTo(now()->setTime(12, 0));
    $this->subscriptions = app(PayPalSubscriptions::class);
    $this->plan = PayPal::paidPlan(['code' => 'chwm']);
});

function subRefusal(callable $callback): ?SubscriptionRefusal
{
    try {
        $callback();
    } catch (SubscriptionRefused $refused) {
        return $refused->reason;
    }

    return null;
}

it('activates the membership that holds the subscription and records what PayPal said', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'starts_at' => null]);

    $synced = $this->subscriptions->sync(PayPal::subscription('I-1'), 'BILLING.SUBSCRIPTION.ACTIVATED');

    expect($synced->is($membership))->toBeTrue();
    $membership->refresh();

    expect($membership->status)->toBe('active')
        ->and($membership->provider)->toBe('paypal')
        ->and($membership->subscription_name)->toBe('membership_chwm')
        ->and($membership->starts_at->toIso8601String())->toBe('2026-10-01T10:00:00+00:00')
        ->and($membership->current_period_start->toIso8601String())->toBe('2026-10-01T10:00:00+00:00')
        ->and($membership->current_period_end->toIso8601String())->toBe('2026-11-01T10:00:00+00:00')
        ->and($membership->ends_at)->toBeNull()
        ->and($membership->canceled_at)->toBeNull()
        ->and($membership->granted_entitlements_snapshot)->toBe(['forums.a.view'])
        ->and($membership->meta)->toMatchArray(['provider_price_id' => 'P-USD', 'payer_id' => 'PAYER-1', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'])
        ->and($membership->isCurrentlyActive())->toBeTrue();
});

it('finds the membership by the custom id this package sent when it does not know the subscription id yet', function () {
    $membership = PayPal::membership($this->plan);

    $this->subscriptions->sync(PayPal::subscription('I-9', ['custom_id' => 'membership:'.$membership->id]), 'BILLING.SUBSCRIPTION.CREATED');

    expect($membership->refresh()->provider_subscription_id)->toBe('I-9')
        ->and($membership->meta['custom_id'])->toBe('membership:'.$membership->id);
});

it('ignores a subscription nobody holds, instead of giving it to someone else', function () {
    $stranger = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-STRANGER', 'status' => 'pending']);

    expect($this->subscriptions->sync(PayPal::subscription('I-LEGACY'), 'BILLING.SUBSCRIPTION.ACTIVATED'))->toBeNull()
        ->and($stranger->refresh()->status)->toBe('pending')
        ->and($stranger->provider_subscription_id)->toBe('I-STRANGER')
        ->and(UserMembership::where('provider_subscription_id', 'I-LEGACY')->exists())->toBeFalse();
});

it('does not take a custom id for another provider\'s membership or a malformed one', function () {
    $local = PayPal::membership($this->plan, ['provider' => 'local']);
    $paypal = PayPal::membership($this->plan);

    expect($this->subscriptions->sync(PayPal::subscription('I-1', ['custom_id' => 'membership:'.$local->id]), 'x'))->toBeNull()
        ->and($this->subscriptions->sync(PayPal::subscription('I-2', ['custom_id' => 'membership:'.$paypal->id.' OR 1=1']), 'x'))->toBeNull()
        ->and($this->subscriptions->sync(PayPal::subscription('I-3', ['custom_id' => 'user:'.$paypal->id]), 'x'))->toBeNull()
        ->and($this->subscriptions->sync(['status' => 'ACTIVE'], 'x'))->toBeNull();
});

it('maps PayPal\'s subscription states to membership states', function (string $paypalStatus, string $event, string $expected) {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'current_period_end' => null]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => $paypalStatus, 'billing_info' => ['next_billing_time' => null]]), $event);

    expect($membership->refresh()->status)->toBe($expected);
})->with([
    'approval pending' => ['APPROVAL_PENDING', 'BILLING.SUBSCRIPTION.CREATED', 'pending'],
    'approved' => ['APPROVED', 'BILLING.SUBSCRIPTION.UPDATED', 'pending'],
    'active' => ['ACTIVE', 'BILLING.SUBSCRIPTION.ACTIVATED', 'active'],
    'suspended' => ['SUSPENDED', 'BILLING.SUBSCRIPTION.SUSPENDED', 'past_due'],
    'cancelled' => ['CANCELLED', 'BILLING.SUBSCRIPTION.CANCELLED', 'canceled'],
    'expired' => ['EXPIRED', 'BILLING.SUBSCRIPTION.EXPIRED', 'canceled'],
    'cancelled by status alone' => ['CANCELLED', 'BILLING.SUBSCRIPTION.UPDATED', 'canceled'],
    'an unknown state' => ['SOMETHING_NEW', 'BILLING.SUBSCRIPTION.UPDATED', 'pending'],
]);

it('lets a cancelled subscription keep its access to the end of the period that was paid for', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'active', 'current_period_end' => now()->addDays(10)]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'CANCELLED', 'billing_info' => ['next_billing_time' => null]]), 'BILLING.SUBSCRIPTION.CANCELLED');
    $membership->refresh();

    expect($membership->status)->toBe('canceling')
        ->and($membership->ends_at->equalTo(now()->addDays(10)))->toBeTrue()
        ->and($membership->current_period_end->equalTo(now()->addDays(10)))->toBeTrue()
        ->and($membership->canceled_at->toIso8601String())->toBe('2026-10-17T09:00:00+00:00')
        ->and($membership->isCurrentlyActive())->toBeTrue();

    $this->travel(11)->days();

    expect($membership->isCurrentlyActive())->toBeFalse();
});

it('ends a cancelled subscription at once when the paid period is already over', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'active', 'current_period_end' => now()->subDay()]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'CANCELLED', 'billing_info' => ['next_billing_time' => null]]), 'BILLING.SUBSCRIPTION.CANCELLED');
    $membership->refresh();

    expect($membership->status)->toBe('canceled')
        ->and($membership->ends_at->toIso8601String())->toBe('2026-10-17T09:00:00+00:00')
        ->and($membership->isCurrentlyActive())->toBeFalse();
});

it('keeps the cancellation time the person caused when PayPal confirms it', function () {
    $canceledAt = now()->subHour();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'canceling', 'canceled_at' => $canceledAt, 'current_period_end' => now()->addDays(3)]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'CANCELLED']), 'BILLING.SUBSCRIPTION.CANCELLED');

    expect($membership->refresh()->canceled_at->equalTo($canceledAt))->toBeTrue();
});

it('ends an expired subscription at once even with time left', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'active', 'current_period_end' => now()->addDays(10)]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'EXPIRED']), 'BILLING.SUBSCRIPTION.EXPIRED');
    $membership->refresh();

    expect($membership->status)->toBe('canceled')
        ->and($membership->isCurrentlyActive())->toBeFalse();
});

it('clears the cancellation when a subscription comes back to life', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'canceled', 'canceled_at' => now()->subDay(), 'ends_at' => now()->subDay()]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'ACTIVE']), 'BILLING.SUBSCRIPTION.ACTIVATED');
    $membership->refresh();

    expect($membership->status)->toBe('active')
        ->and($membership->canceled_at)->toBeNull()
        ->and($membership->ends_at)->toBeNull();
});

it('refreshes the entitlements the membership grants from its plan, and keeps its first start', function () {
    $started = now()->subYear();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'starts_at' => $started, 'granted_entitlements_snapshot' => ['old.key']]);
    $this->plan->update(['entitlements' => ['forums.new.view', 'forums.new.reply']]);

    $this->subscriptions->sync(PayPal::subscription('I-1'), 'BILLING.SUBSCRIPTION.UPDATED');
    $membership->refresh();

    expect($membership->granted_entitlements_snapshot)->toBe(['forums.new.view', 'forums.new.reply'])
        ->and($membership->starts_at->equalTo($started))->toBeTrue();
});

it('keeps the subscription name it was started under, and leaves blank values out of the metadata', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'meta' => ['subscription_name' => 'zc_chwm', 'currency' => 'EUR']]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['subscriber' => ['payer_id' => '']]), 'BILLING.SUBSCRIPTION.UPDATED');
    $membership->refresh();

    expect($membership->subscription_name)->toBe('zc_chwm')
        ->and($membership->meta)->toHaveKey('currency', 'EUR')
        ->and($membership->meta)->not->toHaveKey('payer_id')
        ->and($membership->meta)->not->toHaveKey('custom_id');
});

it('links a subscription from the old site to a person and activates it', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions/I-OLD' => Http::response(PayPal::subscription('I-OLD', ['plan_id' => 'P-EUR']))]);
    $user = TestUser::make('Member');
    $admin = TestUser::make('Admin');

    $membership = $this->subscriptions->link($user, ' I-OLD ', $admin);

    expect($membership->user_id)->toBe($user->id)
        ->and($membership->membership_plan_id)->toBe($this->plan->id)
        ->and($membership->provider)->toBe('paypal')
        ->and($membership->provider_subscription_id)->toBe('I-OLD')
        ->and($membership->status)->toBe('active')
        ->and($membership->meta)->toMatchArray(['linked_via' => 'dashboard_legacy_link', 'linked_by_user_id' => $admin->id, 'event_type' => 'ADMIN.SUBSCRIPTION.LINKED'])
        ->and(UserMembership::count())->toBe(1);
});

it('refuses to link what cannot be linked', function () {
    PayPal::fakeHttp([
        'paypal.test/v1/billing/subscriptions/I-LINKED' => Http::response(PayPal::subscription('I-LINKED')),
        'paypal.test/v1/billing/subscriptions/I-GONE' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404),
        'paypal.test/v1/billing/subscriptions/I-DOWN' => Http::response([], 503),
        'paypal.test/v1/billing/subscriptions/I-STRANGE' => Http::response(PayPal::subscription('I-STRANGE', ['plan_id' => 'P-NOPE'])),
    ]);
    $user = TestUser::make('Member');
    PayPal::membership($this->plan, ['provider_subscription_id' => 'I-LINKED']);

    expect(subRefusal(fn () => $this->subscriptions->link($user, '  ')))->toBe(SubscriptionRefusal::UNKNOWN_SUBSCRIPTION)
        ->and(subRefusal(fn () => $this->subscriptions->link($user, 'I-LINKED')))->toBe(SubscriptionRefusal::ALREADY_LINKED)
        ->and(subRefusal(fn () => $this->subscriptions->link($user, 'I-GONE')))->toBe(SubscriptionRefusal::UNKNOWN_SUBSCRIPTION)
        ->and(subRefusal(fn () => $this->subscriptions->link($user, 'I-DOWN')))->toBe(SubscriptionRefusal::PROVIDER_FAILED)
        ->and(subRefusal(fn () => $this->subscriptions->link($user, 'I-STRANGE')))->toBe(SubscriptionRefusal::UNKNOWN_PLAN)
        ->and(UserMembership::count())->toBe(1);
});

it('goes by the cancel event when the resource it carries still says active', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1', 'status' => 'active', 'current_period_end' => now()->addDays(5)]);

    $this->subscriptions->sync(PayPal::subscription('I-1', ['status' => 'ACTIVE', 'billing_info' => ['next_billing_time' => null]]), 'BILLING.SUBSCRIPTION.CANCELLED');
    $membership->refresh();

    expect($membership->status)->toBe('canceling')
        ->and($membership->ends_at->equalTo(now()->addDays(5)))->toBeTrue();
});
