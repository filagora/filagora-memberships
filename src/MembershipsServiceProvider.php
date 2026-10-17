<?php

declare(strict_types=1);

namespace Filagora\Memberships;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Memberships\Billing\ProviderRegistry;
use Filagora\Memberships\Events\MembershipChanged;
use Filagora\Memberships\Models\UserMembership;
use Filagora\Memberships\Services\Memberships;
use Filagora\Memberships\Support\Models;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MembershipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-memberships.php', 'filagora-memberships');

        $this->app->singleton(ProviderRegistry::class);
        $this->app->singleton(Memberships::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('memberships', 'Filagora Memberships', 'filagora/filagora-memberships', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('memberships', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        $this->announceMembershipChanges();

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
}
