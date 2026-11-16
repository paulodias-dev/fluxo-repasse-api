<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repasse_financial_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'slug'], 'repasse_fin_category_kind_slug_unique');
            $table->index(['kind', 'is_active']);
        });

        Schema::table('repasse_case_financial_entries', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()
                ->constrained('repasse_financial_categories')->nullOnDelete();
            $table->foreignId('replaces_entry_id')->nullable()
                ->constrained('repasse_case_financial_entries')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->index(['repasse_case_id', 'kind', 'status', 'effective_on'], 'fin_case_filters_idx');
            $table->index(['repasse_case_id', 'category_id'], 'fin_case_category_idx');
        });

        Schema::create('repasse_case_financial_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('repasse_case_financial_entries')->restrictOnDelete();
            $table->foreignId('evidence_id')->unique()
                ->constrained('repasse_case_evidence')->restrictOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'entry_id'], 'fin_documents_entry_idx');
        });

        Schema::create('repasse_case_financial_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repasse_case_id')->constrained('repasse_cases')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('repasse_case_financial_entries')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['repasse_case_id', 'entry_id', 'id'], 'fin_events_entry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repasse_case_financial_events');
        Schema::dropIfExists('repasse_case_financial_documents');
        Schema::table('repasse_case_financial_entries', function (Blueprint $table): void {
            $table->dropIndex('fin_case_category_idx');
            $table->dropIndex('fin_case_filters_idx');
            $table->dropForeign(['replaces_entry_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'replaces_entry_id', 'void_reason']);
        });
        Schema::dropIfExists('repasse_financial_categories');
    }
};
