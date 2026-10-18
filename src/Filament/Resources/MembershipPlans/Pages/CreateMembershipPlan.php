<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans\Pages;

use Filagora\Memberships\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipPlan extends CreateRecord
{
    protected static string $resource = MembershipPlanResource::class;
}
