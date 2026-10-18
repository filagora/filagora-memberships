<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans\Schemas;

use Filagora\Entitlements\Models\EntitlementDefinition;
use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Contracts\SyncsPlans;
use Filagora\Memberships\Enums\BillingInterval;
use Filagora\Memberships\Support\Currencies;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MembershipPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->default(true),
                        Toggle::make('is_free')
                            ->default(false)
                            ->live(),
                        Select::make('billing_interval')
                            ->options(BillingInterval::options())
                            ->visible(fn (Get $get): bool => ! $get('is_free'))
                            ->native(false),
                        Select::make('provider')
                            ->options(fn (): array => app(ProviderRegistry::class)->options())
                            ->visible(fn (Get $get): bool => ! $get('is_free'))
                            ->live()
                            ->native(false),
                        TextInput::make('provider_product_id')
                            ->label('Provider product ID')
                            ->helperText(__('filagora-memberships::messages.plans.auto_id'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ! $get('is_free') && self::createsPlans($get('provider'))),
                        TextInput::make('sort_order')
                            ->required()
                            ->numeric()
                            ->default(0),
                        TagsInput::make('entitlements')
                            ->suggestions(fn (): array => class_exists(EntitlementDefinition::class)
                                ? EntitlementDefinition::query()->orderBy('key')->pluck('key')->all()
                                : [])
                            ->columnSpanFull(),
                        TagsInput::make('feature_list')
                            ->columnSpanFull(),
                    ]),
                Repeater::make('prices')
                    ->relationship()
                    ->visible(fn (Get $get): bool => ! $get('is_free'))
                    ->schema([
                        Select::make('currency')
                            ->options(Currencies::options())
                            ->required()
                            ->searchable()
                            ->native(false)
                            ->disabled(fn (Get $get): bool => filled($get('provider_plan_id')))
                            ->dehydrated(fn (Get $get): bool => ! filled($get('provider_plan_id')))
                            ->helperText(fn (Get $get): ?string => filled($get('provider_plan_id'))
                                ? __('filagora-memberships::messages.plans.locked_price')
                                : null),
                        TextInput::make('amount')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->disabled(fn (Get $get): bool => filled($get('provider_plan_id')))
                            ->dehydrated(fn (Get $get): bool => ! filled($get('provider_plan_id'))),
                        Toggle::make('is_default'),
                        TextInput::make('provider_plan_id')
                            ->label('Provider plan ID')
                            ->helperText(fn (Get $get): string => self::createsPlans($get('../../provider'))
                                ? __('filagora-memberships::messages.plans.auto_id')
                                : __('filagora-memberships::messages.plans.manual_id'))
                            ->disabled(fn (Get $get): bool => self::createsPlans($get('../../provider')))
                            ->dehydrated(fn (Get $get): bool => ! self::createsPlans($get('../../provider'))),
                    ])
                    ->columns(4)
                    ->defaultItems(1)
                    ->addActionLabel('Add currency')
                    ->itemLabel(fn (array $state): ?string => filled($state['currency'] ?? null)
                        ? strtoupper((string) $state['currency']).' '.number_format((float) ($state['amount'] ?? 0), 2)
                        : null)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Whether the provider creates its plans from this one (so the ids are not typed in).
     */
    private static function createsPlans(mixed $provider): bool
    {
        return is_string($provider) && app(ProviderRegistry::class)->get($provider) instanceof SyncsPlans;
    }
}
