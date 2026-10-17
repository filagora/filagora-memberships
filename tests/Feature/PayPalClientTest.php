<?php

declare(strict_types=1);

use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Providers\PayPal\PayPalClient;
use Filagora\Memberships\Tests\Support\TestUser;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'filagora-memberships.paypal.client_id' => 'client',
        'filagora-memberships.paypal.client_secret' => 'secret',
        'filagora-memberships.paypal.webhook_id' => 'WH-1',
        'filagora-memberships.paypal.base_url' => 'https://paypal.test/',
    ]);
    $this->client = app(PayPalClient::class);
});

function fakePayPal(array $routes = []): void
{
    Http::fake($routes + [
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
        '*' => Http::response([], 404),
    ]);
}

function clientPlan(array $attributes = []): MembershipPlan
{
    return MembershipPlan::create(array_merge(['code' => 'p'.uniqid(), 'name' => 'Plan', 'provider' => 'paypal', 'billing_interval' => 'monthly', 'description' => 'Help'], $attributes));
}

it('is configured only with a client id, a secret and a webhook id', function () {
    expect($this->client->isConfigured())->toBeTrue();

    foreach (['client_id', 'client_secret', 'webhook_id'] as $key) {
        config(["filagora-memberships.paypal.{$key}" => ' ']);
        expect($this->client->isConfigured())->toBeFalse($key);
        config(["filagora-memberships.paypal.{$key}" => 'x']);
    }
});

it('asks for one access token and reuses it until it expires', function () {
    fakePayPal(['paypal.test/v1/catalogs/products' => Http::response(['id' => 'PROD-1'])]);

    $this->client->createProduct(clientPlan());
    $this->client->createProduct(clientPlan());

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token') && $request->hasHeader('Authorization', 'Basic '.base64_encode('client:secret')) && $request['grant_type'] === 'client_credentials');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/catalogs/products') && $request->hasHeader('Authorization', 'Bearer TOKEN'));
});

it('does not keep a token that is about to expire', function () {
    Http::fake([
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'SHORT', 'expires_in' => 60]),
        'paypal.test/v1/catalogs/products' => Http::response(['id' => 'PROD-1']),
    ]);

    $this->client->createProduct(clientPlan());
    $this->client->createProduct(clientPlan());

    Http::assertSentCount(4);
});

it('refuses to call PayPal without credentials', function () {
    config(['filagora-memberships.paypal.client_secret' => '']);
    fakePayPal();

    expect(fn () => $this->client->createProduct(clientPlan()))->toThrow(RuntimeException::class, 'credentials');
    Http::assertNothingSent();
});

it('creates a catalogue product from the plan', function () {
    fakePayPal(['paypal.test/v1/catalogs/products' => Http::response(['id' => ' PROD-9 '])]);

    expect($this->client->createProduct(clientPlan(['name' => 'Gold', 'description' => str_repeat('d', 300)])))->toBe('PROD-9');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/catalogs/products')
        && $request['name'] === 'Gold' && $request['type'] === 'SERVICE' && $request['category'] === 'SOFTWARE' && strlen($request['description']) === 250);
});

it('falls back to the name for a plan without a description', function () {
    fakePayPal(['paypal.test/v1/catalogs/products' => Http::response(['id' => 'PROD-1'])]);

    $this->client->createProduct(clientPlan(['name' => 'Gold', 'description' => null]));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/catalogs/products') && $request['description'] === 'Gold');
});

it('fails when PayPal returns no id', function () {
    fakePayPal(['paypal.test/v1/catalogs/products' => Http::response(['id' => ' '])]);

    expect(fn () => $this->client->createProduct(clientPlan()))->toThrow(RuntimeException::class, 'product id');
});

it('maps each billing interval to a PayPal frequency and prices the plan in the price\'s currency', function () {
    fakePayPal(['paypal.test/v1/billing/plans' => Http::response(['id' => 'P-1'])]);

    foreach (['weekly' => ['WEEK', 1], 'monthly' => ['MONTH', 1], 'quarterly' => ['MONTH', 3], 'yearly' => ['YEAR', 1]] as $interval => [$unit, $count]) {
        $plan = clientPlan(['billing_interval' => $interval, 'name' => 'Gold']);
        $price = $plan->prices()->create(['currency' => 'eur', 'amount' => '4.5']);

        expect($this->client->createPlan($plan, $price, 'PROD-1'))->toBe('P-1');

        Http::assertSent(function (Request $request) use ($unit, $count) {
            $cycle = $request['billing_cycles'][0] ?? null;

            return str_ends_with($request->url(), '/v1/billing/plans') && $cycle !== null
                && $cycle['frequency'] === ['interval_unit' => $unit, 'interval_count' => $count]
                && $cycle['pricing_scheme']['fixed_price'] === ['value' => '4.50', 'currency_code' => 'EUR']
                && $request['product_id'] === 'PROD-1' && $request['name'] === 'Gold (EUR)'
                && $cycle['total_cycles'] === 0 && $request['payment_preferences']['payment_failure_threshold'] === 3;
        });
    }
});

it('refuses to create a plan for a billing interval PayPal cannot express', function () {
    fakePayPal();
    $plan = clientPlan(['billing_interval' => null]);
    $price = $plan->prices()->create(['currency' => 'USD', 'amount' => '5']);

    expect(fn () => $this->client->createPlan($plan, $price, 'PROD-1'))->toThrow(RuntimeException::class, 'Unsupported billing interval');
});

it('creates a subscription that carries the membership id and the return pages, and finds the approval link', function () {
    fakePayPal(['paypal.test/v1/billing/subscriptions' => Http::response([
        'id' => 'I-1',
        'status' => 'APPROVAL_PENDING',
        'links' => [['rel' => 'self', 'href' => 'https://paypal.test/self'], ['rel' => 'approve', 'href' => ' https://paypal.test/approve/I-1 ']],
    ])]);
    $plan = clientPlan();
    $price = $plan->prices()->create(['currency' => 'USD', 'amount' => '5', 'provider_plan_id' => 'P-USD']);
    $membership = UserMembership::create(['user_id' => TestUser::make()->id, 'membership_plan_id' => $plan->id]);

    $subscription = $this->client->createSubscription($price, $membership, 'https://site.test/ok', 'https://site.test/no');

    expect($subscription['id'])->toBe('I-1')
        ->and($subscription['status'])->toBe('APPROVAL_PENDING')
        ->and($subscription['approve_url'])->toBe('https://paypal.test/approve/I-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/billing/subscriptions')
        && $request['plan_id'] === 'P-USD' && $request['custom_id'] === 'membership:'.$membership->id
        && $request['application_context']['return_url'] === 'https://site.test/ok' && $request['application_context']['cancel_url'] === 'https://site.test/no'
        && $request['application_context']['shipping_preference'] === 'NO_SHIPPING' && $request['application_context']['user_action'] === 'SUBSCRIBE_NOW');
});

it('fails when PayPal gives no approval link', function () {
    fakePayPal(['paypal.test/v1/billing/subscriptions' => Http::response(['id' => 'I-1', 'links' => [['rel' => 'self', 'href' => 'x']]])]);
    $plan = clientPlan();
    $price = $plan->prices()->create(['currency' => 'USD', 'amount' => '5', 'provider_plan_id' => 'P-USD']);
    $membership = UserMembership::create(['user_id' => TestUser::make()->id, 'membership_plan_id' => $plan->id]);

    expect(fn () => $this->client->createSubscription($price, $membership, 'a', 'b'))->toThrow(RuntimeException::class, 'approval URL');
});

it('reads a subscription, escaping its id, and fails on an answer without one', function () {
    fakePayPal([
        'paypal.test/v1/billing/subscriptions/I-1' => Http::response(['id' => 'I-1', 'status' => 'ACTIVE']),
        'paypal.test/v1/billing/subscriptions/I-2' => Http::response(['status' => 'ACTIVE']),
        'paypal.test/v1/billing/subscriptions/a%2Fb%20c' => Http::response(['id' => 'a/b c']),
    ]);

    expect($this->client->getSubscription('I-1')['status'])->toBe('ACTIVE')
        ->and(fn () => $this->client->getSubscription('I-2'))->toThrow(RuntimeException::class, 'subscription details')
        ->and($this->client->getSubscription('a/b c')['id'])->toBe('a/b c');
});

it('cancels a subscription with a reason, and raises on an error answer', function () {
    fakePayPal(['paypal.test/v1/billing/subscriptions/I-1/cancel' => Http::response(null, 204)]);

    $this->client->cancelSubscription('I-1', 'Because');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/I-1/cancel') && $request['reason'] === 'Because');

    fakePayPal(['paypal.test/v1/billing/subscriptions/I-2/cancel' => Http::response(['name' => 'SUBSCRIPTION_STATUS_INVALID'], 422)]);

    expect(fn () => $this->client->cancelSubscription('I-2', 'x'))->toThrow(RequestException::class);
});

it('verifies a webhook delivery with the headers PayPal sent and the configured webhook id', function () {
    fakePayPal(['paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'success'])]);

    $result = $this->client->verifyWebhookSignature([
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-cert-url' => 'https://api.paypal.test/cert',
        'paypal-transmission-id' => 'T-1',
        'paypal-transmission-sig' => 'SIG',
        'paypal-transmission-time' => '2026-10-17T12:00:00Z',
    ], ['id' => 'WH-EVT']);

    expect($result['verified'])->toBeTrue()->and($result['verification_status'])->toBe('SUCCESS');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/verify-webhook-signature')
        && $request['webhook_id'] === 'WH-1' && $request['transmission_id'] === 'T-1' && $request['transmission_sig'] === 'SIG'
        && $request['auth_algo'] === 'SHA256withRSA' && $request['webhook_event'] === ['id' => 'WH-EVT']);
});

it('does not verify a delivery PayPal says is not genuine, or without a webhook id', function () {
    fakePayPal(['paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'])]);

    expect($this->client->verifyWebhookSignature([], [])['verified'])->toBeFalse();

    config(['filagora-memberships.paypal.webhook_id' => '']);
    Http::fake();

    expect($this->client->verifyWebhookSignature([], []))->toBe(['verified' => false, 'verification_status' => '', 'response' => []]);
    Http::assertNothingSent();
});
