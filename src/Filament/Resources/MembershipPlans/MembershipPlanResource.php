<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\CreateMembershipPlan;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\EditMembershipPlan;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Pages\ListMembershipPlans;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Schemas\MembershipPlanForm;
use Filagora\Memberships\Filament\Resources\MembershipPlans\Tables\MembershipPlansTable;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Support\Abilities;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MembershipPlanResource extends Resource
{
    use GatedByAbility;

    protected static ?string $model = MembershipPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 10;

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Abilities::MANAGE;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('memberships');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-memberships::messages.plans.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-memberships::messages.plans.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-memberships::messages.plans.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipPlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembershipPlans::route('/'),
            'create' => CreateMembershipPlan::route('/create'),
            'edit' => EditMembershipPlan::route('/{record}/edit'),
        ];
    }
}
