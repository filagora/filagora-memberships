<?php

declare(strict_types=1);

use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\MembershipPlanPrice;
use Filagora\Memberships\Models\UserMembership;

return [

    /*
    |--------------------------------------------------------------------------
    | Swappable models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass
    | to replace one (here, or under `filagora.models.<key>`, which wins). Subclass
    | the package model; the package always asks for the class through its Models
    | helper, so relations and queries follow your class.
    |
    */
    'models' => [
        'membership_plan' => MembershipPlan::class,
        'membership_plan_price' => MembershipPlanPrice::class,
        'user_membership' => UserMembership::class,
        'billing_event_log' => BillingEventLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription name
    |--------------------------------------------------------------------------
    |
    | The prefix of the name a plan's subscription goes by, followed by the plan code.
    |
    */
    'subscription_prefix' => env('MEMBERSHIPS_SUBSCRIPTION_PREFIX', 'membership'),

    /*
    |--------------------------------------------------------------------------
    | Checkout return pages
    |--------------------------------------------------------------------------
    |
    | Where the provider sends a person after they approve or abandon a purchase: a URL or the
    | name of a route. `subscribe()` takes the same two values as arguments, which win.
    |
    */
    'checkout' => [
        'return_url' => null,
        'cancel_url' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | PayPal
    |--------------------------------------------------------------------------
    |
    | Credentials of a PayPal REST app with the Subscriptions feature switched on. PayPal is only
    | offered once the client id, secret and webhook id are all set. `base_url` is the sandbox
    | API until you point it at the live one (https://api-m.paypal.com). `log_channel` is the
    | log channel for webhook problems (the default channel when empty).
    |
    */
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
        'log_channel' => env('PAYPAL_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gates
    |--------------------------------------------------------------------------
    |
    | Gate abilities guarding the screens, each mapped to the entitlement key that grants it.
    | `manage` covers plans, prices and acting on a person's memberships; `view_billing` the
    | billing provider event log. Remap the keys to your own entitlements.
    |
    */
    'gates' => [
        'filagora.memberships.manage' => 'filagora.memberships.manage',
        'filagora.memberships.view_billing' => 'filagora.memberships.view_billing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook route
    |--------------------------------------------------------------------------
    |
    | `POST {prefix}/{path}` receives PayPal's webhook deliveries, so it is outside the web
    | middleware group (no session, no CSRF token) and checks each delivery with PayPal instead.
    | Switch it off to mount `PayPalWebhookController` yourself.
    |
    */
    'routes' => [
        'enabled' => true,
        'prefix' => '',
        'path' => 'paypal/webhook',
        'middleware' => ['throttle:120,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Billing events keep the provider's payload, which holds the subscriber's and payer's name
    | and email. `event_payload_days` is how long to keep those details: older events lose them
    | (the event, its type and its outcome stay). Null keeps them for ever.
    |
    */
    'retention' => [
        'event_payload_days' => null,
    ],

];
