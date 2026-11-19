<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo técnico global: não contém dados de negócio nem tenant_id.
        Schema::create('vehicle_catalog_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('vehicle_type', 15);
            $table->string('status', 20);
            $table->boolean('with_prices')->default(false);
            $table->unsignedInteger('brands_count')->default(0);
            $table->unsignedInteger('models_count')->default(0);
            $table->unsignedInteger('years_count')->default(0);
            $table->unsignedInteger('prices_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['provider', 'vehicle_type', 'status']);
        });

        Schema::create('vehicle_catalog_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('vehicle_type', 15);
            $table->string('external_code', 32);
            $table->string('name', 180);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'vehicle_type', 'external_code'], 'vehicle_catalog_brand_provider_code');
            $table->index(['provider', 'vehicle_type', 'name']);
        });

        Schema::create('vehicle_catalog_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('vehicle_catalog_brands')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_code', 32);
            $table->string('name', 180);
            $table->string('family', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'brand_id', 'external_code'], 'vehicle_catalog_model_provider_code');
            $table->index(['brand_id', 'family']);
        });

        Schema::create('vehicle_catalog_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('model_id')->constrained('vehicle_catalog_models')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('external_code', 32);
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('fuel_code', 8)->nullable();
            $table->string('fuel_name', 40)->nullable();
            $table->string('label', 180);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'model_id', 'external_code'], 'vehicle_catalog_year_provider_code');
            $table->index(['model_id', 'model_year']);
        });

        Schema::create('vehicle_catalog_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('year_id')->constrained('vehicle_catalog_years')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('fipe_code', 16);
            $table->unsignedBigInteger('price_cents');
            $table->string('reference_month', 60);
            $table->timestamp('queried_at')->useCurrent();
            $table->timestamps();
            $table->unique(['provider', 'year_id', 'reference_month'], 'vehicle_catalog_price_reference');
            $table->index(['provider', 'fipe_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_catalog_prices');
        Schema::dropIfExists('vehicle_catalog_years');
        Schema::dropIfExists('vehicle_catalog_models');
        Schema::dropIfExists('vehicle_catalog_brands');
        Schema::dropIfExists('vehicle_catalog_sync_runs');
    }
};
