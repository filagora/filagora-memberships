<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\RelationManagers;

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Enums\MembershipStatus;
use Filagora\Memberships\Exceptions\SubscriptionRefused;
use Filagora\Memberships\Filament\Resources\BillingEvents\BillingEventResource;
use Filagora\Memberships\Models\MembershipPlan;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Providers\PayPal\PayPalSubscriptions;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Services\MembershipTransitions;
use Filagora\Memberships\Support\Abilities;
use Filagora\Memberships\Support\Models;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A person's memberships on the user screen, with the safe things staff can do to them. The user
 * model needs the `HasMemberships` trait (or its own `userMemberships` relation).
 */
class UserMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'userMemberships';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Memberships';
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows(Abilities::MANAGE);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('plan.name')->label('Plan'),
            TextEntry::make('status')->badge(),
            TextEntry::make('provider')->badge()->placeholder('-'),
            TextEntry::make('subscription_name'),
            TextEntry::make('provider_subscription_id')->placeholder('-'),
            TextEntry::make('starts_at')->dateTime()->placeholder('-'),
            TextEntry::make('trial_ends_at')->dateTime()->placeholder('-'),
            TextEntry::make('current_period_start')->dateTime()->placeholder('-'),
            TextEntry::make('current_period_end')->dateTime()->placeholder('-'),
            TextEntry::make('ends_at')->dateTime()->placeholder('-'),
            TextEntry::make('canceled_at')->dateTime()->placeholder('-'),
            TextEntry::make('granted_entitlements_snapshot')
                ->label('Granted entitlements')
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                ->placeholder('-')
                ->columnSpanFull(),
            TextEntry::make('meta')
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string) $state)
                ->placeholder('-')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subscription_name')
            ->defaultSort('id', 'desc')
            ->description(__('filagora-memberships::messages.memberships.description'))
            ->columns([
                TextColumn::make('plan.name')->label('Plan')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => MembershipStatus::ACTIVE->value,
                        'warning' => MembershipStatus::CANCELING->value,
                        'danger' => MembershipStatus::CANCELED->value,
                        'gray' => [
                            MembershipStatus::PENDING->value,
                            MembershipStatus::PAST_DUE->value,
                            MembershipStatus::UNPAID->value,
                            MembershipStatus::INCOMPLETE->value,
                            MembershipStatus::INCOMPLETE_EXPIRED->value,
                        ],
                    ])
                    ->sortable(),
                TextColumn::make('provider')->badge()->placeholder('-')->sortable(),
                TextColumn::make('subscription_name')->searchable()->toggleable(),
                TextColumn::make('provider_subscription_id')->label('Provider subscription ID')->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('current_period_end')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('ends_at')->dateTime()->placeholder('-')->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(MembershipStatus::options()),
                SelectFilter::make('provider')->options(fn (): array => ['local' => 'Local'] + app(ProviderRegistry::class)->options()),
            ])
            ->headerActions([
                $this->activateLocalAction(),
                $this->linkSubscriptionAction(),
            ])
            ->recordActions([
                ViewAction::make(),
                $this->markCancelingAction(),
                $this->markCanceledAction(),
                $this->endNowAction(),
                Action::make('billing_events')
                    ->label(__('filagora-memberships::messages.memberships.billing_events'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (UserMembership $record): bool => filled($record->provider_subscription_id) && Gate::allows(Abilities::VIEW_BILLING))
                    ->url(fn (UserMembership $record): string => BillingEventResource::getUrl('index', [
                        'provider' => $record->provider,
                        'subscription_id' => $record->provider_subscription_id,
                    ])),
                DeleteAction::make()
                    ->visible(fn (UserMembership $record): bool => $record->status === MembershipStatus::PENDING->value),
            ]);
    }

    protected function canCreate(): bool
    {
        return static::can('create');
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    private function activateLocalAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('filagora-memberships::messages.memberships.activate'))
            ->modalHeading(__('filagora-memberships::messages.memberships.activate'))
            ->schema([
                Select::make('membership_plan_id')
                    ->label('Membership plan')
                    ->required()
                    ->options(fn (): array => Models::class('membership_plan')::query()
                        ->where('is_active', true)
                        ->where('is_free', true)
                        ->orderBy('sort_order')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
            ])
            ->using(function (array $data): Model {
                /** @var MembershipPlan $plan */
                $plan = Models::class('membership_plan')::query()->findOrFail((int) $data['membership_plan_id']);

                return app(Memberships::class)->activateLocal($this->getOwnerRecord(), $plan, auth()->user());
            })
            ->successNotification(Notification::make()->title(__('filagora-memberships::messages.memberships.activated'))->success());
    }

    private function linkSubscriptionAction(): Action
    {
        return Action::make('link_subscription')
            ->label(__('filagora-memberships::messages.memberships.link'))
            ->modalHeading(__('filagora-memberships::messages.memberships.link'))
            ->modalDescription(__('filagora-memberships::messages.memberships.link_description'))
            ->visible(fn (): bool => $this->canCreate() && app(ProviderRegistry::class)->has('paypal'))
            ->schema([
                TextInput::make('provider_subscription_id')
                    ->label('PayPal subscription ID')
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayPalSubscriptions::class)->link($this->getOwnerRecord(), (string) $data['provider_subscription_id'], auth()->user());
                } catch (SubscriptionRefused $refused) {
                    Notification::make()
                        ->title(__('filagora-memberships::messages.memberships.link_failed'))
                        ->body($refused->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title(__('filagora-memberships::messages.memberships.linked'))->success()->send();
            });
    }

    private function markCancelingAction(): Action
    {
        return Action::make('mark_canceling')
            ->label(__('filagora-memberships::messages.memberships.mark_canceling'))
            ->color('warning')
            ->icon('heroicon-o-clock')
            ->visible(fn (UserMembership $record): bool => in_array($record->status, [
                MembershipStatus::ACTIVE->value,
                MembershipStatus::TRIALING->value,
                MembershipStatus::PAST_DUE->value,
            ], true))
            ->schema([
                DateTimePicker::make('ends_at')
                    ->required()
                    ->seconds(false)
                    ->default(fn (UserMembership $record): string => ($record->current_period_end ?? $record->ends_at ?? now()->addDay())->toDateTimeString()),
            ])
            ->action(function (UserMembership $record, array $data): void {
                app(MembershipTransitions::class)->markCanceling($record, now()->parse((string) $data['ends_at']), auth()->user());

                Notification::make()->title(__('filagora-memberships::messages.memberships.marked_canceling'))->success()->send();
            });
    }

    private function markCanceledAction(): Action
    {
        return Action::make('mark_canceled')
            ->label(__('filagora-memberships::messages.memberships.mark_canceled'))
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (UserMembership $record): bool => $record->status !== MembershipStatus::CANCELED->value)
            ->requiresConfirmation()
            ->action(function (UserMembership $record): void {
                app(MembershipTransitions::class)->markCanceled($record, auth()->user());

                Notification::make()->title(__('filagora-memberships::messages.memberships.marked_canceled'))->success()->send();
            });
    }

    private function endNowAction(): Action
    {
        return Action::make('end_now')
            ->label(__('filagora-memberships::messages.memberships.end_now'))
            ->color('danger')
            ->icon('heroicon-o-stop-circle')
            ->visible(fn (UserMembership $record): bool => $record->isCurrentlyActive())
            ->requiresConfirmation()
            ->action(function (UserMembership $record): void {
                app(MembershipTransitions::class)->endNow($record, auth()->user());

                Notification::make()->title(__('filagora-memberships::messages.memberships.ended'))->success()->send();
            });
    }
}
