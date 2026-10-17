<?php

declare(strict_types=1);

namespace Filagora\Memberships\Providers\PayPal;

use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The PayPal REST calls the package needs: catalogue products and billing plans, subscriptions,
 * and webhook signature verification. A failed call throws (an `Illuminate\Http\Client\RequestException`
 * for an error response, a `RuntimeException` for a malformed one); `PayPalProvider` turns those into
 * `ProviderFailed`.
 */
class PayPalClient
{
    private const FREQUENCIES = [
        'weekly' => ['unit' => 'WEEK', 'count' => 1],
        'monthly' => ['unit' => 'MONTH', 'count' => 1],
        'quarterly' => ['unit' => 'MONTH', 'count' => 3],
        'yearly' => ['unit' => 'YEAR', 'count' => 1],
    ];

    public function isConfigured(): bool
    {
        return $this->setting('client_id') !== ''
            && $this->setting('client_secret') !== ''
            && $this->setting('webhook_id') !== '';
    }

    public function createProduct(MembershipPlan $plan): string
    {
        $response = $this->request()
            ->post('/v1/catalogs/products', [
                'name' => $plan->name,
                'description' => mb_substr((string) ($plan->description ?: $plan->name), 0, 250),
                'type' => 'SERVICE',
                'category' => 'SOFTWARE',
            ])
            ->throw()
            ->json();

        $productId = trim((string) Arr::get($response, 'id', ''));

        if ($productId === '') {
            throw new RuntimeException('PayPal did not return a product id.');
        }

        return $productId;
    }

    public function createPlan(MembershipPlan $plan, MembershipPlanPrice $price, string $productId): string
    {
        $frequency = self::FREQUENCIES[$plan->billing_interval ?? ''] ?? throw new RuntimeException("Unsupported billing interval for PayPal plan creation: {$plan->billing_interval}");

        $response = $this->request()
            ->post('/v1/billing/plans', [
                'product_id' => $productId,
                'name' => $plan->name.' ('.strtoupper($price->currency).')',
                'description' => mb_substr((string) ($plan->description ?: $plan->name), 0, 250),
                'billing_cycles' => [
                    [
                        'frequency' => ['interval_unit' => $frequency['unit'], 'interval_count' => $frequency['count']],
                        'tenure_type' => 'REGULAR',
                        'sequence' => 1,
                        'total_cycles' => 0,
                        'pricing_scheme' => [
                            'fixed_price' => [
                                'value' => number_format((float) $price->amount, 2, '.', ''),
                                'currency_code' => strtoupper($price->currency),
                            ],
                        ],
                    ],
                ],
                'payment_preferences' => [
                    'auto_bill_outstanding' => true,
                    'payment_failure_threshold' => 3,
                ],
            ])
            ->throw()
            ->json();

        $planId = trim((string) Arr::get($response, 'id', ''));

        if ($planId === '') {
            throw new RuntimeException('PayPal did not return a plan id.');
        }

        return $planId;
    }

    /**
     * @return array{id: string, status: string, approve_url: string, payload: array<string, mixed>}
     */
    public function createSubscription(MembershipPlanPrice $price, UserMembership $membership, string $returnUrl, string $cancelUrl): array
    {
        $response = $this->request()
            ->post('/v1/billing/subscriptions', [
                'plan_id' => $price->provider_plan_id,
                'custom_id' => 'membership:'.$membership->getKey(),
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'SUBSCRIBE_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('PayPal returned an invalid subscription response.');
        }

        $href = '';

        foreach ((array) Arr::get($response, 'links', []) as $link) {
            if (is_array($link) && Arr::get($link, 'rel') === 'approve') {
                $href = trim((string) Arr::get($link, 'href', ''));

                break;
            }
        }

        if ($href === '') {
            throw new RuntimeException('PayPal did not return an approval URL.');
        }

        return [
            'id' => trim((string) Arr::get($response, 'id', '')),
            'status' => trim((string) Arr::get($response, 'status', 'APPROVAL_PENDING')),
            'approve_url' => $href,
            'payload' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSubscription(string $subscriptionId): array
    {
        $response = $this->request()
            ->get('/v1/billing/subscriptions/'.rawurlencode($subscriptionId))
            ->throw()
            ->json();

        if (! is_array($response) || trim((string) Arr::get($response, 'id', '')) === '') {
            throw new RuntimeException('PayPal did not return subscription details.');
        }

        return $response;
    }

    public function cancelSubscription(string $subscriptionId, string $reason): void
    {
        $this->request()
            ->post('/v1/billing/subscriptions/'.rawurlencode($subscriptionId).'/cancel', ['reason' => $reason])
            ->throw();
    }

    /**
     * Ask PayPal whether a webhook delivery is genuine.
     *
     * @param  array<string, string>  $headers  The PayPal-* headers, lower-cased.
     * @param  array<string, mixed>  $payload
     * @return array{verified: bool, verification_status: string, response: array<string, mixed>}
     */
    public function verifyWebhookSignature(array $headers, array $payload): array
    {
        $webhookId = $this->setting('webhook_id');

        if ($webhookId === '') {
            return ['verified' => false, 'verification_status' => '', 'response' => []];
        }

        $response = $this->request()
            ->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo' => $headers['paypal-auth-algo'] ?? '',
                'cert_url' => $headers['paypal-cert-url'] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id' => $webhookId,
                'webhook_event' => $payload,
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('PayPal returned an invalid webhook verification response.');
        }

        $status = strtoupper(trim((string) Arr::get($response, 'verification_status', '')));

        return ['verified' => $status === 'SUCCESS', 'verification_status' => $status, 'response' => $response];
    }

    private function request(): PendingRequest
    {
        return $this->http()
            ->acceptJson()
            ->asJson()
            ->withToken($this->accessToken())
            ->retry(2, 250);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->setting('base_url'), '/'))
            ->connectTimeout(10)
            ->timeout(20);
    }

    /**
     * An access token, kept until shortly before it expires so a burst of calls asks for one.
     */
    private function accessToken(): string
    {
        $clientId = $this->setting('client_id');
        $clientSecret = $this->setting('client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('PayPal API credentials are missing.');
        }

        $key = 'filagora-memberships:paypal-token:'.hash('sha256', $this->setting('base_url').'|'.$clientId.'|'.$clientSecret);
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->http()
            ->asForm()
            ->acceptJson()
            ->withBasicAuth($clientId, $clientSecret)
            ->retry(2, 250)
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json();

        $token = trim((string) Arr::get($response, 'access_token', ''));

        if ($token === '') {
            throw new RuntimeException('PayPal did not return an access token.');
        }

        $lifetime = (int) Arr::get($response, 'expires_in', 0);

        if ($lifetime > 120) {
            Cache::put($key, $token, $lifetime - 60);
        }

        return $token;
    }

    private function setting(string $key): string
    {
        return trim((string) config("filagora-memberships.paypal.{$key}"));
    }
}
