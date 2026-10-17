<?php

declare(strict_types=1);

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Events\MembershipChanged;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Tests\Support\FakeProvider;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->provider = new FakeProvider;
    app(ProviderRegistry::class)->register($this->provider);
    $this->service = app(Memberships::class);
    $this->user = TestUser::make('Member');
});

function svcPlan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::create(array_merge([
        'code' => 'plan_'.uniqid(),
        'name' => 'Plan',
        'is_active' => true,
        'is_free' => false,
        'provider' => 'fake',
        'billing_interval' => 'monthly',
        'entitlements' => ['forums.a.view'],
    ], $attributes));
}

function svcPaidPlan(array $attributes = []): MembershipPlan
{
    $plan = svcPlan($attributes);
    $plan->prices()->create(['currency' => 'USD', 'amount' => '5.00', 'provider_plan_id' => 'P-USD', 'is_default' => true]);
    $plan->prices()->create(['currency' => 'EUR', 'amount' => '4.50', 'provider_plan_id' => 'P-EUR']);

    return $plan->refresh();
}

function refusalOf(callable $callback): ?SubscriptionRefusal
{
    try {
        $callback();
    } catch (SubscriptionRefused $refused) {
        return $refused->reason;
    }

    return null;
}

it('lists only active plans, by sort order and then name', function () {
    svcPlan(['name' => 'Zed', 'sort_order' => 1]);
    svcPlan(['name' => 'Alpha', 'sort_order' => 2]);
    svcPlan(['name' => 'Beta', 'sort_order' => 1]);
    svcPlan(['name' => 'Hidden', 'is_active' => false]);

    expect($this->service->availablePlans()->pluck('name')->all())->toBe(['Beta', 'Zed', 'Alpha']);
});

it('activates a free plan at once and keeps one local membership per plan', function () {
    $plan = svcPlan(['is_free' => true, 'provider' => null, 'code' => 'Free Tier']);

    $result = $this->service->subscribe($this->user, $plan);

    expect($result->requiresRedirect())->toBeFalse()
        ->and($result->membership->status)->toBe('active')
        ->and($result->membership->provider)->toBe('local')
        ->and($result->membership->subscription_name)->toBe('membership_free_tier')
        ->and($result->membership->granted_entitlements_snapshot)->toBe(['forums.a.view'])
        ->and($result->membership->meta)->toBe(['subscribed_via' => 'self_service'])
        ->and($this->service->hasActive($this->user))->toBeTrue();

    $started = $result->membership->starts_at;
    $this->travel(2)->days();
    $this->service->cancel($this->user, $result->membership);
    $again = $this->service->subscribe($this->user, $plan)->membership;

    expect($again->is($result->membership))->toBeTrue()
        ->and($again->status)->toBe('active')
        ->and($again->canceled_at)->toBeNull()
        ->and($again->ends_at)->toBeNull()
        ->and($again->starts_at->equalTo($started))->toBeTrue()
        ->and(UserMembership::count())->toBe(1);
});

it('refuses a plan that is not active, even a free one', function () {
    expect(refusalOf(fn () => $this->service->subscribe($this->user, svcPlan(['is_active' => false, 'is_free' => true]))))->toBe(SubscriptionRefusal::PLAN_INACTIVE)
        ->and(refusalOf(fn () => $this->service->subscribe($this->user, svcPaidPlan(['is_active' => false]))))->toBe(SubscriptionRefusal::PLAN_INACTIVE)
        ->and(UserMembership::count())->toBe(0);
});

it('refuses a paid plan that is not ready, or whose chosen price is not at the provider', function () {
    expect(refusalOf(fn () => $this->service->subscribe($this->user, svcPlan())))->toBe(SubscriptionRefusal::NOT_CONFIGURED);

    $plan = svcPaidPlan();
    $plan->prices()->where('currency', 'USD')->update(['provider_plan_id' => null]);

    expect(refusalOf(fn () => $this->service->subscribe($this->user, $plan->refresh())))->toBe(SubscriptionRefusal::NOT_CONFIGURED)
        ->and(UserMembership::count())->toBe(0);
});

it('refuses a plan whose provider is unknown or not set up, before creating anything', function () {
    expect(refusalOf(fn () => $this->service->subscribe($this->user, svcPaidPlan(['provider' => 'carrier_pigeon']))))->toBe(SubscriptionRefusal::UNSUPPORTED_PROVIDER);

    $this->provider->configured = false;

    expect(refusalOf(fn () => $this->service->subscribe($this->user, svcPaidPlan())))->toBe(SubscriptionRefusal::PROVIDER_NOT_CONFIGURED)
        ->and(UserMembership::count())->toBe(0);
});

it('starts a paid membership as pending and sends the person to the provider', function () {
    $plan = svcPaidPlan(['code' => 'chwm']);

    $result = $this->service->subscribe($this->user, $plan, 'eur', 'https://site.test/done', 'https://site.test/back');
    $membership = $result->membership->refresh();

    expect($result->requiresRedirect())->toBeTrue()
        ->and($result->redirectUrl)->toBe('https://pay.test/approve/'.$membership->id)
        ->and($membership->status)->toBe('pending')
        ->and($membership->provider)->toBe('fake')
        ->and($membership->subscription_name)->toBe('membership_chwm')
        ->and($membership->provider_subscription_id)->toBe('SUB-'.$membership->id)
        ->and($membership->granted_entitlements_snapshot)->toBe(['forums.a.view'])
        ->and($membership->meta)->toMatchArray(['provider_price_id' => 'P-EUR', 'currency' => 'EUR', 'subscribed_via' => 'fake_checkout', 'fake_status' => 'APPROVAL_PENDING'])
        ->and($this->provider->subscribed)->toBe([['membership' => $membership->id, 'price' => 'P-EUR', 'return' => 'https://site.test/done', 'cancel' => 'https://site.test/back']])
        ->and($this->service->hasActive($this->user))->toBeFalse();
});

it('uses the default price when no currency is chosen or the currency is not offered', function () {
    $plan = svcPaidPlan();

    $this->service->subscribe($this->user, $plan);
    $this->service->subscribe(TestUser::make('Other'), $plan, 'JPY');

    expect(array_column($this->provider->subscribed, 'price'))->toBe(['P-USD', 'P-USD']);
});

it('reuses the pending membership when the same person starts the same plan again', function () {
    $plan = svcPaidPlan();

    $first = $this->service->subscribe($this->user, $plan)->membership;
    $second = $this->service->subscribe($this->user, $plan)->membership;

    expect($second->is($first))->toBeTrue()
        ->and(UserMembership::count())->toBe(1);
});

it('resolves the return pages from arguments, then config, as a URL or a route name', function () {
    $plan = svcPaidPlan();

    $this->service->subscribe($this->user, $plan);
    expect($this->provider->subscribed[0]['return'])->toBe(url('/'));

    Route::get('/billing/done', fn () => 'ok')->name('billing.done');
    Route::getRoutes()->refreshNameLookups();
    config(['filagora-memberships.checkout.return_url' => 'billing.done', 'filagora-memberships.checkout.cancel_url' => 'https://site.test/cancelled']);
    $this->service->subscribe($this->user, $plan);

    expect($this->provider->subscribed[1]['return'])->toBe(route('billing.done'))
        ->and($this->provider->subscribed[1]['cancel'])->toBe('https://site.test/cancelled');

    $this->service->subscribe($this->user, $plan, null, 'https://site.test/override');
    expect($this->provider->subscribed[2]['return'])->toBe('https://site.test/override');
});

it('reports a provider failure, refuses, and leaves the membership pending without a subscription id', function () {
    Exceptions::fake();
    $this->provider->failing = true;

    expect(refusalOf(fn () => $this->service->subscribe($this->user, svcPaidPlan())))->toBe(SubscriptionRefusal::PROVIDER_FAILED);

    Exceptions::assertReported(ProviderFailed::class);
    $membership = UserMembership::first();

    expect($membership->status)->toBe('pending')
        ->and($membership->provider_subscription_id)->toBeNull();
});

it('cancels a provider-billed membership at the provider and keeps access to the period end', function () {
    $membership = $this->service->subscribe($this->user, svcPaidPlan())->membership;
    $membership->update(['status' => 'active', 'current_period_end' => now()->addDays(10)]);

    $canceled = $this->service->cancel($this->user, $membership);

    expect($this->provider->canceled)->toBe([$membership->id])
        ->and($canceled->status)->toBe('canceling')
        ->and($canceled->canceled_at)->not->toBeNull()
        ->and($canceled->ends_at->equalTo(now()->addDays(10)))->toBeTrue()
        ->and($canceled->isCurrentlyActive())->toBeTrue();
});

it('ends a local membership at once', function () {
    $membership = $this->service->activateLocal($this->user, svcPlan(['is_free' => true, 'provider' => null]));

    $canceled = $this->service->cancel($this->user, $membership);

    expect($canceled->status)->toBe('canceled')
        ->and($canceled->ends_at->equalTo(now()))->toBeTrue()
        ->and($this->service->hasActive($this->user))->toBeFalse();
});

it('refuses to cancel someone else\'s membership', function () {
    $membership = $this->service->subscribe($this->user, svcPaidPlan())->membership;

    expect(refusalOf(fn () => $this->service->cancel(TestUser::make('Other'), $membership)))->toBe(SubscriptionRefusal::NOT_YOUR_MEMBERSHIP)
        ->and($this->provider->canceled)->toBe([])
        ->and($membership->refresh()->status)->toBe('pending');
});

it('leaves a membership alone when the provider cannot cancel it, or is not registered', function () {
    Exceptions::fake();
    $membership = $this->service->subscribe($this->user, svcPaidPlan())->membership;
    $membership->update(['status' => 'active']);

    $this->provider->failing = true;
    expect(refusalOf(fn () => $this->service->cancel($this->user, $membership)))->toBe(SubscriptionRefusal::PROVIDER_FAILED)
        ->and($membership->refresh()->status)->toBe('active');

    $membership->update(['provider' => 'stripe', 'provider_subscription_id' => 'sub_123']);
    expect(refusalOf(fn () => $this->service->cancel($this->user, $membership)))->toBe(SubscriptionRefusal::UNSUPPORTED_PROVIDER)
        ->and($membership->refresh()->status)->toBe('active');
});

it('activates a free plan from the dashboard and records who did it', function () {
    $admin = TestUser::make('Admin');
    $plan = svcPlan(['is_free' => true, 'provider' => null]);

    $membership = $this->service->activateLocal($this->user, $plan, $admin);

    expect($membership->status)->toBe('active')
        ->and($membership->meta)->toBe(['activated_via' => 'dashboard', 'activated_by_user_id' => $admin->id])
        ->and($this->service->activateLocal($this->user, $plan)->is($membership))->toBeTrue()
        ->and(UserMembership::count())->toBe(1);
});

it('only activates active free plans from the dashboard', function () {
    expect(refusalOf(fn () => $this->service->activateLocal($this->user, svcPaidPlan())))->toBe(SubscriptionRefusal::PLAN_NOT_FREE)
        ->and(refusalOf(fn () => $this->service->activateLocal($this->user, svcPlan(['is_free' => true, 'is_active' => false]))))->toBe(SubscriptionRefusal::PLAN_INACTIVE);
});

it('names a subscription from the prefix and the plan code', function () {
    expect($this->service->subscriptionNameFor(svcPlan(['code' => 'CHWM Monthly'])))->toBe('membership_chwm_monthly');

    config(['filagora-memberships.subscription_prefix' => '']);
    expect($this->service->subscriptionNameFor(svcPlan(['code' => 'chwm'])))->toBe('chwm');

    config(['filagora-memberships.subscription_prefix' => 'zc']);
    $plan = svcPlan(['code' => '---']);
    expect($this->service->subscriptionNameFor($plan))->toBe('zc_plan_'.$plan->id);
});

it('lists only the memberships that currently give access', function () {
    $free = $this->service->activateLocal($this->user, svcPlan(['is_free' => true, 'provider' => null, 'name' => 'Free']));
    $this->service->subscribe($this->user, svcPaidPlan());

    expect($this->service->currentFor($this->user)->pluck('id')->all())->toBe([$free->id])
        ->and($this->service->currentFor(TestUser::make('Nobody')))->toBeEmpty();
});

it('announces a membership that is created or whose access changes, and one that is deleted', function () {
    Event::fake([MembershipChanged::class]);
    $membership = $this->service->activateLocal($this->user, svcPlan(['is_free' => true, 'provider' => null]));

    Event::assertDispatched(MembershipChanged::class, fn ($event) => $event->membership->is($membership) && $event->previousStatus === null && ! $event->deleted);

    $membership->update(['status' => 'canceled']);
    Event::assertDispatched(MembershipChanged::class, fn ($event) => $event->previousStatus === 'active' && $event->membership->status === 'canceled');

    $membership->update(['meta' => ['note' => 'unrelated']]);
    Event::assertDispatchedTimes(MembershipChanged::class, 2);

    $membership->delete();
    Event::assertDispatched(MembershipChanged::class, fn ($event) => $event->deleted && $event->previousStatus === 'canceled');
});

it('announces a change to each of the things that decide what a membership grants, and to nothing else', function () {
    $membership = $this->service->activateLocal($this->user, svcPlan(['is_free' => true, 'provider' => null]));
    $other = svcPlan(['is_free' => true, 'provider' => null]);

    Event::fake([MembershipChanged::class]);

    foreach ([
        ['ends_at' => now()->addDay()],
        ['current_period_end' => now()->addDays(2)],
        ['granted_entitlements_snapshot' => ['forums.b.view']],
        ['membership_plan_id' => $other->id],
    ] as $change) {
        $membership->update($change);
    }

    Event::assertDispatchedTimes(MembershipChanged::class, 4);

    $membership->update(['subscription_name' => 'renamed', 'meta' => ['x' => 1], 'canceled_at' => now(), 'trial_ends_at' => now()]);

    Event::assertDispatchedTimes(MembershipChanged::class, 4);
});
