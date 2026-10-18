<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans\Pages;

use Filagora\Memberships\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembershipPlans extends ListRecords
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
