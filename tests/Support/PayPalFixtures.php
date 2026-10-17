<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Support;

use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;

final class PayPalFixtures
{
    public static function configure(): void
    {
        Cache::flush();
        config([
            'filagora-memberships.paypal.client_id' => 'client',
            'filagora-memberships.paypal.client_secret' => 'secret',
            'filagora-memberships.paypal.webhook_id' => 'WH-1',
            'filagora-memberships.paypal.base_url' => 'https://paypal.test',
            'logging.default' => 'array',
            'logging.channels.array' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        ]);
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    public static function fakeHttp(array $routes = []): void
    {
        Http::fake($routes + [
            'paypal.test/v1/oauth2/token' => Http::response(['access_token' => 'TOKEN', 'expires_in' => 3600]),
            '*' => Http::response([], 404),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function plan(array $attributes = []): MembershipPlan
    {
        return MembershipPlan::create(array_merge([
            'code' => 'plan_'.uniqid(),
            'name' => 'Plan',
            'is_active' => true,
            'provider' => 'paypal',
            'billing_interval' => 'monthly',
            'entitlements' => ['forums.a.view'],
        ], $attributes));
    }

    /**
     * A paid PayPal plan with a USD and a EUR price that exist at PayPal.
     */
    public static function paidPlan(array $attributes = []): MembershipPlan
    {
        $plan = self::plan($attributes);
        $plan->prices()->create(['currency' => 'USD', 'amount' => '5.00', 'provider_plan_id' => 'P-USD', 'is_default' => true]);
        $plan->prices()->create(['currency' => 'EUR', 'amount' => '4.50', 'provider_plan_id' => 'P-EUR']);

        return $plan->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function membership(MembershipPlan $plan, array $attributes = []): UserMembership
    {
        return UserMembership::create(array_merge([
            'user_id' => TestUser::make()->id,
            'membership_plan_id' => $plan->id,
            'provider' => 'paypal',
            'status' => 'pending',
            'subscription_name' => 'membership_plan',
        ], $attributes));
    }

    /**
     * PayPal's subscription resource.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function subscription(string $id, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id,
            'status' => 'ACTIVE',
            'plan_id' => 'P-USD',
            'start_time' => '2026-10-01T10:00:00Z',
            'status_update_time' => '2026-10-17T09:00:00Z',
            'subscriber' => ['payer_id' => 'PAYER-1'],
            'billing_info' => ['next_billing_time' => '2026-11-01T10:00:00Z'],
        ], $overrides);
    }

    /**
     * A webhook delivery wrapping a subscription resource.
     *
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public static function event(string $eventId, string $type, array $resource): array
    {
        return ['id' => $eventId, 'event_type' => $type, 'resource' => $resource];
    }

    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.paypal.test/cert',
            'paypal-transmission-id' => 'T-1',
            'paypal-transmission-sig' => 'SIG',
            'paypal-transmission-time' => '2026-10-17T12:00:00Z',
        ];
    }
}
