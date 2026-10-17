<?php

declare(strict_types=1);

namespace Filagora\Memberships\Models;

use Filagora\Core\Support\Tables;
use Filagora\Memberships\Enums\BillingInterval;
use Filagora\Memberships\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_free
 * @property string|null $billing_interval
 * @property string|null $provider
 * @property string|null $provider_product_id
 * @property list<string>|null $entitlements
 * @property list<string>|null $feature_list
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MembershipPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_free',
        'billing_interval',
        'provider',
        'provider_product_id',
        'entitlements',
        'feature_list',
        'sort_order',
    ];

    public function getTable(): string
    {
        return Tables::name('membership_plans');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_free' => 'boolean',
            'entitlements' => 'array',
            'feature_list' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function userMemberships(): HasMany
    {
        return $this->hasMany(Models::class('user_membership'), 'membership_plan_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Models::class('membership_plan_price'), 'membership_plan_id');
    }

    /**
     * The price marked as the default, otherwise the one added first.
     */
    public function defaultPrice(): ?MembershipPlanPrice
    {
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        $prices = $prices->sortBy('id');

        /** @var MembershipPlanPrice|null */
        return $prices->firstWhere('is_default', true) ?? $prices->first();
    }

    public function priceFor(string $currency): ?MembershipPlanPrice
    {
        $currency = strtoupper(trim($currency));
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        /** @var MembershipPlanPrice|null */
        return $prices->first(fn (Model $price): bool => strtoupper((string) $price->getAttribute('currency')) === $currency);
    }

    /**
     * @return list<string>
     */
    public function entitlementList(): array
    {
        return self::cleanList($this->entitlements);
    }

    /**
     * @return list<string>
     */
    public function featureList(): array
    {
        return self::cleanList($this->feature_list);
    }

    public function requiresBilling(): bool
    {
        return ! $this->is_free;
    }

    public function billingIntervalLabel(): ?string
    {
        $value = trim((string) ($this->billing_interval ?? ''));

        if ($value === '') {
            return null;
        }

        return BillingInterval::tryFrom($value)?->label() ?? ucfirst($value);
    }

    /**
     * Whether a person could subscribe now: a free plan always, a paid plan when it is active, has
     * a provider and at least one price that exists at that provider.
     */
    public function isCheckoutReady(): bool
    {
        if ($this->is_free) {
            return true;
        }

        return $this->is_active
            && trim((string) $this->provider) !== ''
            && $this->prices()->whereNotNull('provider_plan_id')->where('provider_plan_id', '!=', '')->exists();
    }

    /**
     * @return list<string>
     */
    private static function cleanList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
