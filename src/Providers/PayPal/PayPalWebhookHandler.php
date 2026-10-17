<?php

declare(strict_types=1);

namespace Filagora\Memberships\Providers\PayPal;

use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Services\BillingEvents;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes a PayPal webhook delivery: checks it with PayPal, records it once, and applies the
 * subscription events to the membership they are about. A delivery that cannot be verified is
 * refused (the caller answers 400, so PayPal retries); one that was verified is acknowledged even
 * if applying it failed, because the failure is recorded and PayPal resending it would not help.
 */
class PayPalWebhookHandler
{
    private const SUBSCRIPTION_EVENTS = [
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
    ];

    public function __construct(
        private readonly PayPalClient $client,
        private readonly PayPalSubscriptions $subscriptions,
        private readonly BillingEvents $events,
    ) {}

    /**
     * @param  array<string, string>  $headers  The PayPal-* headers, lower-cased.
     * @param  array<string, mixed>  $payload
     * @return bool Whether to acknowledge the delivery.
     */
    public function handle(array $headers, array $payload, string $rawBody = ''): bool
    {
        $eventId = trim((string) Arr::get($payload, 'id', ''));
        $eventType = trim((string) Arr::get($payload, 'event_type', ''));
        $log = $this->events->find('paypal', $eventId, $eventType, $payload);

        try {
            $verification = $this->client->verifyWebhookSignature($headers, $payload);
        } catch (Throwable $exception) {
            report($exception);

            $this->channel()->error('PayPal webhook signature verification request failed.', [
                ...$this->context($headers, $payload, $rawBody),
                'paypal_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);
            $this->events->markFailed($log, $payload, $eventType, $exception);

            return false;
        }

        if (! $verification['verified']) {
            $this->channel()->warning('PayPal webhook signature verification failed.', [
                ...$this->context($headers, $payload, $rawBody),
                'paypal_verification_status' => $verification['verification_status'],
                'paypal_verification_response' => $verification['response'],
            ]);
            $this->events->markFailed($log, $payload, $eventType, 'PayPal webhook signature verification failed.');

            return false;
        }

        if (! $log instanceof BillingEventLog || $this->events->isProcessed($log)) {
            return true;
        }

        $this->events->markProcessing($log, $eventType, $payload);

        try {
            if (in_array($eventType, self::SUBSCRIPTION_EVENTS, true)) {
                $resource = Arr::get($payload, 'resource', []);

                $this->subscriptions->sync(is_array($resource) ? $resource : [], $eventType);
            }

            $this->events->markProcessed($log);
        } catch (Throwable $exception) {
            report($exception);

            $this->events->markFailed($log, $payload, $eventType, $exception);
        }

        return true;
    }

    private function channel(): LoggerInterface
    {
        return Log::channel(config('filagora-memberships.paypal.log_channel'));
    }

    /**
     * What identifies an unverified delivery, so it can be matched to an event in the PayPal
     * dashboard by hand. PayPal signs the checksum of the bytes it sent; we verify a decoded and
     * re-encoded copy, so a field that does not survive the round trip makes the signature fail.
     * Both checksums are logged, which says outright whether that is what happened, and the raw
     * body and signature make the delivery replayable offline.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function context(array $headers, array $payload, string $rawBody): array
    {
        $optional = [
            'summary' => Arr::get($payload, 'summary'),
            'event_create_time' => Arr::get($payload, 'create_time'),
            'custom_id' => Arr::get($payload, 'resource.custom_id'),
            'plan_id' => Arr::get($payload, 'resource.plan_id'),
            'subscriber_name' => trim(implode(' ', array_filter([
                (string) Arr::get($payload, 'resource.subscriber.name.given_name', ''),
                (string) Arr::get($payload, 'resource.subscriber.name.surname', ''),
            ]))),
            'subscriber_email' => Arr::get($payload, 'resource.subscriber.email_address'),
            'payer_name' => trim(implode(' ', array_filter([
                (string) Arr::get($payload, 'resource.payer.name.given_name', ''),
                (string) Arr::get($payload, 'resource.payer.name.surname', ''),
            ]))),
            'payer_email' => Arr::get($payload, 'resource.payer.email_address'),
            'amount' => Arr::get($payload, 'resource.billing_info.last_payment.amount.value') ?? Arr::get($payload, 'resource.amount.value'),
            'currency' => Arr::get($payload, 'resource.billing_info.last_payment.amount.currency_code') ?? Arr::get($payload, 'resource.amount.currency_code'),
        ];

        $diagnostics = [];

        if ($rawBody !== '') {
            // Slashes are compared unescaped: PayPal decodes our request before recomputing the
            // checksum, so escaping is a transport detail that would flag every healthy payload.
            $reserialized = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $diagnostics = [
                'raw_body_bytes' => strlen($rawBody),
                'raw_body_crc32' => sprintf('%u', crc32($rawBody)),
                'reserialized_crc32' => is_string($reserialized) ? sprintf('%u', crc32($reserialized)) : null,
                'round_trip_is_lossless' => is_string($reserialized) && $reserialized === $rawBody,
                'transmission_sig' => trim((string) ($headers['paypal-transmission-sig'] ?? '')),
                'webhook_id' => trim((string) config('filagora-memberships.paypal.webhook_id')),
                'raw_body' => strlen($rawBody) > 8192 ? substr($rawBody, 0, 8192).'…[truncated]' : $rawBody,
            ];
        }

        return [
            'event_id' => trim((string) Arr::get($payload, 'id', '')),
            'event_type' => trim((string) Arr::get($payload, 'event_type', '')),
            'resource_id' => trim((string) Arr::get($payload, 'resource.id', '')),
            'transmission_id' => trim((string) ($headers['paypal-transmission-id'] ?? '')),
            'transmission_time' => trim((string) ($headers['paypal-transmission-time'] ?? '')),
            'auth_algo' => trim((string) ($headers['paypal-auth-algo'] ?? '')),
            'cert_url' => trim((string) ($headers['paypal-cert-url'] ?? '')),
            'webhook_id_configured' => trim((string) config('filagora-memberships.paypal.webhook_id')) !== '',
            ...$diagnostics,
            ...array_filter(
                array_map(fn (mixed $value): ?string => is_scalar($value) ? trim((string) $value) : null, $optional),
                fn (?string $value): bool => $value !== null && $value !== '',
            ),
        ];
    }
}
