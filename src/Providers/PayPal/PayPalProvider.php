<?php

declare(strict_types=1);

namespace Filagora\Memberships\Providers\PayPal;

use Filagora\Memberships\Billing\ProviderSubscription;
use Filagora\Memberships\Contracts\BillingProvider;
use Filagora\Memberships\Contracts\SyncsPlans;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayPal Subscriptions as a billing provider: checkout, cancel, and creating a plan's catalogue
 * product and billing plans from the package's plans.
 */
class PayPalProvider implements BillingProvider, SyncsPlans
{
    public function __construct(private readonly PayPalClient $client) {}

    public function key(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function subscribe(UserMembership $membership, MembershipPlanPrice $price, string $returnUrl, string $cancelUrl): ProviderSubscription
    {
        try {
            $subscription = $this->client->createSubscription($price, $membership, $returnUrl, $cancelUrl);
        } catch (Throwable $exception) {
            $this->logFailure('PayPal subscription creation failed.', $exception, [
                'membership_plan_id' => $membership->membership_plan_id,
                'paypal_plan_id' => $price->provider_plan_id,
                'currency' => $price->currency,
                'membership_id' => $membership->getKey(),
                'user_id' => $membership->user_id,
            ]);

            throw new ProviderFailed('PayPal could not create the subscription.', $exception);
        }

        return new ProviderSubscription($subscription['id'], $subscription['approve_url'], [
            'paypal_status' => $subscription['status'],
            'paypal_payload' => $subscription['payload'],
        ]);
    }

    public function cancel(UserMembership $membership): void
    {
        try {
            $this->client->cancelSubscription((string) $membership->provider_subscription_id, 'Canceled by customer from the billing page.');
        } catch (Throwable $exception) {
            $this->logFailure('PayPal subscription cancellation failed.', $exception, [
                'membership_id' => $membership->getKey(),
                'paypal_subscription_id' => $membership->provider_subscription_id,
            ]);

            throw new ProviderFailed('PayPal could not cancel the subscription.', $exception);
        }
    }

    public function syncPlan(MembershipPlan $plan): array
    {
        if ($plan->provider !== $this->key()) {
            return [];
        }

        try {
            $productId = trim((string) $plan->provider_product_id);

            if ($productId === '') {
                $productId = $this->client->createProduct($plan);
                $plan->forceFill(['provider_product_id' => $productId])->save();
            }

            $created = [];

            foreach ($plan->prices()->orderBy('id')->get() as $price) {
                if (! $price instanceof MembershipPlanPrice || $price->isSyncedWithProvider()) {
                    continue;
                }

                $price->forceFill(['provider_plan_id' => $this->client->createPlan($plan, $price, $productId)])->save();
                $created[] = $price->currency;
            }

            return $created;
        } catch (Throwable $exception) {
            $this->logFailure('PayPal plan sync failed.', $exception, ['membership_plan_id' => $plan->getKey(), 'plan_code' => $plan->code]);

            throw new ProviderFailed('PayPal could not create the plans.', $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $message, Throwable $exception, array $context): void
    {
        $context['exception'] = $exception::class;
        $context['message'] = $exception->getMessage();

        if ($exception instanceof RequestException) {
            $context['paypal_status'] = $exception->response->status();
            $context['paypal_response_body'] = $exception->response->body();
        }

        Log::channel(config('filagora-memberships.paypal.log_channel'))->error($message, $context);
    }
}
