<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma tabela append-only: nenhuma obrigação, saldo ou transação bancária.
        Schema::create('repasse_preparation_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('repasse_commercial_terms')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('payload_digest', 64);
            $table->char('source_snapshot_sha256', 64);
            $table->char('snapshot_sha256', 64);
            $table->json('snapshot');
            $table->unsignedBigInteger('reference_cents');
            $table->unsignedBigInteger('additions_cents')->default(0);
            $table->unsignedBigInteger('deductions_cents')->default(0);
            $table->unsignedBigInteger('net_cents');
            $table->foreignId('replaces_record_id')->nullable()
                ->constrained('repasse_preparation_records')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'term_id', 'id'], 'prep_record_case_term_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_preparation_records');
    }
};
