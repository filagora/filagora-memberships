<?php

declare(strict_types=1);

namespace Filagora\Memberships;

use Filagora\Memberships\Filament\Resources\BillingEvents\BillingEventResource;
use Filagora\Memberships\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilagoraMembershipsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-memberships';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            MembershipPlanResource::class,
            BillingEventResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
