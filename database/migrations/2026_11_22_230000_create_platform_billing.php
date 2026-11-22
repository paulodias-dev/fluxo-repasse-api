<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->unsignedBigInteger('monthly_cents');
            $t->unsignedInteger('vehicle_limit')->nullable();
            $t->unsignedInteger('photo_limit')->nullable();
            $t->boolean('available')->default(true);
            $t->boolean('default_free')->default(false);
        });
        DB::table('saas_plans')->insertGetId(['name' => 'Gratuito', 'monthly_cents' => 0, 'default_free' => true]);
        Schema::table('tenants', function (Blueprint $t) {
            $t->boolean('saas_managed')->default(true);
            $t->foreignId('saas_plan_id')->nullable()->constrained('saas_plans');
        });
        DB::table('tenants')->update(['saas_managed' => false]);
        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('first_observed_login')->nullable();
            $t->timestamp('last_observed_login')->nullable();
            $t->timestamp('last_activity')->nullable()->index();
            $t->string('last_login_method', 30)->nullable();
        });
        Schema::create('saas_gateways', function (Blueprint $t) {
            $t->string('environment')->primary();
            $t->text('token')->nullable();
            $t->text('secret')->nullable();
            $t->string('return_url')->nullable();
            $t->boolean('active')->default(false);
        });
        foreach (['test','production'] as $environment) {
            DB::table('saas_gateways')->insert(compact('environment'));
        }
        Schema::create('saas_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('plan_id')->constrained('saas_plans');
            $t->unsignedBigInteger('monthly_cents');
            $t->string('environment');
            $t->string('provider_id')->nullable();
            $t->unique(['environment','provider_id']);
            $t->string('status')->default('pending');
            $t->text('checkout_url')->nullable();
            $t->timestamp('paid_until')->nullable();
            $t->timestamps();
        });
        Schema::create('saas_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignUuid('subscription_id')->constrained('saas_subscriptions');
            $t->string('environment');
            $t->string('provider_id');
            $t->unique(['environment','provider_id']);
            $t->string('status');
            $t->unsignedBigInteger('amount_cents');
            $t->timestamp('paid_until')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_charges');
        Schema::dropIfExists('saas_subscriptions');
        Schema::dropIfExists('saas_gateways');
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['last_activity']);
            $t->dropColumn(['first_observed_login','last_observed_login','last_activity','last_login_method']);
        });
        Schema::table('tenants', fn (Blueprint $t) => $t->dropConstrainedForeignId('saas_plan_id'));
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('saas_managed'));
        Schema::dropIfExists('saas_plans');
    }
};
