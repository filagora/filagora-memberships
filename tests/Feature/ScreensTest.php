<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\UserEntitlementGrant;
use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Filament\Resources\BillingEvents\BillingEventResource;
use Filagora\Memberships\Filament\Resources\BillingEvents\Pages\ListBillingEvents;
use Filagora\Memberships\Filament\Resources\BillingEvents\Pages\ViewBillingEvent;
use Filagora\Memberships\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\CreateMembershipPlan;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\EditMembershipPlan;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\ListMembershipPlans;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Support\Abilities;
use Filagora\Memberships\Tests\Support\FakeProvider;
use Filagora\Memberships\Tests\Support\SyncingProvider;
use Filagora\Memberships\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->syncing = new SyncingProvider;
    app(ProviderRegistry::class)->register($this->syncing);
});

function screenStaff(string ...$keys): TestUser
{
    $user = TestUser::make('Staff');

    foreach ($keys as $key) {
        UserEntitlementGrant::create(['user_id' => $user->id, 'entitlement_key' => $key, 'grant_type' => 'manual']);
    }

    actingAs($user);

    return $user;
}

function screenEvent(string $provider, string $status, string $id, array $payload = []): BillingEventLog
{
    return BillingEventLog::create([
        'provider' => $provider,
        'event_id' => $id,
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'processing_status' => $status,
        'payload' => $payload,
    ]);
}

it('registers both resources on a panel with the plugin', function () {
    expect(Filament::getCurrentPanel()->getResources())->toContain(MembershipPlanResource::class, BillingEventResource::class);
});

it('keeps the plan screens for people who may manage memberships', function () {
    actingAs(TestUser::make('Nobody'));
    expect(MembershipPlanResource::canViewAny())->toBeFalse()
        ->and(MembershipPlanResource::canCreate())->toBeFalse();

    screenStaff(Abilities::VIEW_BILLING);
    expect(MembershipPlanResource::canViewAny())->toBeFalse();

    screenStaff(Abilities::MANAGE);
    expect(MembershipPlanResource::canViewAny())->toBeTrue()
        ->and(MembershipPlanResource::canCreate())->toBeTrue()
        ->and(BillingEventResource::canViewAny())->toBeFalse();
});

it('lists plans', function () {
    screenStaff(Abilities::MANAGE);
    $plans = collect(['a', 'b'])->map(fn ($code) => MembershipPlan::create(['code' => $code, 'name' => strtoupper($code), 'is_free' => true]));

    Livewire::test(ListMembershipPlans::class)->assertCanSeeTableRecords($plans);
});

it('creates a free plan without prices', function () {
    screenStaff(Abilities::MANAGE);

    Livewire::test(CreateMembershipPlan::class)
        ->fillForm(['code' => 'free', 'name' => 'Free', 'is_free' => true, 'is_active' => true, 'sort_order' => 0, 'entitlements' => ['forums.a.view']])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = MembershipPlan::firstWhere('code', 'free');

    expect($plan->is_free)->toBeTrue()
        ->and($plan->entitlements)->toBe(['forums.a.view'])
        ->and($plan->prices()->count())->toBe(0);
});

it('creates a paid plan with a price in each currency', function () {
    screenStaff(Abilities::MANAGE);

    Livewire::test(CreateMembershipPlan::class)
        ->fillForm([
            'code' => 'gold',
            'name' => 'Gold',
            'is_free' => false,
            'billing_interval' => 'monthly',
            'provider' => 'syncing',
            'sort_order' => 1,
            'prices' => [
                ['currency' => 'USD', 'amount' => 5, 'is_default' => true],
                ['currency' => 'EUR', 'amount' => 4.5, 'is_default' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = MembershipPlan::firstWhere('code', 'gold');

    expect($plan->provider)->toBe('syncing')
        ->and($plan->billing_interval)->toBe('monthly')
        ->and($plan->prices->pluck('amount', 'currency')->all())->toEqual(['USD' => '5.00', 'EUR' => '4.50'])
        ->and($plan->defaultPrice()->currency)->toBe('USD');
});

it('requires a code and a name, and a code nobody else has', function () {
    screenStaff(Abilities::MANAGE);
    MembershipPlan::create(['code' => 'taken', 'name' => 'Taken', 'is_free' => true]);

    Livewire::test(CreateMembershipPlan::class)
        ->fillForm(['code' => '', 'name' => ''])
        ->call('create')
        ->assertHasFormErrors(['code' => 'required', 'name' => 'required']);

    Livewire::test(CreateMembershipPlan::class)
        ->fillForm(['code' => 'taken', 'name' => 'Again', 'is_free' => true])
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);
});

it('edits a plan', function () {
    screenStaff(Abilities::MANAGE);
    $plan = MembershipPlan::create(['code' => 'p', 'name' => 'Old', 'is_free' => true]);

    Livewire::test(EditMembershipPlan::class, ['record' => $plan->getRouteKey()])
        ->fillForm(['name' => 'New', 'feature_list' => ['Fast', 'Friendly']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->refresh()->name)->toBe('New')
        ->and($plan->featureList())->toBe(['Fast', 'Friendly']);
});

it('offers to sync a plan whose provider creates its own plans, and shows what it created', function () {
    screenStaff(Abilities::MANAGE);
    $plan = MembershipPlan::create(['code' => 'p', 'name' => 'P', 'provider' => 'syncing', 'billing_interval' => 'monthly']);
    $plan->prices()->create(['currency' => 'EUR', 'amount' => '4.50']);

    Livewire::test(EditMembershipPlan::class, ['record' => $plan->getRouteKey()])
        ->assertActionVisible('syncPlans')
        ->callAction('syncPlans')
        ->assertNotified(__('filagora-memberships::messages.plans.sync_done', ['provider' => 'Syncing']));

    expect($plan->refresh()->provider_product_id)->toBe('PROD-SYNC')
        ->and($plan->priceFor('EUR')->provider_plan_id)->toBe('P-EUR-SYNC');
});

it('says when there was nothing to sync, and when the provider failed', function () {
    screenStaff(Abilities::MANAGE);
    $plan = MembershipPlan::create(['code' => 'p', 'name' => 'P', 'provider' => 'syncing', 'billing_interval' => 'monthly']);
    $this->syncing->created = [];

    Livewire::test(EditMembershipPlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('syncPlans')
        ->assertNotified(__('filagora-memberships::messages.plans.sync_nothing'));

    $this->syncing->syncFails = true;
    Exceptions::fake();

    Livewire::test(EditMembershipPlan::class, ['record' => $plan->getRouteKey()])
        ->callAction('syncPlans')
        ->assertNotified(__('filagora-memberships::messages.plans.sync_failed', ['provider' => 'Syncing']));
});

it('does not offer to sync a plan whose provider takes typed-in ids, or a free plan', function () {
    screenStaff(Abilities::MANAGE);
    app(ProviderRegistry::class)->register(new FakeProvider('manual'));
    $manual = MembershipPlan::create(['code' => 'm', 'name' => 'M', 'provider' => 'manual']);
    $free = MembershipPlan::create(['code' => 'f', 'name' => 'F', 'is_free' => true]);

    Livewire::test(EditMembershipPlan::class, ['record' => $manual->getRouteKey()])->assertActionHidden('syncPlans');
    Livewire::test(EditMembershipPlan::class, ['record' => $free->getRouteKey()])->assertActionHidden('syncPlans');
});

it('shows billing events only to people who may read the billing log, and never lets anyone change them', function () {
    actingAs(TestUser::make('Nobody'));
    expect(BillingEventResource::canViewAny())->toBeFalse();

    $event = screenEvent('paypal', 'processed', 'WH-1');
    screenStaff(Abilities::MANAGE);
    expect(BillingEventResource::canViewAny())->toBeFalse();

    screenStaff(Abilities::VIEW_BILLING);

    expect(BillingEventResource::canViewAny())->toBeTrue()
        ->and(BillingEventResource::canView($event))->toBeTrue()
        ->and(BillingEventResource::canCreate())->toBeFalse()
        ->and(BillingEventResource::canEdit($event))->toBeFalse()
        ->and(BillingEventResource::canDelete($event))->toBeFalse()
        ->and(BillingEventResource::canDeleteAny())->toBeFalse();
});

it('lists failed events first and all events on the other tab', function () {
    screenStaff(Abilities::VIEW_BILLING);
    $failed = screenEvent('paypal', 'failed', 'WH-F');
    $done = screenEvent('paypal', 'processed', 'WH-P');

    Livewire::test(ListBillingEvents::class)
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$done])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$failed, $done]);
});

it('filters the events by provider and status', function () {
    screenStaff(Abilities::VIEW_BILLING);
    $a = screenEvent('paypal', 'processed', 'WH-A');
    $b = screenEvent('other', 'processed', 'WH-B');
    $c = screenEvent('paypal', 'failed', 'WH-C');

    Livewire::test(ListBillingEvents::class)
        ->set('activeTab', 'all')
        ->filterTable('provider', 'other')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('processing_status', 'failed')
        ->assertCanSeeTableRecords([$c])
        ->assertCanNotSeeTableRecords([$a, $b]);
});

it('opens the events about one subscription from a link', function () {
    screenStaff(Abilities::VIEW_BILLING);
    $mine = screenEvent('paypal', 'processed', 'WH-MINE', ['resource' => ['id' => 'I-100']]);
    $byId = screenEvent('paypal', 'processed', 'I-100');
    $other = screenEvent('paypal', 'processed', 'WH-OTHER', ['resource' => ['id' => 'I-200']]);
    $otherProvider = screenEvent('stripe', 'processed', 'WH-STRIPE', ['resource' => ['id' => 'I-100']]);

    request()->query->set('provider', 'paypal');
    request()->query->set('subscription_id', 'I-100');

    expect(BillingEventResource::getEloquentQuery()->pluck('id')->sort()->values()->all())->toBe([$mine->id, $byId->id]);

    request()->query->set('subscription_id', 'I_1%');

    expect(BillingEventResource::getEloquentQuery()->count())->toBe(0);
});

it('shows the whole event, including the payload', function () {
    screenStaff(Abilities::VIEW_BILLING);
    $event = screenEvent('paypal', 'failed', 'WH-1', ['resource' => ['id' => 'I-1', 'custom_id' => 'membership:7']]);
    $event->update(['error_message' => 'It broke.']);

    Livewire::test(ViewBillingEvent::class, ['record' => $event->getKey()])
        ->assertSee('WH-1')
        ->assertSee('I-1')
        ->assertSee('membership:7')
        ->assertSee('It broke.');
});

it('badges the navigation with the number of failed events', function () {
    screenStaff(Abilities::VIEW_BILLING);

    expect(BillingEventResource::getNavigationBadge())->toBeNull();

    screenEvent('paypal', 'failed', 'WH-1');
    screenEvent('stripe', 'failed', 'WH-2');
    screenEvent('paypal', 'processed', 'WH-3');

    expect(BillingEventResource::getNavigationBadge())->toBe('2')
        ->and(BillingEventResource::getNavigationBadgeColor())->toBe('danger')
        ->and(BillingEventResource::getNavigationBadgeTooltip())->toContain('2');
});
