<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\BillingEvents\Schemas;

use Filagora\Memberships\Models\BillingEventLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BillingEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Event')
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('provider')->badge(),
                        TextEntry::make('event_type')->badge(),
                        TextEntry::make('event_id'),
                        TextEntry::make('resource_id')
                            ->label('Resource ID')
                            ->state(fn (BillingEventLog $record): ?string => $record->paypalResourceId())
                            ->placeholder('-'),
                        TextEntry::make('custom_id')
                            ->label('Custom ID')
                            ->state(fn (BillingEventLog $record): ?string => $record->paypalCustomId())
                            ->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('Processing')
                    ->schema([
                        TextEntry::make('processing_status')->badge(),
                        TextEntry::make('processed_at')->dateTime()->placeholder('-'),
                        TextEntry::make('created_at')->dateTime(),
                        TextEntry::make('error_message')->placeholder('-')->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Payload')
                    ->schema([
                        TextEntry::make('payload')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : ((string) $state !== '' ? (string) $state : '{}'))
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
