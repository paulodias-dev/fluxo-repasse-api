<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plano único ativo por veículo, validado sob lock da ficha. Snapshot
        // imutável após aprovação; nenhuma transferência bancária é executada.
        Schema::create('repasse_payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'repasse_case_id', 'status', 'id'], 'settlement_plan_case_idx');
        });
        Schema::create('repasse_plan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('repasse_payment_plans')->restrictOnDelete();
            $table->foreignId('beneficiary_party_id')->constrained('parties')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('intent_sha256', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('external_reference', 120)->nullable();
            $table->string('proof_name', 180)->nullable();
            $table->string('proof_path', 300)->nullable();
            $table->string('proof_mime', 80)->nullable();
            $table->unsignedInteger('proof_size_bytes')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->index(['tenant_id', 'plan_id', 'beneficiary_party_id', 'voided_at'], 'settlement_payment_line_idx');
        });
        Schema::create('repasse_plan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('repasse_payment_plans')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()
                ->constrained('repasse_plan_payments')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'plan_id', 'id'], 'settlement_events_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_plan_events');
        Schema::dropIfExists('repasse_plan_payments');
        Schema::dropIfExists('repasse_payment_plans');
    }
};
