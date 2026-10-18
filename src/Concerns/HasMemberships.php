<?php

declare(strict_types=1);

namespace Filagora\Memberships\Concerns;

use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Add to the user model to give it its memberships (and so the relation manager on the user
 * screen) and the two questions the rest of the application asks.
 *
 * @mixin Model
 */
trait HasMemberships
{
    /**
     * @return HasMany<Model, $this>
     */
    public function userMemberships(): HasMany
    {
        return $this->hasMany(Models::class('user_membership'), 'user_id');
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function membershipPlans(): BelongsToMany
    {
        return $this->belongsToMany(Models::class('membership_plan'), (new (Models::class('user_membership')))->getTable(), 'user_id', 'membership_plan_id')
            ->withPivot(['status', 'provider', 'starts_at', 'ends_at', 'current_period_end'])
            ->withTimestamps();
    }

    public function hasActiveMembership(): bool
    {
        return app(Memberships::class)->hasActive($this);
    }

    public function hasActiveMembershipForPlan(int $planId): bool
    {
        return app(Memberships::class)->currentFor($this)
            ->contains(fn (UserMembership $membership): bool => (int) $membership->membership_plan_id === $planId);
    }
}
