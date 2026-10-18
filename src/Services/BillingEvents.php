<?php

declare(strict_types=1);

namespace Filagora\Memberships\Services;

use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    /**
     * Where a provider puts the details of the person: PayPal's subscriber and payer.
     */
    private const PERSONAL_PATHS = ['resource.subscriber', 'resource.payer'];

    /**
     * The events whose resource is one of the given provider subscriptions.
     *
     * @param  list<string>  $subscriptionIds
     * @return Collection<int, BillingEventLog>
     */
    public function forSubscriptions(array $subscriptionIds): Collection
    {
        if ($subscriptionIds === []) {
            return new Collection;
        }

        /** @var Collection<int, BillingEventLog> */
        return Models::class('billing_event_log')::query()
            ->where(function ($query) use ($subscriptionIds): void {
                foreach ($subscriptionIds as $id) {
                    $query->orWhere('payload', 'like', '%"id":"'.addcslashes($id, '%_\\').'"%');
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Model $event): bool => $event instanceof BillingEventLog && in_array($event->paypalResourceId(), $subscriptionIds, true))
            ->values();
    }

    /**
     * Take the subscriber's and payer's details out of an event's payload. Returns whether it held
     * any.
     */
    public function scrubPersonalDetails(BillingEventLog $event): bool
    {
        $payload = $event->payload;
        $found = false;

        foreach (self::PERSONAL_PATHS as $path) {
            if (Arr::has($payload, $path)) {
                Arr::forget($payload, $path);
                $found = true;
            }
        }

        if ($found) {
            $event->forceFill(['payload' => $payload])->save();
        }

        return $found;
    }

    /**
     * Scrub the events received more than `$days` days ago that still hold personal details.
     *
     * @return int How many events held them (and, unless `$dryRun`, no longer do).
     */
    public function scrubOlderThan(int $days, bool $dryRun): int
    {
        $count = 0;

        Models::class('billing_event_log')::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereRaw("JSON_CONTAINS_PATH(payload, 'one', '$.resource.subscriber', '$.resource.payer')")
            ->orderBy('id')
            ->each(function (Model $event) use (&$count, $dryRun): void {
                if (! $event instanceof BillingEventLog) {
                    return;
                }

                $count++;

                if (! $dryRun) {
                    $this->scrubPersonalDetails($event);
                }
            });

        return $count;
    }
}
