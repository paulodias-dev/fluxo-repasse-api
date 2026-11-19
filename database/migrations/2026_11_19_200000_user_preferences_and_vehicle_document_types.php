<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('preferences')->nullable();
        });

        Schema::table('repasse_case_evidence', function (Blueprint $table): void {
            $table->string('document_type', 40)->nullable();
            $table->string('document_label', 120)->nullable();
            $table->index(['repasse_case_id', 'document_type'], 'case_evidence_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_case_evidence', function (Blueprint $table): void {
            $table->dropIndex('case_evidence_type_idx');
            $table->dropColumn(['document_type', 'document_label']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
