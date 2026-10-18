<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Entitlements\Services\EntitlementResolver;
use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Filament\RelationManagers\UserMembershipsRelationManager;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\EditMembershipPlan;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Support\Abilities;
use Filagora\Memberships\Tests\Support\FakeProvider;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Filagora\Memberships\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    PayPal::configure();
    $this->travelTo(now()->setTime(12, 0));
    $this->member = TestUser::make('Member');
    $this->staff = TestUser::make('Staff');
    UserEntitlementGrant::create(['user_id' => $this->staff->id, 'entitlement_key' => Abilities::MANAGE, 'grant_type' => 'manual']);
    actingAs($this->staff);
    $this->free = MembershipPlan::create(['code' => 'free', 'name' => 'Free', 'is_active' => true, 'is_free' => true, 'entitlements' => ['forums.a.view']]);
    $this->paid = PayPal::paidPlan(['code' => 'gold', 'name' => 'Gold']);
});

function managerFor(TestUser $owner): Testable
{
    return Livewire::test(UserMembershipsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => EditMembershipPlan::class]);
}

function heldMembership(TestUser $owner, MembershipPlan $plan, array $attributes = []): UserMembership
{
    return UserMembership::create(array_merge(['user_id' => $owner->id, 'membership_plan_id' => $plan->id, 'status' => 'active', 'provider' => 'paypal', 'provider_subscription_id' => 'I-'.uniqid()], $attributes));
}

it('shows the memberships only to people who may manage them', function () {
    expect(UserMembershipsRelationManager::canViewForRecord($this->member, EditMembershipPlan::class))->toBeTrue();

    actingAs(TestUser::make('Nobody'));

    expect(UserMembershipsRelationManager::canViewForRecord($this->member, EditMembershipPlan::class))->toBeFalse();
});

it('lists only the person\'s own memberships', function () {
    $mine = heldMembership($this->member, $this->paid);
    $theirs = heldMembership(TestUser::make('Other'), $this->paid);

    managerFor($this->member)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);
});

it('activates a free plan for the person and records who did it', function () {
    managerFor($this->member)
        ->callTableAction('create', null, ['membership_plan_id' => $this->free->id])
        ->assertHasNoTableActionErrors();

    $membership = UserMembership::firstWhere('user_id', $this->member->id);

    expect($membership->status)->toBe('active')
        ->and($membership->provider)->toBe('local')
        ->and($membership->meta['activated_by_user_id'])->toBe($this->staff->id)
        ->and($this->member->hasActiveMembership())->toBeTrue();
});

it('offers only active free plans to activate', function () {
    MembershipPlan::create(['code' => 'off', 'name' => 'Off', 'is_active' => false, 'is_free' => true]);

    managerFor($this->member)
        ->callTableAction('create', null, ['membership_plan_id' => $this->paid->id])
        ->assertHasTableActionErrors(['membership_plan_id']);

    expect(UserMembership::count())->toBe(0);
});

it('links a subscription from the old site and says so', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions/I-OLD' => Http::response(PayPal::subscription('I-OLD', ['plan_id' => 'P-USD']))]);

    managerFor($this->member)
        ->callTableAction('link_subscription', null, ['provider_subscription_id' => 'I-OLD'])
        ->assertNotified(__('filagora-memberships::messages.memberships.linked'));

    $membership = UserMembership::firstWhere('provider_subscription_id', 'I-OLD');

    expect($membership->user_id)->toBe($this->member->id)
        ->and($membership->status)->toBe('active')
        ->and($membership->meta['linked_by_user_id'])->toBe($this->staff->id);
});

it('explains why a subscription could not be linked', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions/I-GONE' => Http::response([], 404)]);

    managerFor($this->member)
        ->callTableAction('link_subscription', null, ['provider_subscription_id' => 'I-GONE'])
        ->assertNotified(__('filagora-memberships::messages.memberships.link_failed'));

    expect(UserMembership::count())->toBe(0);
});

it('does not offer to link a subscription when PayPal is not a provider', function () {
    $registry = new ProviderRegistry;
    $registry->register(new FakeProvider);
    app()->instance(ProviderRegistry::class, $registry);

    managerFor($this->member)->assertTableActionHidden('link_subscription');
});

it('marks an active membership canceling until a date, and records the action', function () {
    $membership = heldMembership($this->member, $this->paid, ['current_period_end' => now()->addDays(6)]);

    managerFor($this->member)
        ->callTableAction('mark_canceling', $membership, ['ends_at' => now()->addDays(3)->toDateTimeString()])
        ->assertNotified(__('filagora-memberships::messages.memberships.marked_canceling'));

    $membership->refresh();

    expect($membership->status)->toBe('canceling')
        ->and($membership->ends_at->equalTo(now()->addDays(3)))->toBeTrue()
        ->and($membership->meta['dashboard_last_action_by_user_id'])->toBe($this->staff->id);
});

it('marks a membership canceled, and ends one now', function () {
    $a = heldMembership($this->member, $this->paid, ['current_period_end' => now()->addDays(6)]);
    $b = heldMembership($this->member, $this->free, ['provider' => 'local', 'provider_subscription_id' => null, 'current_period_end' => now()->addDays(6)]);

    managerFor($this->member)
        ->callTableAction('mark_canceled', $a)
        ->callTableAction('end_now', $b);

    expect($a->refresh()->status)->toBe('canceled')
        ->and($b->refresh()->status)->toBe('canceled')
        ->and($b->ends_at->equalTo(now()))->toBeTrue()
        ->and($b->isCurrentlyActive())->toBeFalse();
});

it('only offers each action where it makes sense', function () {
    $pending = heldMembership($this->member, $this->paid, ['status' => 'pending']);
    $canceled = heldMembership($this->member, $this->paid, ['status' => 'canceled', 'ends_at' => now()->subDay()]);
    $active = heldMembership($this->member, $this->paid);

    managerFor($this->member)
        ->assertTableActionHidden('mark_canceling', $pending)
        ->assertTableActionHidden('mark_canceled', $canceled)
        ->assertTableActionHidden('end_now', $pending)
        ->assertTableActionHidden('end_now', $canceled)
        ->assertTableActionVisible('mark_canceling', $active)
        ->assertTableActionVisible('end_now', $active)
        ->assertTableActionHidden('delete', $active)
        ->assertTableActionVisible('delete', $pending);
});

it('links to the billing events about a membership only for people who may read them', function () {
    $membership = heldMembership($this->member, $this->paid, ['provider_subscription_id' => 'I-9']);

    managerFor($this->member)->assertTableActionHidden('billing_events', $membership);

    UserEntitlementGrant::create(['user_id' => $this->staff->id, 'entitlement_key' => Abilities::VIEW_BILLING, 'grant_type' => 'manual']);
    EntitlementResolver::class;
    app(EntitlementResolver::class)->flushMemoized();

    managerFor($this->member)->assertTableActionVisible('billing_events', $membership);
});

it('filters by status and provider', function () {
    $active = heldMembership($this->member, $this->paid);
    $local = heldMembership($this->member, $this->free, ['provider' => 'local', 'provider_subscription_id' => null, 'status' => 'canceled']);

    managerFor($this->member)
        ->filterTable('status', 'canceled')
        ->assertCanSeeTableRecords([$local])
        ->assertCanNotSeeTableRecords([$active])
        ->resetTableFilters()
        ->filterTable('provider', 'paypal')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$local]);
});
