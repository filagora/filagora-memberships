<?php

declare(strict_types=1);

namespace Filagora\Memberships\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filagora\Core\CoreServiceProvider;
use Filagora\Entitlements\EntitlementsServiceProvider;
use Filagora\Forums\ForumsServiceProvider;
use Filagora\Memberships\MembershipsServiceProvider;
use Filagora\Memberships\Tests\Support\TestPanelProvider;
use Filagora\Memberships\Tests\Support\TestSupportServiceProvider;
use Filagora\Memberships\Tests\Support\TestUser;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            CoreServiceProvider::class,
            EntitlementsServiceProvider::class,
            ForumsServiceProvider::class,
            MembershipsServiceProvider::class,
            TestPanelProvider::class,
            TestSupportServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filagora.user_model', TestUser::class);
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
