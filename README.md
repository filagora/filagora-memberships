# Filagora Memberships

Memberships and billing for Filagora: plans with a price per currency, member subscriptions, PayPal
checkout and webhooks, free and locally activated plans, and the entitlements a membership brings.
Requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core); it works with
[`filagora/filagora-entitlements`](https://github.com/filagora/filagora-entitlements) and
[`filagora/filagora-forums`](https://github.com/filagora/filagora-forums) when they are installed.

> The package is at `0.1`: the API may change between `0.x` minor versions. There is no
> member-facing frontend: the billing page and the checkout return pages are yours. PayPal is the
> only payment provider included; the `BillingProvider` contract is there for others.

## What it does

- **Plans and prices.** A plan has a code, a name, a billing interval, a provider, the entitlements it
  grants and a feature list. A paid plan has one price per currency, each with the provider's own id.
  A free plan has no price. Staff manage them in Filament; the PayPal catalogue product and billing
  plans are created from the plan with one button.
- **Starting a membership.** `Memberships::subscribe($user, $plan, $currency, $returnUrl, $cancelUrl)`
  activates a free plan at once. For a paid plan it creates a pending membership and returns where to
  send the person (`$result->redirectUrl`); the membership stays pending until the provider's webhook
  confirms it. A problem is a `SubscriptionRefused` with a reason (`SubscriptionRefusal`) and an
  English message you can show.
- **Cancelling.** `Memberships::cancel($user, $membership)` cancels at the provider and keeps access to
  the end of the period already paid for; a free or local membership ends now. A membership whose
  provider is not registered is refused rather than marked cancelled while the provider keeps billing.
- **Staff actions.** On the user screen: activate a free plan, link a PayPal subscription made before
  this package, mark canceling, mark canceled, end now. The changes are recorded with who made them.
  They change the membership only; a subscription still billing at PayPal has to be cancelled there.
- **Entitlements.** With `filagora-entitlements` installed, a current membership grants what it was
  started with (or what its plan grants, when it has no snapshot); every plan's keys feed the
  definitions list; a membership that changes drops its owner's cached entitlements.
- **Forums.** With `filagora-forums` installed, announcements can target members (any membership, or
  one plan), and `MembershipsPaidAccess` is ready to bind as the forums' paid-access check.
- **Billing events.** Every webhook delivery is recorded once, with its outcome. Staff see them in a
  read-only screen with the failures first and a badge in the navigation.
- **Privacy.** A person's memberships and the events received about them are included in their data
  export; erasure takes their details out of those events. A retention window can do the same for old
  events.

## Install

```bash
composer require filagora/filagora-memberships
php artisan migrate
```

Add the trait to your user model (it gives the relation the user screen needs, and the two questions
the rest of the application asks):

```php
use Filagora\Memberships\Concerns\HasMemberships;

class User extends Authenticatable
{
    use HasMemberships;   // userMemberships(), membershipPlans(), hasActiveMembership(), hasActiveMembershipForPlan()
}
```

Register the plugin on your panel, and the relation manager on your user resource:

```php
->plugin(\Filagora\Memberships\FilagoraMembershipsPlugin::make())

// UserResource::getRelations()
\Filagora\Memberships\Filament\RelationManagers\UserMembershipsRelationManager::class,
```

Two abilities guard the screens, each mapped to an entitlement key under `gates` in
`config/filagora-memberships.php`: `filagora.memberships.manage` (plans, prices, acting on a person's
memberships) and `filagora.memberships.view_billing` (the billing event log).

## PayPal

Create a REST app in the PayPal developer dashboard with the **Subscriptions** feature switched on, and
set:

```dotenv
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_BASE_URL=https://api-m.paypal.com   # the sandbox API when unset
```

PayPal is offered only when the first three are set. In the dashboard, point a webhook at
`https://your-site/paypal/webhook` and subscribe it to the `BILLING.SUBSCRIPTION.*` events (created,
activated, updated, cancelled, expired, suspended). The route is registered for you, outside the web
group (no session, no CSRF token), rate limited, and checks every delivery with PayPal. Change or
switch it off under `routes` in the config to mount `PayPalWebhookController` yourself.

Create a plan, add a price for each currency, and press **Sync PayPal plans** on its edit page: the
catalogue product and a billing plan per currency are created at PayPal and their ids stored. A synced
price is locked; to change it, remove the row, save, add a new one and sync again (existing
subscribers keep what they agreed to).

## Using it from your billing page

```php
$result = app(\Filagora\Memberships\Services\Memberships::class)
    ->subscribe($request->user(), $plan, $request->input('currency'), route('billing.done'), route('billing.cancelled'));

return $result->requiresRedirect() ? redirect()->away($result->redirectUrl) : back();
```

The return pages can also be set once under `checkout` in the config (a URL or a route name). Catch
`SubscriptionRefused` and show `$refused->getMessage()`.

## Other payment providers

Implement `Filagora\Memberships\Contracts\BillingProvider` (and `SyncsPlans` if the provider's plans
are created from ours) and register it with the `ProviderRegistry`; a plan names its provider by key.
`BillingEvents` is the idempotent record to log the provider's webhook events in. Stripe is not
included.

## Adopting an existing site

The four tables (`membership_plans`, `membership_plan_prices`, `user_memberships`,
`billing_event_logs`) match the forum this package was extracted from column for column and index for
index. Run `php artisan filagora:adopt --package=memberships` to record the migration as run; it
checks the tables against the package's snapshot first and refuses on any difference. Cashier's
`subscriptions` tables are not part of this package.

## Differences from the forum it was extracted from

- A PayPal subscription that no membership holds, and that carries no `custom_id` from this package, is
  ignored. The forum attached it to the latest PayPal membership of the same plan, which could give one
  person's subscription (and its access) to someone else. Use **Link subscription** for subscriptions
  made before this package.
- A cancelled PayPal subscription keeps its access to the end of the period that was paid for. The
  forum's cancel webhook ended it at once, undoing the "canceling" state it had just set.
- With no price marked as the default, the default is the first one added, not whichever row the
  database returned first.
- The access token is cached until shortly before it expires.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`; PayPal is faked at the HTTP layer.

## Licence

MIT. See [LICENSE](LICENSE).
