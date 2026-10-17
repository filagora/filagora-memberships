<?php

declare(strict_types=1);

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\SubscriptionRefusal;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Providers\PayPal\PayPalProvider;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    PayPal::configure();
    $this->provider = app(PayPalProvider::class);
});

function paypalLogs(): array
{
    return Log::channel('array')->getLogger()->getHandlers()[0]->getRecords();
}

it('is registered under the key paypal and configured when its credentials are set', function () {
    expect($this->provider->key())->toBe('paypal')
        ->and($this->provider->label())->toBe('PayPal')
        ->and($this->provider->isConfigured())->toBeTrue()
        ->and(app(ProviderRegistry::class)->get('PAYPAL'))->toBe($this->provider);

    config(['filagora-memberships.paypal.webhook_id' => '']);

    expect($this->provider->isConfigured())->toBeFalse();
});

it('starts a subscription and hands back the approval page and what PayPal said', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions' => Http::response([
        'id' => 'I-1', 'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
    ])]);
    $plan = PayPal::paidPlan();
    $membership = PayPal::membership($plan);

    $subscription = $this->provider->subscribe($membership, $plan->priceFor('USD'), 'https://site.test/ok', 'https://site.test/no');

    expect($subscription->id)->toBe('I-1')
        ->and($subscription->redirectUrl)->toBe('https://paypal.test/approve')
        ->and($subscription->meta['paypal_status'])->toBe('APPROVAL_PENDING')
        ->and($subscription->meta['paypal_payload']['id'])->toBe('I-1');
});

it('logs the failure with what PayPal answered, and raises ProviderFailed', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions' => Http::response(['name' => 'INVALID_REQUEST'], 400)]);
    $plan = PayPal::paidPlan();
    $membership = PayPal::membership($plan);

    expect(fn () => $this->provider->subscribe($membership, $plan->priceFor('USD'), 'a', 'b'))->toThrow(ProviderFailed::class);

    $record = paypalLogs()[0];

    expect($record['message'])->toBe('PayPal subscription creation failed.')
        ->and($record['context'])->toMatchArray(['paypal_plan_id' => 'P-USD', 'currency' => 'USD', 'membership_id' => $membership->id, 'paypal_status' => 400]);
});

it('cancels the subscription at PayPal, and raises ProviderFailed when PayPal refuses', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions/I-1/cancel' => Http::sequence()->push(null, 204)->push([], 422)]);
    $membership = PayPal::membership(PayPal::paidPlan(), ['provider_subscription_id' => 'I-1']);

    $this->provider->cancel($membership);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/I-1/cancel'));

    expect(fn () => $this->provider->cancel($membership))->toThrow(ProviderFailed::class);
});

it('creates the product once and a billing plan for each price that has none', function () {
    PayPal::fakeHttp([
        'paypal.test/v1/catalogs/products' => Http::response(['id' => 'PROD-1']),
        'paypal.test/v1/billing/plans' => Http::sequence()->push(['id' => 'P-NEW-1'])->push(['id' => 'P-NEW-2']),
    ]);
    $plan = PayPal::plan(['name' => 'Gold']);
    $plan->prices()->create(['currency' => 'USD', 'amount' => '5.00', 'provider_plan_id' => 'P-HAVE']);
    $plan->prices()->create(['currency' => 'EUR', 'amount' => '4.50']);
    $plan->prices()->create(['currency' => 'GBP', 'amount' => '4.00']);

    expect($this->provider->syncPlan($plan))->toBe(['EUR', 'GBP'])
        ->and($plan->refresh()->provider_product_id)->toBe('PROD-1')
        ->and($plan->priceFor('USD')->provider_plan_id)->toBe('P-HAVE')
        ->and($plan->priceFor('EUR')->provider_plan_id)->toBe('P-NEW-1')
        ->and($plan->priceFor('GBP')->provider_plan_id)->toBe('P-NEW-2');

    Http::assertSentCount(4);

    expect($this->provider->syncPlan($plan))->toBe([]);
    Http::assertSentCount(4);
});

it('keeps the product it already has, and ignores a plan that is not a PayPal plan', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/plans' => Http::response(['id' => 'P-NEW'])]);
    $plan = PayPal::plan(['provider_product_id' => 'PROD-OLD']);
    $plan->prices()->create(['currency' => 'USD', 'amount' => '5.00']);

    expect($this->provider->syncPlan($plan))->toBe(['USD'])
        ->and($plan->refresh()->provider_product_id)->toBe('PROD-OLD');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/v1/catalogs/products'));

    expect($this->provider->syncPlan(PayPal::plan(['provider' => 'stripe'])))->toBe([]);
});

it('stops a sync that fails part way, keeping what was created', function () {
    PayPal::fakeHttp([
        'paypal.test/v1/catalogs/products' => Http::response(['id' => 'PROD-1']),
        'paypal.test/v1/billing/plans' => Http::sequence()->push(['id' => 'P-NEW-1'])->push([], 500),
    ]);
    $plan = PayPal::plan();
    $plan->prices()->create(['currency' => 'USD', 'amount' => '5.00']);
    $plan->prices()->create(['currency' => 'EUR', 'amount' => '4.50']);

    expect(fn () => $this->provider->syncPlan($plan))->toThrow(ProviderFailed::class);

    $plan->refresh();

    expect($plan->provider_product_id)->toBe('PROD-1')
        ->and($plan->priceFor('USD')->provider_plan_id)->toBe('P-NEW-1')
        ->and($plan->priceFor('EUR')->provider_plan_id)->toBeNull();
});

it('takes a person from the billing page to PayPal and back to a pending membership', function () {
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions' => Http::response([
        'id' => 'I-77', 'status' => 'APPROVAL_PENDING', 'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve/I-77']],
    ])]);
    $plan = PayPal::paidPlan(['code' => 'chwm']);
    $user = TestUser::make('Member');

    $result = app(Memberships::class)->subscribe($user, $plan, 'EUR', 'https://site.test/ok', 'https://site.test/no');
    $membership = $result->membership->refresh();

    expect($result->redirectUrl)->toBe('https://paypal.test/approve/I-77')
        ->and($membership->provider)->toBe('paypal')
        ->and($membership->provider_subscription_id)->toBe('I-77')
        ->and($membership->status)->toBe('pending')
        ->and($membership->meta)->toMatchArray(['provider_price_id' => 'P-EUR', 'currency' => 'EUR', 'subscribed_via' => 'paypal_checkout', 'paypal_status' => 'APPROVAL_PENDING']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/billing/subscriptions') && $request['plan_id'] === 'P-EUR' && $request['custom_id'] === 'membership:'.$membership->id);
});

it('refuses with a reason the host can show when PayPal is down or not set up', function () {
    Exceptions::fake();
    PayPal::fakeHttp(['paypal.test/v1/billing/subscriptions' => Http::response([], 503)]);
    $plan = PayPal::paidPlan();
    $user = TestUser::make('Member');

    $reason = null;
    try {
        app(Memberships::class)->subscribe($user, $plan);
    } catch (SubscriptionRefused $refused) {
        $reason = $refused->reason;
    }

    expect($reason)->toBe(SubscriptionRefusal::PROVIDER_FAILED)
        ->and(UserMembership::first()->status)->toBe('pending');

    config(['filagora-memberships.paypal.client_secret' => '']);

    expect(fn () => app(Memberships::class)->subscribe($user, $plan))->toThrow(SubscriptionRefused::class);
});
