# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `Memberships`: `availablePlans`, `currentFor`, `hasActive`, `subscribe` (a free plan is active at once; a paid plan creates a pending membership and returns where to send the person), `cancel` (a provider-billed membership is cancelled at the provider and keeps access to the period end; a local one ends now; one whose provider is not registered is refused rather than marked cancelled while billing continues), `activateLocal` and `subscriptionNameFor`. A refusal is a `SubscriptionRefused` with a `SubscriptionRefusal` reason and an English message.
- The `BillingProvider` contract (and `SyncsPlans` for providers that create their own catalogue), a `ProviderRegistry`, and `ProviderFailed` for a provider that could not do what it was asked.
- `MembershipChanged`, fired when a membership is created or deleted, or when its status, plan, end dates or entitlements change, whoever wrote the change.
- Config: `subscription_prefix` and the `checkout` return pages (a URL or a route name).
- Tables `membership_plans`, `membership_plan_prices`, `user_memberships` and `billing_event_logs`, matching the existing site's tables column for column and index for index, with a schema snapshot for `filagora:adopt`.
- Models `MembershipPlan`, `MembershipPlanPrice`, `UserMembership` and `BillingEventLog`, swappable under `filagora-memberships.models`, and the enums `MembershipStatus`, `BillingInterval` and `EventStatus`.
- `MembershipPlan::defaultPrice()` returns the price marked as the default, otherwise the one added first. The forum this was extracted from took whichever row the database returned first, which can be any currency.
