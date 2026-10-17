<?php

declare(strict_types=1);

namespace Filagora\Memberships\Entitlements;

use Filagora\Entitlements\Contracts\GrantSource;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Illuminate\Database\Eloquent\Model;

/**
 * The entitlements a person's memberships bring, for the entitlements package to merge with
 * their default and direct grants. Each current membership grants what it was started with (its
 * snapshot), falling back to what its plan grants now when the snapshot is empty.
 */
final class MembershipGrantSource implements GrantSource
{
    public function __construct(private readonly Memberships $memberships) {}

    public function grantsFor(Model $user): array
    {
        $records = [];

        foreach ($this->memberships->currentFor($user) as $membership) {
            foreach ($this->keysOf($membership) as $key) {
                $records[] = $this->record($membership, $key);
            }
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    private function keysOf(UserMembership $membership): array
    {
        $snapshot = $membership->granted_entitlements_snapshot;
        $keys = is_array($snapshot) && $snapshot !== [] ? $snapshot : ($membership->plan?->entitlementList() ?? []);

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys,
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(UserMembership $membership, string $key): array
    {
        $plan = $membership->plan;
        $summary = $plan instanceof MembershipPlan
            ? trim(implode(' ', array_filter([$plan->name, filled($plan->code) ? '('.$plan->code.')' : null])))
            : 'Unknown plan';

        return [
            'entitlement_key' => $key,
            'source_kind' => 'membership',
            'source_label' => 'Membership',
            'source_summary' => $summary,
            'source_reference' => 'Membership #'.$membership->id,
            'grant_type' => 'membership',
            'reason' => null,
            'starts_at' => $membership->starts_at?->toAtomString(),
            'expires_at' => ($membership->ends_at ?? $membership->current_period_end)?->toAtomString(),
            'metadata' => [
                'membership_id' => (int) $membership->id,
                'membership_plan_id' => $plan?->id,
                'membership_plan_code' => $plan?->code,
            ],
        ];
    }
}
