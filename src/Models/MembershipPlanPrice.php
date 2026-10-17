<?php

declare(strict_types=1);

namespace Filagora\Memberships\Models;

use Filagora\Core\Support\Tables;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $membership_plan_id
 * @property string $currency
 * @property string $amount
 * @property string|null $provider_plan_id
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MembershipPlanPrice extends Model
{
    protected $fillable = [
        'currency',
        'amount',
        'provider_plan_id',
        'is_default',
    ];

    public function getTable(): string
    {
        return Tables::name('membership_plan_prices');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function membershipPlan(): BelongsTo
    {
        return $this->belongsTo(Models::class('membership_plan'), 'membership_plan_id');
    }

    /**
     * Whether this price exists at the billing provider.
     */
    public function isSyncedWithProvider(): bool
    {
        return trim((string) $this->provider_plan_id) !== '';
    }

    public function formattedAmount(): string
    {
        return strtoupper(trim($this->currency)).' '.number_format((float) $this->amount, 2);
    }
}
