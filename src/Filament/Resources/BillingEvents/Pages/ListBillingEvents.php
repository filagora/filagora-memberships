<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\BillingEvents\Pages;

use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Filament\Resources\BillingEvents\BillingEventResource;
use Filagora\Memberships\Support\Models;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBillingEvents extends ListRecords
{
    protected static string $resource = BillingEventResource::class;

    public function getTabs(): array
    {
        return [
            'failed' => Tab::make(__('filagora-memberships::messages.billing_events.tab_failed'))
                ->badge(BillingEventResource::failedCount())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('processing_status', EventStatus::FAILED->value)),
            'all' => Tab::make(__('filagora-memberships::messages.billing_events.tab_all'))
                ->badge(Models::class('billing_event_log')::query()->count()),
        ];
    }
}
