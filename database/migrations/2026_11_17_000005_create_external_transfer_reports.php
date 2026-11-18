<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registro declaratório de transferências feitas FORA da plataforma.
        // Não é obrigação legal, liquidação certificada, autorização ou ledger bancário.
        Schema::create('repasse_external_transfer_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('repasse_commercial_terms')->restrictOnDelete();
            $table->foreignId('proposal_id')->constrained('repasse_obligation_proposals')->restrictOnDelete();
            $table->foreignId('payer_party_id')->constrained('parties')->restrictOnDelete();
            $table->foreignId('beneficiary_party_id')->constrained('parties')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('intent_sha256', 64);
            $table->char('proposal_sha256', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->date('transferred_on');
            $table->string('external_reference', 120)->nullable();
            $table->string('operator_note', 500)->nullable();
            $table->string('proof_name', 180);
            $table->string('proof_path', 300);
            $table->string('proof_mime', 80);
            $table->unsignedInteger('proof_size_bytes');
            $table->char('proof_sha256', 64);
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reported_at')->useCurrent();
            // Correções são estorno explícito; valor/data/comprovante originais nunca mudam.
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->index(['proposal_id', 'id'], 'ext_transfer_proposal_idx');
            $table->index(['repasse_case_id', 'beneficiary_party_id', 'id'], 'ext_transfer_case_beneficiary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_external_transfer_reports');
    }
};
