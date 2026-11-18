<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('payer_party_id')->constrained('parties')->restrictOnDelete();
            $table->string('payer_name', 180);
            $table->uuid('request_key');
            $table->char('intent_sha256', 64);
            $table->unsignedBigInteger('base_amount_cents');
            $table->string('memo', 500)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'request_key'], 'settlement_tenant_request_unique');
            $table->index(['tenant_id', 'repasse_case_id', 'id'], 'settlement_vehicle_idx');
        });

        Schema::create('repasse_settlement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained('repasse_settlements')->restrictOnDelete();
            $table->foreignId('beneficiary_party_id')->constrained('parties')->restrictOnDelete();
            $table->string('beneficiary_name', 180);
            $table->string('kind', 25);
            $table->string('mode', 16);
            $table->unsignedInteger('basis_points')->nullable();
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'settlement_id', 'id'], 'settlement_line_scope_idx');
        });

        Schema::create('repasse_settlement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained('repasse_settlements')->restrictOnDelete();
            $table->foreignId('line_id')->constrained('repasse_settlement_lines')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('intent_sha256', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('external_reference', 120)->nullable();
            $table->string('proof_name', 180)->nullable();
            $table->string('proof_path', 300)->nullable();
            $table->string('proof_mime', 80)->nullable();
            $table->unsignedInteger('proof_size_bytes')->nullable();
            $table->char('proof_sha256', 64)->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reported_at')->useCurrent();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->unique(['tenant_id', 'request_key'], 'settlement_payment_tenant_request_unique');
            $table->index(['tenant_id', 'settlement_id', 'line_id', 'voided_at'], 'settlement_payment_line_idx');
        });

        Schema::create('repasse_settlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('settlement_id')->constrained('repasse_settlements')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('repasse_settlement_payments')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('event_type', 30);
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'settlement_id', 'id'], 'settlement_events_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_settlement_events');
        Schema::dropIfExists('repasse_settlement_payments');
        Schema::dropIfExists('repasse_settlement_lines');
        Schema::dropIfExists('repasse_settlements');
    }
};
