<?php

declare(strict_types=1);

namespace Filagora\Memberships\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $membership_plan_id
 * @property string $status
 * @property string|null $provider
 * @property string $subscription_name
 * @property string|null $provider_subscription_id
 * @property Carbon|null $starts_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $ends_at
 * @property Carbon|null $canceled_at
 * @property list<string>|null $granted_entitlements_snapshot
 * @property array<string, mixed>|null $meta
 * @property-read MembershipPlan|null $plan
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserMembership extends Model
{
    protected $fillable = [
        'user_id',
        'membership_plan_id',
        'status',
        'provider',
        'subscription_name',
        'provider_subscription_id',
        'starts_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'ends_at',
        'canceled_at',
        'granted_entitlements_snapshot',
        'meta',
    ];

    public function getTable(): string
    {
        return Tables::name('user_memberships');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'granted_entitlements_snapshot' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Models::class('membership_plan'), 'membership_plan_id');
    }

    /**
     * Memberships that may still hold a person's access: active, trialing, or canceling, and not
     * yet past the end of what was paid for.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            MembershipStatus::ACTIVE->value,
            MembershipStatus::TRIALING->value,
            MembershipStatus::CANCELING->value,
        ])->where(function (Builder $inner): void {
            $inner->whereNull('ends_at')
                ->orWhere('ends_at', '>', now())
                ->orWhere('current_period_end', '>', now());
        });
    }

    public function isCurrentlyActive(): bool
    {
        if (in_array($this->status, [MembershipStatus::ACTIVE->value, MembershipStatus::TRIALING->value], true)) {
            return $this->ends_at === null || $this->ends_at->isFuture();
        }

        if ($this->status === MembershipStatus::CANCELING->value) {
            if ($this->current_period_end !== null) {
                return $this->current_period_end->isFuture();
            }

            return $this->ends_at === null || $this->ends_at->isFuture();
        }

        return false;
    }
}
