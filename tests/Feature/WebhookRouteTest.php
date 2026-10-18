<?php

declare(strict_types=1);

use Filagora\Memberships\Http\Controllers\PayPalWebhookController;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Providers\PayPal\PayPalWebhookHandler;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    PayPal::configure();
    $this->plan = PayPal::paidPlan();
});

function deliver(array $payload, array $headers = [], ?string $raw = null)
{
    $server = [];

    foreach (($headers ?: PayPal::headers()) as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/paypal/webhook', server: $server + ['CONTENT_TYPE' => 'application/json'], content: $raw ?? json_encode($payload, JSON_UNESCAPED_SLASHES));
}

it('is a named route outside the web group, rate limited', function () {
    $route = Route::getRoutes()->getByName('filagora-memberships.webhooks.paypal');

    expect($route->uri())->toBe('paypal/webhook')
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toBe(['throttle:120,1'])
        ->and($route->getControllerClass())->toBe(PayPalWebhookController::class);
});

it('takes a verified delivery, applies it and answers 200', function () {
    Http::fake([
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
        'paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);

    deliver(PayPal::event('WH-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1')))->assertOk();

    expect($membership->refresh()->status)->toBe('active')
        ->and(BillingEventLog::firstWhere('event_id', 'WH-1')->processing_status)->toBe('processed');
});

it('passes PayPal\'s headers and the exact body on to be verified', function () {
    Http::fake([
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
        'paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);

    deliver(PayPal::event('WH-1', 'PAYMENT.SALE.COMPLETED', ['id' => 'x']))->assertOk();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature')
        && $request['transmission_id'] === 'T-1' && $request['transmission_sig'] === 'SIG' && $request['webhook_event']['id'] === 'WH-1');
});

it('answers 400 to a delivery PayPal does not vouch for, and changes no membership', function () {
    Http::fake([
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
        'paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
    ]);
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);

    deliver(PayPal::event('WH-FORGED', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1')))
        ->assertStatus(400)
        ->assertSee(__('filagora-memberships::memberships.webhook_invalid'));

    expect($membership->refresh()->status)->toBe('pending');
});

it('answers 400 to a body that is not a JSON object', function () {
    Http::fake();

    deliver([], raw: 'not json')->assertStatus(400);
    deliver([], raw: '"a string"')->assertStatus(400);
    deliver([], raw: '')->assertStatus(400);

    Http::assertNothingSent();
});

it('answers 400 and reports when the handler itself blows up', function () {
    Exceptions::fake();
    $this->mock(PayPalWebhookHandler::class)->shouldReceive('handle')->andThrow(new RuntimeException('boom'));

    deliver(['id' => 'x', 'event_type' => 'y'])->assertStatus(400);

    Exceptions::assertReported(RuntimeException::class);
});

it('needs no session or CSRF token', function () {
    Http::fake([
        'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600]),
        'paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
    ]);
    $this->withMiddleware();

    deliver(PayPal::event('WH-1', 'PAYMENT.SALE.COMPLETED', ['id' => 'x']))->assertOk();
});
