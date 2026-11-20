<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repasse_case_evidence', function (Blueprint $table): void {
            $table->boolean('is_cover')->default(false)->after('document_label');
            $table->index(['repasse_case_id', 'is_cover', 'archived_at'], 'case_evidence_cover_idx');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_case_evidence', function (Blueprint $table): void {
            $table->dropIndex('case_evidence_cover_idx');
            $table->dropColumn('is_cover');
        });
    }
};
