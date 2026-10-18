<?php

declare(strict_types=1);

namespace Filagora\Memberships;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Navigation\NavigationGroupDefinition;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Support\GateAbilities;
use Filagora\Entitlements\Registry\Definition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Forums\Targeting\TargetingRules;
use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Entitlements\BumpEntitlementsCache;
use Filagora\Memberships\Entitlements\MembershipEntitlementKeys;
use Filagora\Memberships\Entitlements\MembershipGrantSource;
use Filagora\Memberships\Entitlements\SyncPlanDefinitions;
use Filagora\Memberships\Events\MembershipChanged;
use Filagora\Memberships\Forums\MembershipRule;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Privacy\BillingEventPayloadsRetention;
use Filagora\Memberships\Privacy\MembershipsDataContributor;
use Filagora\Memberships\Providers\PayPal\PayPalClient;
use Filagora\Memberships\Providers\PayPal\PayPalProvider;
use Filagora\Memberships\Providers\PayPal\PayPalSubscriptions;
use Filagora\Memberships\Providers\PayPal\PayPalWebhookHandler;
use Filagora\Memberships\Services\BillingEvents;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Support\Models;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MembershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-memberships.php', 'filagora-memberships');

        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(Memberships::class);
        $this->app->singleton(BillingEvents::class);
        $this->app->singleton(PayPalClient::class);
        $this->app->singleton(PayPalProvider::class);
        $this->app->singleton(PayPalSubscriptions::class);
        $this->app->singleton(PayPalWebhookHandler::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('memberships', 'Filagora Memberships', 'filagora/filagora-memberships', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('memberships', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->app->make(ProviderRegistry::class)->register($this->app->make(PayPalProvider::class));

        $this->app->make(PrivacyContributorRegistry::class)->register($this->app->make(MembershipsDataContributor::class));
        $this->app->make(RetentionRuleRegistry::class)->register(new BillingEventPayloadsRetention);

        if (config('filagora-memberships.routes.enabled', true)) {
            $this->registerWebhookRoute();
        }

        $this->registerNavigationGroup();
        GateAbilities::define('filagora-memberships.gates');
        $this->announceMembershipChanges();
        $this->joinEntitlements();
        $this->joinForums();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-memberships');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-memberships.php' => config_path('filagora-memberships.php'),
            ], 'filagora-memberships-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'filagora-memberships-migrations');
        }
    }

    /**
     * Tell the rest of the application when a membership changes in a way that changes what it
     * grants, whoever made the change.
     */
    private function announceMembershipChanges(): void
    {
        $model = Models::class('user_membership');

        $model::created(function (UserMembership $membership): void {
            Event::dispatch(new MembershipChanged($membership, null));
        });

        $model::updated(function (UserMembership $membership): void {
            $relevant = ['status', 'membership_plan_id', 'ends_at', 'current_period_end', 'granted_entitlements_snapshot'];

            if ($membership->wasChanged($relevant)) {
                Event::dispatch(new MembershipChanged($membership, (string) $membership->getOriginal('status')));
            }
        });

        $model::deleted(function (UserMembership $membership): void {
            Event::dispatch(new MembershipChanged($membership, (string) $membership->status, deleted: true));
        });
    }

    /**
     * With the entitlements package installed, memberships count as a source of entitlements,
     * their plans feed the definitions list, and a membership that changes drops its owner's
     * cached entitlements.
     */
    private function joinEntitlements(): void
    {
        if (! class_exists(EntitlementRegistry::class)) {
            return;
        }

        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (GateAbilities::keys('filagora-memberships.gates') as $entitlement) {
            $registry->define(new Definition($entitlement, wildcardChildren: false));
        }

        $registry->grantSource($this->app->make(MembershipGrantSource::class));
        $registry->keySource(new MembershipEntitlementKeys);

        Models::class('membership_plan')::observe(SyncPlanDefinitions::class);
        Event::listen(MembershipChanged::class, [BumpEntitlementsCache::class, 'handle']);
    }

    /**
     * With the forums package installed, announcements can target members. The paid-access check
     * is not bound here, because the host decides what "paid" means; `MembershipsPaidAccess` is
     * ready to bind.
     */
    private function joinForums(): void
    {
        if (class_exists(TargetingRules::class)) {
            $this->app->make(TargetingRules::class)->register($this->app->make(MembershipRule::class));
        }
    }

    private function registerNavigationGroup(): void
    {
        $this->app->make(NavigationGroupRegistry::class)->register(new NavigationGroupDefinition(
            key: 'memberships',
            label: fn (): string => __('filagora-memberships::messages.navigation_groups.memberships'),
            icon: 'heroicon-o-credit-card',
            sort: 25,
        ));
    }

    private function registerWebhookRoute(): void
    {
        Route::prefix((string) config('filagora-memberships.routes.prefix', ''))
            ->middleware((array) config('filagora-memberships.routes.middleware', []))
            ->group(__DIR__.'/../routes/webhooks.php');
    }
}
