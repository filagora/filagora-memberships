<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\BillingEvents\Pages;

use Filagora\Memberships\Filament\Resources\BillingEvents\BillingEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBillingEvent extends ViewRecord
{
    protected static string $resource = BillingEventResource::class;
}
