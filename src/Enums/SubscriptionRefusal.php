<?php

declare(strict_types=1);

namespace Filagora\Memberships\Enums;

/**
 * Why a membership could not be started, cancelled or activated.
 */
enum SubscriptionRefusal: string
{
    case PLAN_INACTIVE = 'plan_inactive';
    case PLAN_NOT_FREE = 'plan_not_free';
    case NOT_CONFIGURED = 'not_configured';
    case UNSUPPORTED_PROVIDER = 'unsupported_provider';
    case PROVIDER_NOT_CONFIGURED = 'provider_not_configured';
    case PROVIDER_FAILED = 'provider_failed';
    case NOT_YOUR_MEMBERSHIP = 'not_your_membership';
    case ALREADY_LINKED = 'already_linked';
    case UNKNOWN_SUBSCRIPTION = 'unknown_subscription';
    case UNKNOWN_PLAN = 'unknown_plan';
    case END_DATE_REQUIRED = 'end_date_required';

    public function message(): string
    {
        return __("filagora-memberships::memberships.refusals.{$this->value}");
    }
}
