<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Propostas de obrigações INTERNAS, exclusivamente documentais.
        // Não há contas a pagar, contas bancárias, saldo, quitação ou ledger.
        Schema::create('repasse_obligation_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('repasse_commercial_terms')->restrictOnDelete();
            $table->foreignId('preparation_record_id')
                ->constrained('repasse_preparation_records')->restrictOnDelete();
            $table->foreignId('payer_party_id')->constrained('parties')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('intent_sha256', 64);
            $table->char('source_snapshot_sha256', 64);
            $table->char('snapshot_sha256', 64);
            // LONGTEXT conserva a representação exata da fotografia imutável.
            $table->longText('snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'term_id', 'id'], 'proposal_case_term_idx');
            $table->index(['preparation_record_id', 'id'], 'proposal_record_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_obligation_proposals');
    }
};
