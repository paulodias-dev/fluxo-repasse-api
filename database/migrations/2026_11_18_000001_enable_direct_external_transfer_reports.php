<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reutiliza a tabela documental da Macro 48. Proposta/minuta são
        // opcionais APENAS para relatos externos diretos, nunca para dívida.
        Schema::table('repasse_external_transfer_reports', function (Blueprint $table): void {
            $table->unsignedBigInteger('term_id')->nullable()->change();
            $table->unsignedBigInteger('proposal_id')->nullable()->change();
            $table->char('proposal_sha256', 64)->nullable()->change();
            $table->string('payer_name', 180)->nullable();
            $table->string('beneficiary_name', 180)->nullable();
            $table->index(['repasse_case_id', 'proposal_id', 'id'], 'ext_transfer_case_origin_idx');
        });
    }

    public function down(): void
    {
        // Reverter a nulabilidade com registros diretos existentes apagaria
        // histórico. Requer migração de dados explícita antes do rollback.
        if (\Illuminate\Support\Facades\DB::table('repasse_external_transfer_reports')
            ->whereNull('proposal_id')->exists()) {
            throw new \RuntimeException(
                'Existem relatos diretos; não é seguro reverter nulabilidade sem migração de dados.'
            );
        }
        Schema::table('repasse_external_transfer_reports', function (Blueprint $table): void {
            $table->dropIndex('ext_transfer_case_origin_idx');
            $table->dropColumn(['payer_name', 'beneficiary_name']);
            $table->unsignedBigInteger('term_id')->nullable(false)->change();
            $table->unsignedBigInteger('proposal_id')->nullable(false)->change();
            $table->char('proposal_sha256', 64)->nullable(false)->change();
        });
    }
};
