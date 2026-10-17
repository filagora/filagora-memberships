<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The tables must match the site they were extracted from, so an existing site can adopt them as
 * they are.
 */

const MEMBERSHIPS_MIGRATION = '2026_10_17_100000_create_filagora_membership_tables';
const MEMBERSHIPS_TABLES = ['membership_plans', 'membership_plan_prices', 'user_memberships', 'billing_event_logs'];

function membershipsMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.MEMBERSHIPS_MIGRATION.'.php';
}

function dropMembershipTables(): void
{
    foreach (array_reverse(MEMBERSHIPS_TABLES) as $table) {
        Schema::dropIfExists($table);
    }
}

afterEach(function () {
    dropMembershipTables();
    config(['filagora.table_prefix' => 'filagora_']);

    if (! Schema::hasTable(Tables::name('membership_plans'))) {
        membershipsMigration()->up();
    }
});

it('ships a snapshot of the tables that matches the migration, column order included', function () {
    $snapshot = app(SchemaRegistry::class)->get('memberships');

    expect(array_keys($snapshot->tables))->toBe(MEMBERSHIPS_TABLES)
        ->and($snapshot->migrationNames())->toBe([MEMBERSHIPS_MIGRATION]);

    foreach (MEMBERSHIPS_TABLES as $table) {
        expect(TableShape::inspect($table)->differencesFrom($snapshot->tables[$table], compareColumnOrder: true))->toBe([], $table);
    }
});

it('keeps plan codes unique and a price per currency per plan', function () {
    $plans = TableShape::inspect('membership_plans');
    $prices = TableShape::inspect('membership_plan_prices');

    expect($plans->indexes['membership_plans_code_unique']['unique'])->toBeTrue()
        ->and($plans->columns['is_active'])->toMatchArray(['type' => 'tinyint(1)', 'nullable' => false, 'default' => '1'])
        ->and($plans->columns['is_free']['default'])->toBe('0')
        ->and($plans->columns['sort_order'])->toMatchArray(['type' => 'int unsigned', 'default' => '0'])
        ->and($prices->columns['amount']['type'])->toBe('decimal(10,2)')
        ->and($prices->columns['currency']['type'])->toBe('varchar(3)')
        ->and($prices->indexes['membership_plan_prices_membership_plan_id_currency_unique'])->toMatchArray(['columns' => ['membership_plan_id', 'currency'], 'unique' => true])
        ->and($prices->indexes['membership_plan_prices_provider_plan_id_index']['columns'])->toBe(['provider_plan_id']);
});

it('keeps a provider subscription id unique across memberships, with the lookups by person and by plan', function () {
    $shape = TableShape::inspect('user_memberships');

    expect($shape->columns['status'])->toMatchArray(['nullable' => false, 'default' => 'pending'])
        ->and($shape->columns['subscription_name']['default'])->toBe('default')
        ->and($shape->columns['provider_subscription_id']['nullable'])->toBeTrue()
        ->and($shape->indexes['user_memberships_provider_subscription_id_unique']['unique'])->toBeTrue()
        ->and($shape->indexes['user_memberships_user_id_status_index']['columns'])->toBe(['user_id', 'status'])
        ->and($shape->indexes['user_memberships_membership_plan_id_status_index']['columns'])->toBe(['membership_plan_id', 'status']);
});

it('keeps a billing event once per provider and event id', function () {
    $shape = TableShape::inspect('billing_event_logs');

    expect($shape->indexes['billing_event_logs_provider_event_id_unique'])->toMatchArray(['columns' => ['provider', 'event_id'], 'unique' => true])
        ->and($shape->indexes['billing_event_logs_provider_event_type_index']['columns'])->toBe(['provider', 'event_type'])
        ->and($shape->columns['processing_status']['default'])->toBe('pending')
        ->and($shape->columns['payload']['nullable'])->toBeFalse();
});

it('removes prices and memberships along with their plan', function () {
    foreach (['membership_plan_prices', 'user_memberships'] as $table) {
        $keys = TableShape::inspect($table)->foreignKeys;

        expect(collect($keys)->firstWhere('columns', ['membership_plan_id']))->toMatchArray(['on' => 'membership_plans', 'on_delete' => 'cascade']);
    }

    $user = collect(TableShape::inspect('user_memberships')->foreignKeys)->firstWhere('columns', ['user_id']);

    expect($user)->toMatchArray(['on' => '{users}', 'on_delete' => 'cascade']);
});

it('adopts the tables of an existing site and records the migration', function () {
    dropMembershipTables();
    config(['filagora.table_prefix' => '']);
    membershipsMigration()->up();
    DB::table('migrations')->where('migration', MEMBERSHIPS_MIGRATION)->delete();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('memberships'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([MEMBERSHIPS_MIGRATION]);

    $this->artisan('filagora:adopt', ['--package' => 'memberships'])->expectsOutputToContain('Recorded 1 migration(s) as run.')->assertExitCode(0);
});

it('refuses tables that lost the unique subscription id, and is already adopted on a site that ran the migration', function () {
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('memberships'))->status)->toBe(AdoptionStatus::AlreadyAdopted);

    dropMembershipTables();
    config(['filagora.table_prefix' => '']);
    membershipsMigration()->up();
    Schema::table('user_memberships', fn ($table) => $table->dropUnique('user_memberships_provider_subscription_id_unique'));

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('memberships'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('user_memberships: index user_memberships_provider_subscription_id_unique is missing');
});
