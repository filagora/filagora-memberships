<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Filagora\Memberships\Tests\Support\TestUser;

beforeEach(function () {
    $this->travelTo(now()->setTime(12, 0));
    $this->plan = PayPal::paidPlan(['code' => 'gold', 'name' => 'Gold']);
    $this->contributor = app(PrivacyContributorRegistry::class)->all()['memberships'];
    $this->rule = collect(app(RetentionRuleRegistry::class)->all())->first(fn ($rule) => $rule->key() === 'memberships.billing_event_payloads');
});

function personalEvent(string $id, string $subscriptionId, string $created = 'now', array $extra = []): BillingEventLog
{
    $event = BillingEventLog::create([
        'provider' => 'paypal',
        'event_id' => $id,
        'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
        'processing_status' => 'processed',
        'payload' => ['id' => $id, 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => array_replace([
            'id' => $subscriptionId,
            'status' => 'ACTIVE',
            'subscriber' => ['name' => ['given_name' => 'Alice'], 'email_address' => 'alice@example.test'],
            'payer' => ['email_address' => 'alice@example.test'],
        ], $extra)],
    ]);
    BillingEventLog::whereKey($event->id)->toBase()->update(['created_at' => now()->modify($created)]);

    return $event->refresh();
}

it('exports a person\'s memberships and the events received about them, without the payloads', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    PayPal::membership($this->plan, ['user_id' => $alice->id, 'status' => 'active', 'provider_subscription_id' => 'I-A', 'starts_at' => now()->subMonth(), 'granted_entitlements_snapshot' => ['forums.a.view']]);
    PayPal::membership($this->plan, ['user_id' => $bob->id, 'provider_subscription_id' => 'I-B']);
    personalEvent('WH-A', 'I-A');
    personalEvent('WH-B', 'I-B');

    $export = $this->contributor->export($alice);

    expect($export['memberships'])->toHaveCount(1)
        ->and($export['memberships'][0])->toMatchArray([
            'plan' => 'Gold',
            'plan_code' => 'gold',
            'status' => 'active',
            'provider' => 'paypal',
            'provider_subscription_id' => 'I-A',
            'starts_at' => now()->subMonth()->toIso8601String(),
            'granted_entitlements' => ['forums.a.view'],
        ])
        ->and($export['billing_events'])->toBe([['provider' => 'paypal', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'received_at' => now()->toIso8601String()]])
        ->and(json_encode($export))->not->toContain('alice@example.test')->not->toContain('I-B');
});

it('exports nothing for a person with no memberships', function () {
    expect($this->contributor->export(TestUser::make('Nobody')))->toBe(['memberships' => [], 'billing_events' => []]);
});

it('on erasure keeps the memberships but takes the person\'s details out of their events', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $membership = PayPal::membership($this->plan, ['user_id' => $alice->id, 'provider_subscription_id' => 'I-A', 'meta' => ['payer_id' => 'PAYER-A', 'currency' => 'USD']]);
    PayPal::membership($this->plan, ['user_id' => $bob->id, 'provider_subscription_id' => 'I-B', 'meta' => ['payer_id' => 'PAYER-B']]);
    $mine = personalEvent('WH-A', 'I-A');
    $theirs = personalEvent('WH-B', 'I-B');

    $this->contributor->erase($alice);

    expect(UserMembership::count())->toBe(2)
        ->and($membership->refresh()->meta)->toBe(['currency' => 'USD'])
        ->and(UserMembership::firstWhere('provider_subscription_id', 'I-B')->meta)->toBe(['payer_id' => 'PAYER-B'])
        ->and($mine->refresh()->payload['resource'])->toBe(['id' => 'I-A', 'status' => 'ACTIVE'])
        ->and($mine->event_type)->toBe('BILLING.SUBSCRIPTION.ACTIVATED')
        ->and($theirs->refresh()->payload['resource'])->toHaveKeys(['subscriber', 'payer']);
});

it('erases cleanly for a person with nothing to erase', function () {
    $this->contributor->erase(TestUser::make('Nobody'));

    expect(BillingEventLog::count())->toBe(0);
});

it('does not take an event for a similar subscription id', function () {
    $alice = TestUser::make('Alice');
    PayPal::membership($this->plan, ['user_id' => $alice->id, 'provider_subscription_id' => 'I-1']);
    $other = personalEvent('WH-X', 'I-10');
    $underscore = personalEvent('WH-Y', 'IX1');
    $namesIt = personalEvent('I-1', 'I-77');

    $this->contributor->erase($alice);

    expect($other->refresh()->payload['resource'])->toHaveKey('subscriber')
        ->and($underscore->refresh()->payload['resource'])->toHaveKey('subscriber')
        ->and($namesIt->refresh()->payload['resource'])->toHaveKey('subscriber');
});

it('keeps payloads for ever unless a window is set', function () {
    $old = personalEvent('WH-OLD', 'I-1', '-3 years');

    expect($this->rule->prune(false))->toBe(0);

    config(['filagora-memberships.retention.event_payload_days' => 0]);
    expect($this->rule->prune(false))->toBe(0);

    expect($old->refresh()->payload['resource'])->toHaveKey('subscriber');
});

it('takes the details out of events older than the window, and counts without changing on a dry run', function () {
    config(['filagora-memberships.retention.event_payload_days' => 90]);
    $old = personalEvent('WH-OLD', 'I-1', '-120 days');
    $recent = personalEvent('WH-NEW', 'I-2', '-30 days');
    $edge = personalEvent('WH-EDGE', 'I-3', '-89 days');

    expect($this->rule->prune(true))->toBe(1)
        ->and($old->refresh()->payload['resource'])->toHaveKey('subscriber');

    expect($this->rule->prune(false))->toBe(1)
        ->and($old->refresh()->payload['resource'])->toBe(['id' => 'I-1', 'status' => 'ACTIVE'])
        ->and($recent->refresh()->payload['resource'])->toHaveKey('subscriber')
        ->and($edge->refresh()->payload['resource'])->toHaveKey('subscriber')
        ->and(BillingEventLog::count())->toBe(3);

    expect($this->rule->prune(false))->toBe(0);
});

it('counts an old event once it holds either the subscriber or the payer', function () {
    config(['filagora-memberships.retention.event_payload_days' => 30]);
    $subscriberOnly = personalEvent('WH-1', 'I-1', '-60 days');
    $payload = $subscriberOnly->payload;
    unset($payload['resource']['payer']);
    $subscriberOnly->update(['payload' => $payload]);
    $none = BillingEventLog::create(['provider' => 'paypal', 'event_id' => 'WH-2', 'event_type' => 'x', 'payload' => ['resource' => ['id' => 'I-9']]]);
    BillingEventLog::whereKey($none->id)->toBase()->update(['created_at' => now()->subDays(60)]);

    expect($this->rule->prune(false))->toBe(1);
});
