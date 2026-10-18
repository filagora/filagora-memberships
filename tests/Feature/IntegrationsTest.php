<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\EntitlementDefinition;
use Filagora\Entitlements\Services\EntitlementDefinitionCatalog;
use Filagora\Entitlements\Services\EntitlementResolver;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Forums\Targeting\Viewer;
use Filagora\Memberships\Entitlements\MembershipEntitlementKeys;
use Filagora\Memberships\Forums\MembershipRule;
use Filagora\Memberships\Forums\MembershipsPaidAccess;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Foundation\Auth\User;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->user = TestUser::make('Member');
    $this->resolver = app(EntitlementResolver::class);
});

function itgPlan(array $entitlements = ['forums.chwm.view', 'forums.chwm.reply'], array $attributes = []): MembershipPlan
{
    return MembershipPlan::create(array_merge(['code' => 'plan_'.uniqid(), 'name' => 'Gold', 'is_active' => true, 'is_free' => true, 'entitlements' => $entitlements], $attributes));
}

function itgMembership(TestUser $user, MembershipPlan $plan, array $attributes = []): UserMembership
{
    return UserMembership::create(array_merge([
        'user_id' => $user->id,
        'membership_plan_id' => $plan->id,
        'provider' => 'local',
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'granted_entitlements_snapshot' => $plan->entitlementList(),
    ], $attributes));
}

it('lets a current membership grant its entitlements', function () {
    itgMembership($this->user, itgPlan());

    expect($this->resolver->hasEntitlement($this->user, 'forums.chwm.view'))->toBeTrue()
        ->and($this->resolver->hasEntitlement($this->user, 'forums.chwm.reply'))->toBeTrue()
        ->and($this->resolver->hasEntitlement($this->user, 'forums.other.view'))->toBeFalse()
        ->and($this->resolver->hasEntitlement(TestUser::make('Other'), 'forums.chwm.view'))->toBeFalse();
});

it('explains where an entitlement came from', function () {
    $membership = itgMembership($this->user, itgPlan(['forums.chwm.view'], ['code' => 'chwm', 'name' => 'CHWM']), ['ends_at' => now()->addDays(5)]);

    $grant = $this->resolver->grantsFor($this->user)->first();

    expect($grant)->toMatchArray([
        'entitlement_key' => 'forums.chwm.view',
        'source_kind' => 'membership',
        'source_label' => 'Membership',
        'source_summary' => 'CHWM (chwm)',
        'source_reference' => 'Membership #'.$membership->id,
        'grant_type' => 'membership',
        'expires_at' => now()->addDays(5)->toAtomString(),
    ])
        ->and($grant['starts_at'])->toBe(now()->subDay()->toAtomString())
        ->and($grant['metadata'])->toBe(['membership_id' => $membership->id, 'membership_plan_id' => $membership->membership_plan_id, 'membership_plan_code' => 'chwm']);
});

it('expires a grant at the period end when the membership has no end date', function () {
    itgMembership($this->user, itgPlan(['forums.chwm.view']), ['current_period_end' => now()->addDays(9)]);

    expect($this->resolver->grantsFor($this->user)->first()['expires_at'])->toBe(now()->addDays(9)->toAtomString());
});

it('grants what the membership was started with, and falls back to the plan when it has no snapshot', function () {
    $plan = itgPlan(['forums.new.view']);
    itgMembership($this->user, $plan, ['granted_entitlements_snapshot' => ['forums.old.view']]);
    $second = TestUser::make('Second');
    itgMembership($second, $plan, ['granted_entitlements_snapshot' => null]);

    expect($this->resolver->entitlementsFor($this->user))->toBe(['forums.old.view'])
        ->and($this->resolver->entitlementsFor($second))->toBe(['forums.new.view']);
});

it('grants nothing for memberships that are not current', function (string $status, array $attributes) {
    itgMembership($this->user, itgPlan(), ['status' => $status] + $attributes);

    expect($this->resolver->entitlementsFor($this->user))->toBe([]);
})->with([
    'pending' => ['pending', []],
    'canceled' => ['canceled', ['ends_at' => '-1 day']],
    'past due' => ['past_due', []],
    'ended' => ['active', ['ends_at' => '-1 hour']],
]);

it('drops a person\'s cached entitlements as soon as their membership changes', function () {
    expect($this->resolver->hasEntitlement($this->user, 'forums.chwm.view'))->toBeFalse();

    $membership = itgMembership($this->user, itgPlan());
    expect($this->resolver->hasEntitlement($this->user, 'forums.chwm.view'))->toBeTrue();

    $membership->update(['status' => 'canceled', 'ends_at' => now()->subMinute()]);
    expect($this->resolver->hasEntitlement($this->user, 'forums.chwm.view'))->toBeFalse();

    $membership->delete();
    itgMembership($this->user, itgPlan(['forums.chwm.view']));
    expect($this->resolver->hasEntitlement($this->user, 'forums.chwm.view'))->toBeTrue();
});

it('does not disturb another person\'s cached entitlements', function () {
    $other = TestUser::make('Other');
    itgMembership($other, itgPlan());
    expect($this->resolver->hasEntitlement($other, 'forums.chwm.view'))->toBeTrue();

    itgMembership($this->user, itgPlan(['forums.x.view']));

    expect($this->resolver->entitlementsFor($other))->toBe(['forums.chwm.view', 'forums.chwm.reply']);
});

it('gives each plan\'s entitlements a definition when the plan is saved', function () {
    itgPlan(['forums.chwm.view', ' forums.chwm.reply ']);

    expect(EntitlementDefinition::pluck('key')->sort()->values()->all())->toContain('forums.chwm.view', 'forums.chwm.reply');
});

it('lists every plan\'s entitlement keys as a source of definitions', function () {
    itgPlan(['forums.b.view', 'forums.a.view']);
    itgPlan(['forums.a.view', 'forums.c.view']);

    expect((new MembershipEntitlementKeys)->keys())->toBe(['forums.a.view', 'forums.b.view', 'forums.c.view']);

    EntitlementDefinition::query()->delete();
    app(EntitlementDefinitionCatalog::class)->synchronizeAll();

    expect(EntitlementDefinition::pluck('key')->all())->toContain('forums.a.view', 'forums.b.view', 'forums.c.view');
});

it('answers the forums paid-access check from current memberships', function () {
    $check = app(MembershipsPaidAccess::class);

    expect($check->hasPaidAccess($this->user))->toBeFalse();

    itgMembership($this->user, itgPlan());

    expect($check->hasPaidAccess($this->user))->toBeTrue()
        ->and($check->hasPaidAccess(TestUser::make('Other')))->toBeFalse()
        ->and($check->hasPaidAccess(new User))->toBeFalse();
});

it('targets announcements at members, optionally on one plan, and at non-members when negated', function () {
    $rules = app(TargetingRules::class);
    $gold = itgPlan();
    $silver = itgPlan();
    itgMembership($this->user, $gold);

    $member = new Viewer($this->user);
    $stranger = new Viewer(TestUser::make('Stranger'));
    $guest = new Viewer;

    $any = [['type' => 'membership']];
    $onGold = [['type' => 'membership', 'plan_id' => (string) $gold->id]];
    $onSilver = [['type' => 'membership', 'plan_id' => $silver->id]];
    $notAny = [['type' => 'membership', 'negate' => true]];

    expect($rules->has('membership'))->toBeTrue()
        ->and($rules->passes($any, $member))->toBeTrue()
        ->and($rules->passes($any, $stranger))->toBeFalse()
        ->and($rules->passes($any, $guest))->toBeFalse()
        ->and($rules->passes($onGold, $member))->toBeTrue()
        ->and($rules->passes($onSilver, $member))->toBeFalse()
        ->and($rules->passes($notAny, $member))->toBeFalse()
        ->and($rules->passes($notAny, $stranger))->toBeTrue()
        ->and($rules->passes($notAny, $guest))->toBeFalse();
});

it('counts only current memberships for the targeting rule', function () {
    $rules = app(TargetingRules::class);
    itgMembership($this->user, itgPlan(), ['status' => 'canceled', 'ends_at' => now()->subDay()]);

    expect($rules->passes([['type' => 'membership']], new Viewer($this->user)))->toBeFalse()
        ->and(app(Memberships::class)->hasActive($this->user))->toBeFalse();
});

it('adds its targeting rule when the registry is first used, not while the application boots', function () {
    app()->forgetInstance(TargetingRules::class);

    expect(app()->resolved(TargetingRules::class))->toBeFalse()
        ->and(app(TargetingRules::class)->has('membership'))->toBeTrue();
});

it('keeps a membership targeting rule that was registered first', function () {
    $custom = new class(app(Memberships::class)) extends MembershipRule
    {
        public function label(): string
        {
            return 'The host\'s own';
        }
    };

    app()->forgetInstance(TargetingRules::class);
    app()->extend(TargetingRules::class, function (TargetingRules $rules) use ($custom): TargetingRules {
        $rules->register($custom);

        return $rules;
    });

    expect(app(TargetingRules::class)->all()['membership']->label())->toBe('The host\'s own');
});
