<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans\Tables;

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\BillingInterval;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class MembershipPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('is_free')->boolean(),
                TextColumn::make('billing_interval')
                    ->label('Plan period')
                    ->formatStateUsing(fn (?string $state): string => BillingInterval::tryFrom($state ?? '')?->label() ?? (string) ($state ?? '-'))
                    ->toggleable(),
                TextColumn::make('provider')
                    ->formatStateUsing(fn (?string $state): string => app(ProviderRegistry::class)->get($state)?->label() ?? (string) ($state ?? '-'))
                    ->toggleable(),
                TextColumn::make('prices_count')
                    ->label('Currencies')
                    ->counts('prices')
                    ->toggleable(),
                TextColumn::make('sort_order')->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }
}
