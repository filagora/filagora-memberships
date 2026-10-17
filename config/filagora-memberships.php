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

];
