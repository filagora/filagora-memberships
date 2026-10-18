<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\BillingEvents\Tables;

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Models\BillingEventLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BillingEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->numeric()->sortable(),
                TextColumn::make('provider')->badge()->sortable()->searchable(),
                TextColumn::make('processing_status')
                    ->badge()
                    ->colors([
                        'success' => EventStatus::PROCESSED->value,
                        'danger' => EventStatus::FAILED->value,
                        'warning' => EventStatus::PROCESSING->value,
                        'gray' => EventStatus::PENDING->value,
                    ])
                    ->sortable(),
                TextColumn::make('event_type')->badge()->searchable()->sortable(),
                TextColumn::make('event_id')->label('Event ID')->searchable()->toggleable(),
                TextColumn::make('resource_id')
                    ->label('Resource ID')
                    ->state(fn (BillingEventLog $record): ?string => $record->paypalResourceId())
                    ->placeholder('-')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('payload', 'like', '%'.addcslashes($search, '%_\\').'%')),
                TextColumn::make('error_message')
                    ->limit(80)
                    ->tooltip(fn (BillingEventLog $record): ?string => $record->error_message)
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('processed_at')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(fn (): array => app(ProviderRegistry::class)->options()),
                SelectFilter::make('processing_status')
                    ->options(EventStatus::options()),
            ])
            ->recordActions([ViewAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([]);
    }
}
