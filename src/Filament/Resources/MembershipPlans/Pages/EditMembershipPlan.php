<?php

declare(strict_types=1);

namespace Filagora\Memberships\Filament\Resources\MembershipPlans\Pages;

use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Contracts\SyncsPlans;
use Filagora\Memberships\Exceptions\ProviderFailed;
use Filagora\Memberships\Filament\Resources\MembershipPlans\MembershipPlanResource;
use Filagora\Memberships\Models\MembershipPlan;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditMembershipPlan extends EditRecord
{
    protected static string $resource = MembershipPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncPlans')
                ->label(fn (): string => __('filagora-memberships::messages.plans.sync', ['provider' => $this->providerLabel()]))
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->syncingProvider() instanceof SyncsPlans)
                ->action(fn () => $this->syncPlans()),
            DeleteAction::make(),
        ];
    }

    /**
     * Create what the provider is missing for this plan, and say what happened.
     */
    public function syncPlans(): void
    {
        $provider = $this->syncingProvider();
        $plan = $this->record;

        if (! $provider instanceof SyncsPlans || ! $plan instanceof MembershipPlan) {
            return;
        }

        $name = $this->providerLabel();

        try {
            $created = $provider->syncPlan($plan);
        } catch (ProviderFailed $failure) {
            report($failure);

            Notification::make()
                ->title(__('filagora-memberships::messages.plans.sync_failed', ['provider' => $name]))
                ->body($failure->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($created === []) {
            Notification::make()
                ->title(__('filagora-memberships::messages.plans.sync_nothing'))
                ->body(__('filagora-memberships::messages.plans.sync_nothing_body'))
                ->info()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('filagora-memberships::messages.plans.sync_done', ['provider' => $name]))
            ->body(__('filagora-memberships::messages.plans.sync_done_body', ['currencies' => implode(', ', $created)]))
            ->success()
            ->send();

        $this->refreshFormData(['provider_product_id', 'prices']);
    }

    private function syncingProvider(): ?object
    {
        $plan = $this->record;

        return $plan instanceof MembershipPlan ? app(ProviderRegistry::class)->get($plan->provider) : null;
    }

    private function providerLabel(): string
    {
        $plan = $this->record;

        return $plan instanceof MembershipPlan ? (string) app(ProviderRegistry::class)->get($plan->provider)?->label() : '';
    }
}
