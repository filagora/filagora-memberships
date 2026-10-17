<?php

declare(strict_types=1);

namespace Filagora\Memberships\Services;

use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Support\Models;
use Throwable;

/**
 * The record of what billing providers have told us, one row per provider event. A provider
 * delivers the same event more than once; a row that is already processed is never processed
 * again.
 */
class BillingEvents
{
    /**
     * The event's row, not yet saved when it is new. Null when the delivery names no event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function find(string $provider, string $eventId, string $eventType, array $payload): ?BillingEventLog
    {
        if ($eventId === '' || $eventType === '') {
            return null;
        }

        /** @var BillingEventLog */
        return Models::class('billing_event_log')::query()
            ->firstOrNew(['provider' => $provider, 'event_id' => $eventId])
            ->fill(['event_type' => $eventType, 'payload' => $payload]);
    }

    public function isProcessed(BillingEventLog $log): bool
    {
        return $log->exists && $log->processing_status === EventStatus::PROCESSED->value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markProcessing(BillingEventLog $log, string $eventType, array $payload): void
    {
        $log->fill([
            'event_type' => $eventType,
            'processing_status' => EventStatus::PROCESSING->value,
            'processed_at' => null,
            'error_message' => null,
            'payload' => $payload,
        ])->save();
    }

    public function markProcessed(BillingEventLog $log): void
    {
        $log->forceFill([
            'processing_status' => EventStatus::PROCESSED->value,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(?BillingEventLog $log, array $payload, string $eventType, string|Throwable $reason): void
    {
        if (! $log instanceof BillingEventLog) {
            return;
        }

        $log->fill([
            'event_type' => $eventType,
            'processing_status' => EventStatus::FAILED->value,
            'processed_at' => null,
            'error_message' => mb_substr($reason instanceof Throwable ? $reason->getMessage() : $reason, 0, 65535),
            'payload' => $payload,
        ])->save();
    }
}
