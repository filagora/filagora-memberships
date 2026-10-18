<?php

declare(strict_types=1);

namespace Filagora\Memberships\Privacy;

use Filagora\Core\Contracts\RetentionRule;
use Filagora\Memberships\Services\BillingEvents;

/**
 * Takes the subscriber's and payer's details out of billing events older than the configured
 * window. The events themselves stay.
 */
final class BillingEventPayloadsRetention implements RetentionRule
{
    public function key(): string
    {
        return 'memberships.billing_event_payloads';
    }

    public function prune(bool $dryRun): int
    {
        $days = config('filagora-memberships.retention.event_payload_days');

        if (! is_numeric($days) || (int) $days <= 0) {
            return 0;
        }

        return app(BillingEvents::class)->scrubOlderThan((int) $days, $dryRun);
    }
}
