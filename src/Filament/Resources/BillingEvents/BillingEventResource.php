<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\BillingEvents;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Memberships\Enums\EventStatus;
use Filagora\Memberships\Filament\Resources\BillingEvents\Pages\ListBillingEvents;
use Filagora\Memberships\Filament\Resources\BillingEvents\Pages\ViewBillingEvent;
use Filagora\Memberships\Filament\Resources\BillingEvents\Schemas\BillingEventInfolist;
use Filagora\Memberships\Filament\Resources\BillingEvents\Tables\BillingEventsTable;
use Filagora\Memberships\Models\BillingEventLog;
use Filagora\Memberships\Support\Abilities;
use Filagora\Memberships\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The log of what billing providers told us. Read-only: it is evidence, not something to edit.
 */
class BillingEventResource extends Resource
{
    use GatedByAbility;

    protected static ?string $model = BillingEventLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 20;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return in_array($action, ['viewAny', 'view'], true) ? Abilities::VIEW_BILLING : null;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('memberships');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-memberships::messages.billing_events.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-memberships::messages.billing_events.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-memberships::messages.billing_events.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::failedCount();

        return $count > 0 ? ($count > 99 ? '99+' : (string) $count) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $count = static::failedCount();

        return $count > 0 ? trans_choice('filagora-memberships::messages.billing_events.badge_tooltip', $count, ['count' => $count]) : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BillingEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingEventsTable::configure($table);
    }

    /**
     * The list can be narrowed from a link (`?provider=` and `?subscription_id=`), which is how a
     * membership's own screen opens the events about its subscription.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $provider = trim((string) request()->query('provider', ''));

        if ($provider !== '') {
            $query->where('provider', $provider);
        }

        $subscriptionId = trim((string) request()->query('subscription_id', ''));

        if ($subscriptionId !== '') {
            $query->where(function (Builder $nested) use ($subscriptionId): void {
                $nested
                    ->where('payload', 'like', '%'.addcslashes($subscriptionId, '%_\\').'%')
                    ->orWhere('event_id', $subscriptionId);
            });
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingEvents::route('/'),
            'view' => ViewBillingEvent::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function failedCount(): int
    {
        return Models::class('billing_event_log')::query()->where('processing_status', EventStatus::FAILED->value)->count();
    }
}
