<?php

declare(strict_types=1);

use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Providers\PayPal\PayPalWebhookHandler;
use Filagora\Memberships\Tests\Support\PayPalFixtures as PayPal;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    PayPal::configure();
    $this->travelTo(now()->setTime(12, 0));
    $this->handler = app(PayPalWebhookHandler::class);
    $this->plan = PayPal::paidPlan();
});

function verifiedBy(string $status = 'SUCCESS'): void
{
    PayPal::fakeHttp(['paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $status])]);
}

function webhookLogs(): array
{
    return Log::channel('array')->getLogger()->getHandlers()[0]->getRecords();
}

it('applies a verified subscription event to the membership and records it once', function () {
    verifiedBy();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1'));

    expect($this->handler->handle(PayPal::headers(), $payload))->toBeTrue();

    $log = BillingEventLog::where('event_id', 'WH-EVT-1')->first();

    expect($membership->refresh()->status)->toBe('active')
        ->and($log->provider)->toBe('paypal')
        ->and($log->event_type)->toBe('BILLING.SUBSCRIPTION.ACTIVATED')
        ->and($log->processing_status)->toBe('processed')
        ->and($log->processed_at)->not->toBeNull()
        ->and($log->error_message)->toBeNull()
        ->and($log->payload['resource']['id'])->toBe('I-1');
});

it('does not apply a delivery it has already processed', function () {
    verifiedBy();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1'));

    $this->handler->handle(PayPal::headers(), $payload);
    $membership->update(['status' => 'canceled']);

    expect($this->handler->handle(PayPal::headers(), $payload))->toBeTrue()
        ->and($membership->refresh()->status)->toBe('canceled')
        ->and(BillingEventLog::count())->toBe(1);
});

it('refuses a delivery PayPal says is not genuine, and changes nothing but the record of it', function () {
    verifiedBy('FAILURE');
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);
    $payload = PayPal::event('WH-FORGED', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1'));

    expect($this->handler->handle(PayPal::headers(), $payload, json_encode($payload)))->toBeFalse()
        ->and($membership->refresh()->status)->toBe('pending');

    $log = BillingEventLog::where('event_id', 'WH-FORGED')->first();

    expect($log->processing_status)->toBe('failed')
        ->and($log->error_message)->toBe('PayPal webhook signature verification failed.')
        ->and(webhookLogs()[0]['message'])->toBe('PayPal webhook signature verification failed.')
        ->and(webhookLogs()[0]['context'])->toMatchArray(['event_id' => 'WH-FORGED', 'paypal_verification_status' => 'FAILURE', 'transmission_id' => 'T-1', 'webhook_id_configured' => true]);
});

it('processes a failed delivery when PayPal sends it again and it verifies', function () {
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1'));

    verifiedBy('FAILURE');
    $this->handler->handle(PayPal::headers(), $payload);

    verifiedBy('SUCCESS');
    // A fresh fake: the earlier stub would answer first.
    Http::swap(new Factory);
    PayPal::fakeHttp(['paypal.test/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);

    expect($this->handler->handle(PayPal::headers(), $payload))->toBeTrue()
        ->and($membership->refresh()->status)->toBe('active')
        ->and(BillingEventLog::where('event_id', 'WH-EVT-1')->first()->processing_status)->toBe('processed')
        ->and(BillingEventLog::count())->toBe(1);
});

it('refuses, reports and records a delivery it could not verify because PayPal could not be asked', function () {
    Exceptions::fake();
    PayPal::fakeHttp(['paypal.test/v1/notifications/verify-webhook-signature' => Http::response([], 500)]);
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1'));

    expect($this->handler->handle(PayPal::headers(), $payload))->toBeFalse();

    Exceptions::assertReportedCount(1);

    expect(BillingEventLog::first()->processing_status)->toBe('failed')
        ->and(webhookLogs()[0]['message'])->toBe('PayPal webhook signature verification request failed.')
        ->and(webhookLogs()[0]['context']['paypal_status'])->toBe(500);
});

it('acknowledges a verified delivery that names no event, without recording it', function () {
    verifiedBy();

    expect($this->handler->handle(PayPal::headers(), ['summary' => 'ping']))->toBeTrue()
        ->and($this->handler->handle(PayPal::headers(), ['id' => 'WH-1']))->toBeTrue()
        ->and(BillingEventLog::count())->toBe(0);
});

it('records an event it does not act on as processed', function () {
    verifiedBy();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);

    expect($this->handler->handle(PayPal::headers(), PayPal::event('WH-PAY', 'PAYMENT.SALE.COMPLETED', PayPal::subscription('I-1'))))->toBeTrue()
        ->and($membership->refresh()->status)->toBe('pending')
        ->and(BillingEventLog::first()->processing_status)->toBe('processed');
});

it('records a failure while applying an event, acknowledges it, and reports it', function () {
    Exceptions::fake();
    verifiedBy();
    PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', PayPal::subscription('I-1', ['start_time' => 'not a date at all']));

    expect($this->handler->handle(PayPal::headers(), $payload))->toBeTrue();

    Exceptions::assertReportedCount(1);
    $log = BillingEventLog::first();

    expect($log->processing_status)->toBe('failed')
        ->and($log->error_message)->not->toBeEmpty()
        ->and($log->processed_at)->toBeNull();
});

it('logs whether the body survives being decoded and encoded again, so a failed signature can be diagnosed', function () {
    verifiedBy('FAILURE');
    $payload = PayPal::event('WH-EVT-1', 'BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-1', 'url' => 'https://x.test/a']);

    $this->handler->handle(PayPal::headers(), $payload, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $this->handler->handle(PayPal::headers(), [...$payload, 'id' => 'WH-EVT-2'], '{"id": "WH-EVT-2",  "padded": true}');

    $lossless = webhookLogs()[0]['context'];
    $lossy = webhookLogs()[1]['context'];

    expect($lossless['round_trip_is_lossless'])->toBeTrue()
        ->and($lossless['raw_body_crc32'])->toBe($lossless['reserialized_crc32'])
        ->and($lossless['transmission_sig'])->toBe('SIG')
        ->and($lossy['round_trip_is_lossless'])->toBeFalse()
        ->and($lossy['raw_body_crc32'])->not->toBe($lossy['reserialized_crc32']);
});

it('acts on each kind of subscription event and on no other', function (string $eventType, bool $acts) {
    verifiedBy();
    $membership = PayPal::membership($this->plan, ['provider_subscription_id' => 'I-1']);

    $this->handler->handle(PayPal::headers(), PayPal::event('WH-EVT', $eventType, PayPal::subscription('I-1')));

    expect($membership->refresh()->meta['event_type'] ?? null)->toBe($acts ? $eventType : null);
})->with([
    ['BILLING.SUBSCRIPTION.CREATED', true],
    ['BILLING.SUBSCRIPTION.ACTIVATED', true],
    ['BILLING.SUBSCRIPTION.UPDATED', true],
    ['BILLING.SUBSCRIPTION.CANCELLED', true],
    ['BILLING.SUBSCRIPTION.EXPIRED', true],
    ['BILLING.SUBSCRIPTION.SUSPENDED', true],
    ['BILLING.SUBSCRIPTION.PAYMENT.FAILED', false],
    ['PAYMENT.SALE.COMPLETED', false],
]);
