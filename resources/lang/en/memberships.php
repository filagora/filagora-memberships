<?php

declare(strict_types=1);

return [
    'refusals' => [
        'plan_inactive' => 'This membership plan is not available.',
        'plan_not_free' => 'Only a free plan can be activated this way.',
        'not_configured' => 'This membership cannot be purchased yet.',
        'unsupported_provider' => 'This membership uses a payment method that is not available.',
        'provider_not_configured' => 'The payment method for this membership is not set up.',
        'provider_failed' => 'We could not reach the payment provider. Please try again later.',
        'not_your_membership' => 'That membership does not belong to you.',
        'already_linked' => 'This subscription is already linked to a membership.',
        'unknown_subscription' => 'The payment provider has no such subscription.',
        'unknown_plan' => 'No membership plan is set up for that subscription.',
        'end_date_required' => 'An end date is required to mark a membership as canceling.',
    ],

    'webhook_invalid' => 'The webhook delivery could not be processed.',
];
