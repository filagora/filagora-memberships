<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Plans, their prices per currency, who holds which plan, and the log of billing-provider events,
 * in the shape of the forum the package was extracted from. A paid plan's price lives on its price
 * rows (one per currency), each with the provider's own identifier; `provider_product_id` on the
 * plan is the provider's catalogue product shared by those prices. A person's provider
 * subscription id is unique across providers, and a plan or price can only be removed along with
 * what hangs off it. The longer index name is written out so that it is the same with and without
 * a table prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('membership_plans'), function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_free')->default(false);
            $table->string('billing_interval')->nullable();
            $table->string('provider')->nullable();
            $table->string('provider_product_id')->nullable();
            $table->json('entitlements')->nullable();
            $table->json('feature_list')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create(Tables::name('membership_plan_prices'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_plan_id')->constrained(Tables::name('membership_plans'))->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 10, 2);
            $table->string('provider_plan_id')->nullable()->index();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['membership_plan_id', 'currency'], 'membership_plan_prices_membership_plan_id_currency_unique');
        });

        Schema::create(Tables::name('user_memberships'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id')->constrained(UserModel::table())->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained(Tables::name('membership_plans'))->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('provider')->nullable();
            $table->string('subscription_name')->default('default');
            $table->string('provider_subscription_id')->nullable()->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->json('granted_entitlements_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['membership_plan_id', 'status']);
        });

        Schema::create(Tables::name('billing_event_logs'), function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type');
            $table->string('processing_status')->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
            $table->index(['provider', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('billing_event_logs'));
        Schema::dropIfExists(Tables::name('user_memberships'));
        Schema::dropIfExists(Tables::name('membership_plan_prices'));
        Schema::dropIfExists(Tables::name('membership_plans'));
    }
};
