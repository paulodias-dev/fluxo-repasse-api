<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes are additive and serve platform-only aggregate filters.
 * Schedule deployment for a low-traffic window on populated MySQL schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->index('created_at', 'platform_bi_tenant_created_idx');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->index('created_at', 'platform_bi_user_created_idx');
            $table->index(['tenant_id', 'last_activity'], 'platform_bi_user_activity_idx');
        });
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->index('created_at', 'platform_bi_vehicle_created_idx');
            $table->index('sold_on', 'platform_bi_vehicle_sold_idx');
        });
        Schema::table('saas_charges', function (Blueprint $table): void {
            $table->index(['environment', 'status', 'created_at'],
                'platform_bi_charge_period_idx');
        });
        Schema::table('saas_subscriptions', function (Blueprint $table): void {
            $table->index(['environment', 'status', 'paid_until', 'tenant_id'],
                'platform_bi_subscription_idx');
        });
    }

    public function down(): void
    {
        Schema::table('saas_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('platform_bi_subscription_idx');
        });
        Schema::table('saas_charges', function (Blueprint $table): void {
            $table->dropIndex('platform_bi_charge_period_idx');
        });
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropIndex('platform_bi_vehicle_sold_idx');
            $table->dropIndex('platform_bi_vehicle_created_idx');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('platform_bi_user_activity_idx');
            $table->dropIndex('platform_bi_user_created_idx');
        });
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex('platform_bi_tenant_created_idx');
        });
    }
};
