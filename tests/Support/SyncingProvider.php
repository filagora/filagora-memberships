<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests\Support;

use Filagora\Memberships\Contracts\SyncsPlans;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Models\MembershipPlan;

/**
 * A provider that creates its plans from the package's, for the screens that offer to sync.
 */
class SyncingProvider extends FakeProvider implements SyncsPlans
{
    /** @var list<string> */
    public array $created = ['EUR'];

    public bool $syncFails = false;

    public function __construct()
    {
        parent::__construct('syncing');
    }

    public function label(): string
    {
        return 'Syncing';
    }

    public function syncPlan(MembershipPlan $plan): array
    {
        if ($this->syncFails) {
            throw new ProviderFailed('The provider refused.');
        }

        $plan->forceFill(['provider_product_id' => 'PROD-SYNC'])->save();

        foreach ($this->created as $currency) {
            $plan->prices()->where('currency', $currency)->update(['provider_plan_id' => 'P-'.$currency.'-SYNC']);
        }

        return $this->created;
    }
}
