# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Tables `membership_plans`, `membership_plan_prices`, `user_memberships` and `billing_event_logs`, matching the existing site's tables column for column and index for index, with a schema snapshot for `filagora:adopt`.
- Models `MembershipPlan`, `MembershipPlanPrice`, `UserMembership` and `BillingEventLog`, swappable under `filagora-memberships.models`, and the enums `MembershipStatus`, `BillingInterval` and `EventStatus`.
- `MembershipPlan::defaultPrice()` returns the price marked as the default, otherwise the one added first. The forum this was extracted from took whichever row the database returned first, which can be any currency.
