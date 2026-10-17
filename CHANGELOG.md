# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- With `filagora-entitlements` installed: memberships are a source of entitlements (`MembershipGrantSource`: a current membership grants what it was started with, or what its plan grants when it has no snapshot); every plan's keys feed the definitions list (`MembershipEntitlementKeys`), and saving a plan gives its keys a definition; a membership that changes drops its owner's cached entitlements.
- With `filagora-forums` installed: the `membership` announcement targeting rule (any current membership, or one plan; negatable), and `MembershipsPaidAccess`, a `PaidAccessCheck` ready to bind (it is not bound for you, because what counts as paid is the host's decision).
- PayPal as a billing provider (`paypal`): subscription checkout, cancel, and `syncPlan`, which creates a plan's catalogue product and a billing plan for each price that has none. The credentials are the usual `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID` and `PAYPAL_BASE_URL`, read under `paypal` in the config; PayPal is offered only when the first three are set. The access token is kept until shortly before it expires instead of being requested for every call.
- `PayPalWebhookHandler`: verifies a delivery with PayPal, records it once in `billing_event_logs`, and applies the six `BILLING.SUBSCRIPTION.*` events. An unverifiable delivery is refused (the caller answers 400) with the diagnostics that tell a lossy round trip of the body from a bad signature; a verified one is acknowledged even if applying it failed, and the failure is recorded.
- `PayPalSubscriptions`: `sync` applies PayPal's subscription to the membership that holds it; `link` attaches a subscription made on the old site to a person.
- `BillingEvents`, the idempotent record of provider events, for other providers to reuse.

### Changed from the forum this was extracted from

- A subscription that no membership holds, and that carries no `custom_id` from this package, is ignored. The forum attached it to the latest PayPal membership of the same plan, which could hand one person's subscription, and its access, to someone else.
- A cancelled subscription keeps its access to the end of the period that was paid for: the membership is `canceling` until then and `canceled` after. The forum's cancel webhook ended it at once, undoing the "canceling" state it had just set when the person cancelled from the site. An expired subscription still ends at once.
- PayPal's cancellation time does not overwrite the time the person cancelled.

### Added earlier in this release

- `Memberships`: `availablePlans`, `currentFor`, `hasActive`, `subscribe` (a free plan is active at once; a paid plan creates a pending membership and returns where to send the person), `cancel` (a provider-billed membership is cancelled at the provider and keeps access to the period end; a local one ends now; one whose provider is not registered is refused rather than marked cancelled while billing continues), `activateLocal` and `subscriptionNameFor`. A refusal is a `SubscriptionRefused` with a `SubscriptionRefusal` reason and an English message.
- The `BillingProvider` contract (and `SyncsPlans` for providers that create their own catalogue), a `ProviderRegistry`, and `ProviderFailed` for a provider that could not do what it was asked.
- `MembershipChanged`, fired when a membership is created or deleted, or when its status, plan, end dates or entitlements change, whoever wrote the change.
- Config: `subscription_prefix` and the `checkout` return pages (a URL or a route name).
- Tables `membership_plans`, `membership_plan_prices`, `user_memberships` and `billing_event_logs`, matching the existing site's tables column for column and index for index, with a schema snapshot for `filagora:adopt`.
- Models `MembershipPlan`, `MembershipPlanPrice`, `UserMembership` and `BillingEventLog`, swappable under `filagora-memberships.models`, and the enums `MembershipStatus`, `BillingInterval` and `EventStatus`.
- `MembershipPlan::defaultPrice()` returns the price marked as the default, otherwise the one added first. The forum this was extracted from took whichever row the database returned first, which can be any currency.
