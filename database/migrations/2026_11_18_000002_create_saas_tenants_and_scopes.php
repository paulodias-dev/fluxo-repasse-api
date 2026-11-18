<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single MySQL database, mandatory tenant_id in ALL business tables.
 * Existing production DBs require a supervised, backed-up migration;
 * this migration is designed for a clean migrate:fresh --seed in development.
 */
return new class extends Migration
{
    public const BUSINESS_TABLES = [
        'parties', 'party_events', 'party_links',
        'repasse_cases', 'repasse_case_parties', 'repasse_case_events',
        'repasse_case_tasks', 'repasse_case_checklist', 'repasse_case_notes',
        'repasse_case_evidence', 'repasse_scenarios',
        'repasse_scenario_allocations', 'repasse_scenario_versions',
        'repasse_distribution_templates', 'repasse_distribution_lines',
        'repasse_distribution_versions', 'repasse_distribution_batches',
        'repasse_scenario_reviews', 'repasse_scenario_review_events',
        'repasse_case_packages', 'repasse_case_financial_entries',
        'repasse_financial_categories', 'repasse_case_financial_documents',
        'repasse_case_financial_events', 'repasse_commercial_terms',
        'repasse_commercial_term_versions', 'repasse_commercial_term_evidence',
        'repasse_commercial_reviews', 'repasse_commercial_review_events',
        'repasse_preparation_records', 'repasse_obligation_proposals',
        'repasse_external_transfer_reports',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            // Null is reserved for the technical platform administrator; it
            // may authenticate, but cannot access tenant business endpoints.
            $table->foreignId('tenant_id')->nullable()
                ->constrained('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'is_active']);
        });

        foreach (self::BUSINESS_TABLES as $tableName) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->index(['tenant_id', 'id']);
            });
        }

        Schema::table('repasse_financial_categories', static function (Blueprint $table): void {
            $table->dropUnique('repasse_fin_category_kind_slug_unique');
            $table->unique(['tenant_id', 'kind', 'slug'], 'fin_category_tenant_kind_slug');
        });
    }

    public function down(): void
    {
        Schema::table('repasse_financial_categories', static function (Blueprint $table): void {
            $table->dropUnique('fin_category_tenant_kind_slug');
            $table->unique(['kind', 'slug'], 'repasse_fin_category_kind_slug_unique');
        });

        foreach (array_reverse(self::BUSINESS_TABLES) as $tableName) {
            Schema::table($tableName, static function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        Schema::table('users', static function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('tenants');
    }
};
