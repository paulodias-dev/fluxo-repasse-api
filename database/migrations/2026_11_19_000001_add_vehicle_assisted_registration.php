<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->string('vehicle_type', 15)->nullable();
            $table->string('vehicle_version', 180)->nullable();
            $table->string('fipe_code', 10)->nullable();
            $table->string('fipe_reference_month', 60)->nullable();
            $table->unsignedBigInteger('fipe_price_cents')->nullable();
            $table->string('fipe_source', 32)->nullable();
            $table->timestamp('fipe_queried_at')->nullable();
        });
        Schema::create('vehicle_create_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('payload_sha256', 64);
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['tenant_id', 'request_key'], 'vehicle_create_idem_tenant_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_create_requests');
        Schema::table('repasse_cases', function (Blueprint $table): void {
            $table->dropColumn(['vehicle_type', 'vehicle_version', 'fipe_code',
                'fipe_reference_month', 'fipe_price_cents', 'fipe_source', 'fipe_queried_at']);
        });
    }
};
